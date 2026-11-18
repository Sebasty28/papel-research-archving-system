"""Measure local repository LCP, skeleton timing, and layout shifts.

Run with Python and websocket-client installed:
    python scripts/measure_repository_lcp.py --runs 3
"""

import argparse
import json
import os
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

import websocket


PROBE = r"""(() => {
  const p = window.__papelPerf = {events: [], lcp: [], shifts: []};
  const at = () => Math.round(performance.now());
  const rect = selector => {
    const el = document.querySelector(selector);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return [r.x, r.y, r.width, r.height].map(Math.round);
  };
  const snap = name => p.events.push({name, t: at(),
    hero: rect('#heroBanner'), search: rect('.search-band'),
    main: rect('#mainCol'), heading: rect('.section-heading')});
  new PerformanceObserver(list => list.getEntries().forEach(e =>
    p.lcp.push({t: Math.round(e.startTime), cls: e.element?.className || '',
      url: e.url || ''}))).observe({type: 'largest-contentful-paint', buffered: true});
  new PerformanceObserver(list => list.getEntries().forEach(e => {
    if (!e.hadRecentInput) p.shifts.push({t: Math.round(e.startTime), value: e.value,
      sources: (e.sources || []).map(s => ({node: s.node?.className || s.node?.id || '',
        previous: [s.previousRect.x, s.previousRect.y].map(Math.round),
        current: [s.currentRect.x, s.currentRect.y].map(Math.round)}))});
  })).observe({type: 'layout-shift', buffered: true});
  document.addEventListener('DOMContentLoaded', () => {
    snap('dom-ready');
    new MutationObserver(() => {
      const classes = document.documentElement.classList;
      if (classes.contains('papel-skel-out')) snap('skeleton-fade');
      else if (!classes.contains('papel-skel')) snap('skeleton-gone');
    }).observe(document.documentElement, {attributes: true, attributeFilter: ['class']});
  });
  window.addEventListener('load', () => snap('load'));
  document.addEventListener('load', e => {
    if (e.target instanceof HTMLImageElement && e.target.matches('.hero-slide.is-active'))
      snap('hero-loaded');
  }, true);
})();"""


def chrome_path():
    for path in (r'C:\Program Files\Google\Chrome\Application\chrome.exe',
                 r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'):
        if Path(path).exists():
            return path
    raise RuntimeError('Chrome or Edge was not found')


def measure(url, screenshot_prefix, run, width, height, dpr):
    with tempfile.TemporaryDirectory(prefix='papel-lcp-') as profile:
        flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
        proc = subprocess.Popen([chrome_path(), '--headless=new', '--disable-gpu',
            '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0',
            '--remote-allow-origins=*', '--user-data-dir=' + profile, 'about:blank'],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=flags)
        try:
            port_file = Path(profile) / 'DevToolsActivePort'
            port = None
            for _ in range(100):
                try:
                    port = port_file.read_text().splitlines()[0]
                    break
                except (FileNotFoundError, PermissionError, IndexError):
                    time.sleep(.1)
            if port is None:
                raise RuntimeError('Browser DevTools port was not ready')
            req = urllib.request.Request(f'http://127.0.0.1:{port}/json/new?about:blank', method='PUT')
            target = json.load(urllib.request.urlopen(req))
            ws = websocket.create_connection(target['webSocketDebuggerUrl'], timeout=20)
            next_id = 0

            def call(method, params=None):
                nonlocal next_id
                next_id += 1
                call_id = next_id
                ws.send(json.dumps({'id': call_id, 'method': method, 'params': params or {}}))
                while True:
                    result = json.loads(ws.recv())
                    if result.get('id') == call_id:
                        if 'error' in result:
                            raise RuntimeError(result['error'])
                        return result.get('result', {})

            call('Page.enable')
            call('Network.enable')
            call('Network.setCacheDisabled', {'cacheDisabled': True})
            call('Network.emulateNetworkConditions', {'offline': False, 'latency': 40,
                 'downloadThroughput': 5 * 1024 * 1024, 'uploadThroughput': 5 * 1024 * 1024})
            call('Emulation.setCPUThrottlingRate', {'rate': 2})
            call('Emulation.setDeviceMetricsOverride', {'width': width, 'height': height,
                 'deviceScaleFactor': dpr, 'mobile': width <= 900})
            call('Page.addScriptToEvaluateOnNewDocument', {'source': PROBE})
            call('Page.navigate', {'url': url})
            time.sleep(1.3)
            if screenshot_prefix:
                import base64
                shot = call('Page.captureScreenshot', {'format': 'png'})['data']
                Path(f'{screenshot_prefix}-{run}-before.png').write_bytes(base64.b64decode(shot))
            time.sleep(3.0)
            if screenshot_prefix:
                shot = call('Page.captureScreenshot', {'format': 'png'})['data']
                Path(f'{screenshot_prefix}-{run}-after.png').write_bytes(base64.b64decode(shot))
            expression = """JSON.stringify({probe: window.__papelPerf,
              resources: performance.getEntriesByType('resource')
                .filter(e => e.name.includes('navbar-photo'))
                .map(e => ({name: e.name.split('/').pop(), end: Math.round(e.responseEnd)})),
              heroVisible: getComputedStyle(document.querySelector('.hero img')).visibility})"""
            value = call('Runtime.evaluate', {'expression': expression, 'returnByValue': True})
            ws.close()
            if 'value' not in value['result']:
                raise RuntimeError(value['result'].get('exceptionDetails', value['result']))
            return json.loads(value['result']['value'])
        finally:
            proc.terminate()
            proc.wait(timeout=10)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--url', default='http://localhost/capstone/archive/')
    parser.add_argument('--runs', type=int, default=3)
    parser.add_argument('--screenshot-prefix')
    parser.add_argument('--width', type=int, default=1536)
    parser.add_argument('--height', type=int, default=900)
    parser.add_argument('--dpr', type=float, default=1)
    args = parser.parse_args()
    for i in range(1, args.runs + 1):
        result = measure(args.url, args.screenshot_prefix, i, args.width, args.height, args.dpr)
        print(json.dumps({'run': i, **result}, ensure_ascii=False))
