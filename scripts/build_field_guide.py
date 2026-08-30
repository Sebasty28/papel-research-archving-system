# -*- coding: utf-8 -*-
"""Rebuild the architecture field guide from the code as it stands today.

    python scripts/build_field_guide.py

Writes docs/field_guide.html. Everything countable in that page - how many
files there are, how long each one is, which files pull in which - is measured
here on every run, so the guide cannot quietly go out of date. The prose lives
in field_guide_content.py, and the code samples are lifted out of the real
files at build time rather than pasted, so an edit to the code changes the
guide.

It also checks itself and says so:

  * a file the guide talks about that no longer exists is reported, loudly,
    and the build exits non-zero - so a rename cannot leave the guide lying;
  * a code sample whose anchor line has gone is reported the same way;
  * anything added to the project that the guide has never mentioned is listed
    as "not covered", so new work gets written up rather than forgotten;
  * what changed since the last build is printed, from a snapshot kept beside
    the page.

Run it after any change worth explaining. To have it run itself, see
scripts/field_guide_hook.md.
"""
import io
import json
import os
import re
import sys
import datetime

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT_DIR = os.path.join(ROOT, 'docs')
OUT_HTML = os.path.join(OUT_DIR, 'field_guide.html')
SNAPSHOT = os.path.join(OUT_DIR, 'field_guide_snapshot.json')

sys.path.insert(0, HERE)
import field_guide_content as C

SKIP_DIRS = {'vendor', 'node_modules', '.git', 'uploads', 'backups', 'mailer',
             '__pycache__', 'docs'}
CODE_EXT = ('.php', '.py')

TABLE_NAMES = [t for t, _ in C.DB_NOTES]


# ===================================================== reading the project
def project_files():
    out = []
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs
                   if d not in SKIP_DIRS and not d.startswith('data_rescue')]
        for f in files:
            if f.endswith(CODE_EXT):
                rel = os.path.relpath(os.path.join(base, f), ROOT)
                out.append(rel.replace('\\', '/'))
    return sorted(out)


def read(rel):
    try:
        return io.open(os.path.join(ROOT, rel), encoding='utf-8',
                       errors='replace').read()
    except OSError:
        return ''


INCLUDE_RE = re.compile(
    r"(?:require|include)(?:_once)?\s*[( ]?\s*"
    r"(?:(ROOT_PATH|__DIR__)\s*\.\s*)?['\"]([^'\"]+)['\"]")


def includes_of(src, rel):
    """Resolve all three spellings of an include to one project path."""
    found = set()
    here = os.path.dirname(rel)
    for m in INCLUDE_RE.finditer(src):
        anchor, target = m.group(1), m.group(2)
        target = target.replace('\\', '/')
        if anchor == '__DIR__' or target.startswith(('..', './')):
            target = os.path.normpath(
                os.path.join(here, target.lstrip('/'))).replace('\\', '/')
        else:
            target = target.lstrip('/')
        if target.endswith('.php'):
            found.add(target)
    return sorted(found)


def kind_of(rel):
    if rel.startswith('tests/'):
        return 'test'
    if rel.startswith(('config/',)):
        return 'engine'
    if rel.startswith('includes/'):
        return 'shared'
    if rel.startswith(('app/models/', 'app/helpers/')):
        return 'model'
    if rel.startswith(('scripts/', 'database/', 'notifications/cron/')):
        return 'script'
    return 'page'


def roles_of(src):
    m = re.search(r'require_role\(\s*\[([^\]]*)\]', src)
    if m:
        return [r.strip().strip("'\"") for r in m.group(1).split(',') if r.strip()]
    return ['any signed-in'] if 'require_login()' in src else []


def scan():
    files = {}
    for rel in project_files():
        src = read(rel)
        files[rel] = {
            'lines': src.count('\n') + 1,
            'kind': kind_of(rel),
            'includes': includes_of(src, rel) if rel.endswith('.php') else [],
            'roles': roles_of(src) if rel.endswith('.php') else [],
        }
    for rel in files:
        files[rel]['used_by'] = sum(1 for r in files
                                    if rel in files[r]['includes'])
    return files


# =========================================================== code samples
def lift(sample, files):
    """Pull a sample's current text out of the file it names."""
    rel = sample['file']
    if rel not in files:
        return None, 'file is gone'
    src = read(rel)
    lines = src.split('\n')
    hit = None
    for i, line in enumerate(lines):
        if sample['anchor'] in line:
            hit = i
            break
    if hit is None:
        return None, 'anchor not found: %s' % sample['anchor'][:60]
    start = max(0, hit - sample.get('before', 0))
    end = min(len(lines), start + sample.get('lines', 6))
    body = lines[start:end]
    # Trim the shared indentation so the sample is readable on its own.
    pad = [len(l) - len(l.lstrip()) for l in body if l.strip()]
    cut = min(pad) if pad else 0
    body = [l[cut:] if l.strip() else '' for l in body]
    return {'first_line': start + 1, 'text': body}, None


# =============================================================== checking
def audit(files):
    """Every claim the prose makes, checked against the project."""
    problems, mentioned = [], set()

    for mod in C.MODULES:
        for feat in mod['features']:
            for path, _ in feat['files']:
                mentioned.add(path)
                if path not in files:
                    problems.append('module %s names a file that no longer '
                                    'exists: %s' % (mod['num'], path))
    for s in C.CODE:
        mentioned.add(s['file'])
        if s['file'] not in files:
            problems.append('code sample "%s" points at a missing file: %s'
                            % (s['title'], s['file']))
    for path in C.DEAD_NOTES:
        mentioned.add(path)
        if path not in files:
            problems.append('listed as dead but no longer present: %s' % path)
        elif files[path]['used_by'] > 0:
            problems.append('listed as dead but %d file(s) now use it: %s'
                            % (files[path]['used_by'], path))

    # Anything real that nothing in the guide mentions, worth writing up.
    uncovered = sorted(r for r in files
                       if r not in mentioned
                       and files[r]['kind'] in ('page', 'shared', 'model',
                                                'engine'))
    return problems, uncovered


def drift(files):
    """What has changed since the last build."""
    if not os.path.isfile(SNAPSHOT):
        return None
    old = json.loads(io.open(SNAPSHOT, encoding='utf-8').read())
    old_files = old.get('files', {})
    added = sorted(set(files) - set(old_files))
    gone = sorted(set(old_files) - set(files))
    changed = sorted(r for r in set(files) & set(old_files)
                     if files[r]['lines'] != old_files[r]['lines'])
    return {'since': old.get('built'), 'added': added, 'removed': gone,
            'changed': changed}


# ================================================================ writing
def esc(s):
    return (str(s).replace('&', '&amp;').replace('<', '&lt;')
            .replace('>', '&gt;').replace('"', '&quot;'))


def file_chip(path, files):
    kind = files.get(path, {}).get('kind', 'page')
    return '<span class="file %s">%s</span>' % (kind, esc(path))


def render_modules(files):
    out = []
    for mod in C.MODULES:
        feats = []
        for feat in mod['features']:
            items = []
            for path, why in feat['files']:
                meta = files.get(path)
                size = ('<span class="meta">%s lines%s</span>'
                        % ('{:,}'.format(meta['lines']),
                           ', used by %d' % meta['used_by']
                           if meta['used_by'] else '')) if meta else ''
                items.append(
                    '<li>%s<span class="why">%s %s</span></li>'
                    % (file_chip(path, files), esc(why), size))
            feats.append(
                '<div class="feat"><h4>%s</h4>%s<ul class="files">%s</ul></div>'
                % (esc(feat['title']),
                   '<p>%s</p>' % esc(feat['blurb']) if feat['blurb'] else '',
                   ''.join(items)))
        out.append(
            '<article class="mod" id="%s"><header><span class="num">MODULE %d'
            '</span><h3>%s</h3>%s</header>%s<div class="wires"><b>wiring</b>%s'
            '</div></article>'
            % (mod['id'], mod['num'], esc(mod['title']),
               '<p>%s</p>' % esc(mod['blurb']) if mod['blurb'] else '',
               ''.join(feats), esc(mod['wires'])))
    return '\n'.join(out)


def render_code(files, problems):
    out = []
    for s in C.CODE:
        lifted, err = lift(s, files)
        if err:
            problems.append('code sample "%s": %s' % (s['title'], err))
            continue
        numbered = []
        for i, line in enumerate(lifted['text']):
            numbered.append(
                '<span class="ln">%d</span>%s'
                % (lifted['first_line'] + i, esc(line) or '&nbsp;'))
        notes = ''.join(
            '<li><code>%s</code><span>%s</span></li>' % (esc(frag), esc(text))
            for frag, text in s['notes'])
        out.append(
            '<article class="tour" id="%s">'
            '<h3>%s</h3><p class="tour-intro">%s</p>'
            '<p class="src">%s <span class="meta">line %d</span></p>'
            '<pre class="code"><code>%s</code></pre>'
            '<ol class="notes">%s</ol>'
            '<p class="takeaway"><b>What to remember</b> %s</p></article>'
            % (s['id'], esc(s['title']), esc(s['intro']),
               file_chip(s['file'], files), lifted['first_line'],
               '\n'.join(numbered), notes, esc(s['takeaway'])))
    return '\n'.join(out)


def render_dead(files):
    rows = []
    for path, why in C.DEAD_NOTES.items():
        if path not in files:
            continue
        rows.append('<li>%s<span class="why">%s <span class="meta">%s lines'
                    '</span></span></li>'
                    % (file_chip(path, files), esc(why),
                       '{:,}'.format(files[path]['lines'])))
    return ''.join(rows)


def build():
    files = scan()
    problems, uncovered = audit(files)
    changes = drift(files)

    php = {r: d for r, d in files.items() if r.endswith('.php')}
    counts = {}
    for d in php.values():
        counts[d['kind']] = counts.get(d['kind'], 0) + 1
    stats = {
        'php': len(php),
        'modules': len(C.MODULES),
        'pages': counts.get('page', 0),
        'shared': counts.get('shared', 0),
        'tables': len(C.DB_NOTES),
        'tests': sum(1 for r in files if r.startswith('tests/')),
        'tours': len(C.CODE),
        'core_used': files.get('config/core.php', {}).get('used_by', 0),
    }

    body_code = render_code(files, problems)
    html = TEMPLATE
    for key, val in [
        ('__DATE__', datetime.date.today().strftime('%d %B %Y')),
        ('__MODULE_CARDS__', render_modules(files)),
        ('__CODE__', body_code),
        ('__DEAD__', render_dead(files)),
        ('__DB__', ''.join('<tr><td>%s</td><td>%s</td></tr>'
                           % (esc(t), esc(d)) for t, d in C.DB_NOTES)),
        ('__GLOSSARY__', ''.join(
            '<div class="gl"><h4>%s</h4><p>%s</p></div>' % (t, d)
            for t, d in C.GLOSSARY)),
        ('__TOC_MODULES__', ''.join(
            '<li><a href="#%s">%d &middot; %s</a></li>'
            % (m['id'], m['num'], esc(m['title'].split('—')[0].strip()))
            for m in C.MODULES)),
        ('__TOC_CODE__', ''.join(
            '<li><a href="#%s">%s</a></li>' % (s['id'], esc(s['title']))
            for s in C.CODE)),
    ]:
        html = html.replace(key, val)
    for key, val in stats.items():
        html = html.replace('__%s__' % key.upper(), '{:,}'.format(val))

    if not os.path.isdir(OUT_DIR):
        os.makedirs(OUT_DIR)
    io.open(OUT_HTML, 'w', encoding='utf-8').write(html)
    io.open(SNAPSHOT, 'w', encoding='utf-8').write(json.dumps(
        {'built': datetime.datetime.now().strftime('%Y-%m-%d %H:%M'),
         'files': {r: {'lines': d['lines']} for r, d in files.items()}},
        indent=1))

    # ------------------------------------------------------------- report
    print('  built %s' % os.path.relpath(OUT_HTML, ROOT).replace('\\', '/'))
    print('  %d PHP files, %d pages, %d shared, %d modules, %d code tours'
          % (stats['php'], stats['pages'], stats['shared'],
             stats['modules'], stats['tours']))

    if changes:
        n = len(changes['added']) + len(changes['removed']) + len(changes['changed'])
        print('\n  since the last build (%s): %d file(s) differ'
              % (changes['since'], n))
        for label, items in (('new', changes['added']),
                             ('deleted', changes['removed']),
                             ('edited', changes['changed'])):
            for f in items[:8]:
                print('    %-8s %s' % (label, f))
            if len(items) > 8:
                print('    %-8s ... and %d more' % (label, len(items) - 8))

    if uncovered:
        print('\n  not covered by the guide (%d) - worth writing up:'
              % len(uncovered))
        for f in uncovered[:12]:
            print('    %s' % f)
        if len(uncovered) > 12:
            print('    ... and %d more' % (len(uncovered) - 12))

    if problems:
        print('\n  PROBLEMS - the guide would be wrong (%d):' % len(problems))
        for p in problems:
            print('    %s' % p)
        return 1
    print('\n  every file and code sample the guide names was found.')
    return 0


TEMPLATE = r"""<title>PAPEL Field Guide</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700&family=JetBrains+Mono:wght@400;500;700&family=Source+Sans+3:ital,wght@0,400;0,600;1,400&display=swap">
<style>
:root{
  --paper:#F8F6F5;--surface:#FFFFFF;--sunk:#F0ECEA;--raise:#FBF9F8;
  --ink:#1C1517;--ink-2:#594F51;--ink-3:#8B7E80;
  --rule:#E4DCDA;--rule-2:#CEC3C0;
  --accent:#8C1D2C;--accent-soft:#F7EAEB;
  --k-page:#8C1D2C;--k-shared:#1B5E6E;--k-engine:#875711;
  --k-model:#57467A;--k-test:#256242;--k-script:#6B5B4A;
  --code-bg:#FBF8F7;
  --shadow:0 1px 2px rgba(28,21,23,.05),0 12px 28px -20px rgba(28,21,23,.4);
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --paper:#171214;--surface:#20191B;--sunk:#2A2225;--raise:#251E20;
  --ink:#F1E9E7;--ink-2:#C2B6B4;--ink-3:#8F8281;
  --rule:#362C2E;--rule-2:#493B3E;
  --accent:#E78D97;--accent-soft:#36191E;
  --k-page:#E78D97;--k-shared:#6FB6C6;--k-engine:#D9A85C;
  --k-model:#AC9AD4;--k-test:#71C59A;--k-script:#B7A48D;
  --code-bg:#1B1517;
  --shadow:0 1px 2px rgba(0,0,0,.5),0 14px 30px -22px rgba(0,0,0,.9);
}}
:root[data-theme="dark"]{
  --paper:#171214;--surface:#20191B;--sunk:#2A2225;--raise:#251E20;
  --ink:#F1E9E7;--ink-2:#C2B6B4;--ink-3:#8F8281;
  --rule:#362C2E;--rule-2:#493B3E;
  --accent:#E78D97;--accent-soft:#36191E;
  --k-page:#E78D97;--k-shared:#6FB6C6;--k-engine:#D9A85C;
  --k-model:#AC9AD4;--k-test:#71C59A;--k-script:#B7A48D;
  --code-bg:#1B1517;
  --shadow:0 1px 2px rgba(0,0,0,.5),0 14px 30px -22px rgba(0,0,0,.9);
}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font-family:"Source Sans 3",system-ui,-apple-system,sans-serif;
  font-size:16px;line-height:1.62;-webkit-font-smoothing:antialiased}
.wrap{max-width:1180px;margin:0 auto;padding:0 clamp(1rem,4vw,3rem)}
h1,h2,h3,h4{font-family:"Bricolage Grotesque",system-ui,sans-serif;
  text-wrap:balance;margin:0}
h1{font-size:clamp(2.3rem,5vw,3.4rem);font-weight:700;line-height:1.02;letter-spacing:-.02em}
h2{font-size:clamp(1.5rem,3vw,2.05rem);font-weight:600;letter-spacing:-.015em;line-height:1.15}
h3{font-size:1.14rem;font-weight:600}
h4{font-size:.95rem;font-weight:600}
p{margin:0 0 1rem}
a{color:var(--accent)}
.eyebrow{font-family:"JetBrains Mono",monospace;font-size:.7rem;letter-spacing:.16em;
  text-transform:uppercase;color:var(--ink-3);margin:0 0 .8rem}
.lede{color:var(--ink-2);max-width:66ch}
.big-lede{font-size:1.12rem;color:var(--ink-2);max-width:64ch}
.mast{border-bottom:1px solid var(--rule);background:var(--surface)}
.mast .wrap{padding-top:clamp(2.5rem,6vw,4.5rem);padding-bottom:2.2rem}
.mast h1 em{font-style:normal;color:var(--accent)}
.stats{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1.6rem}
.stat{border:1px solid var(--rule);border-radius:2px;background:var(--raise);
  padding:.55rem .85rem;min-width:96px}
.stat b{display:block;font-family:"JetBrains Mono",monospace;font-size:1.3rem;
  font-weight:500;line-height:1.2;font-variant-numeric:tabular-nums}
.stat span{font-size:.7rem;letter-spacing:.08em;text-transform:uppercase;color:var(--ink-3)}
.built{margin-top:1rem;font-size:.85rem;color:var(--ink-3)}
.shell{display:grid;grid-template-columns:212px minmax(0,1fr);
  gap:clamp(1.5rem,4vw,3.5rem);padding-top:2.5rem;align-items:start}
nav.toc{position:sticky;top:1.2rem;font-size:.86rem}
nav.toc h4{color:var(--ink-3);font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;
  margin:0 0 .6rem;font-family:"JetBrains Mono",monospace;font-weight:500}
nav.toc ol{list-style:none;margin:0 0 1.4rem;padding:0;display:flex;flex-direction:column;gap:.14rem}
nav.toc a{display:block;padding:.22rem .5rem;border-radius:2px;color:var(--ink-2);
  text-decoration:none;border-left:2px solid transparent}
nav.toc a:hover{background:var(--sunk);color:var(--ink);border-left-color:var(--accent)}
main{min-width:0}
section{margin:0 0 clamp(2.6rem,5vw,4rem);scroll-margin-top:1rem}
.note{border-left:3px solid var(--accent);background:var(--accent-soft);
  padding:.9rem 1.1rem;border-radius:0 2px 2px 0;margin:1.2rem 0;color:var(--ink-2)}
.note strong{color:var(--ink)}
code,.file{font-family:"JetBrains Mono",ui-monospace,monospace}
.file{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;
  padding:.16rem .45rem;border-radius:2px;background:var(--sunk);
  border:1px solid var(--rule);white-space:nowrap}
.file::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--k-page);flex:none}
.file.shared::before{background:var(--k-shared)}
.file.engine::before{background:var(--k-engine)}
.file.model::before{background:var(--k-model)}
.file.test::before{background:var(--k-test)}
.file.script::before{background:var(--k-script)}
.meta{font-family:"JetBrains Mono",monospace;font-size:.72rem;color:var(--ink-3);
  white-space:nowrap;font-variant-numeric:tabular-nums}
.legend{display:flex;flex-wrap:wrap;gap:.4rem 1.1rem;margin:1rem 0 0;
  font-size:.84rem;color:var(--ink-2)}
.legend span{display:inline-flex;align-items:center;gap:.4rem}
.legend i{width:8px;height:8px;border-radius:50%;display:inline-block}
figure{margin:1.6rem 0;background:var(--surface);border:1px solid var(--rule);
  border-radius:3px;padding:1.2rem;overflow-x:auto}
figure svg{max-width:100%;height:auto;display:block;margin:0 auto;color:var(--ink-2)}
figcaption{margin-top:.9rem;font-size:.86rem;color:var(--ink-3);text-align:center}
.mods{display:flex;flex-direction:column;gap:1rem}
.mod{background:var(--surface);border:1px solid var(--rule);border-radius:3px;
  box-shadow:var(--shadow);overflow:hidden}
.mod>header{display:flex;gap:.9rem;align-items:baseline;flex-wrap:wrap;
  padding:1rem 1.2rem;border-bottom:1px solid var(--rule);background:var(--raise)}
.mod .num{font-family:"JetBrains Mono",monospace;font-size:.72rem;color:var(--accent);
  letter-spacing:.1em;flex:none}
.mod>header p{margin:.15rem 0 0;flex-basis:100%;color:var(--ink-2);font-size:.94rem;max-width:72ch}
.feat{padding:1rem 1.2rem;border-bottom:1px dashed var(--rule)}
.feat:last-of-type{border-bottom:0}
.feat h4{margin:0 0 .3rem}
.feat>p{margin:0 0 .65rem;color:var(--ink-2);font-size:.93rem;max-width:74ch}
.files{display:flex;flex-direction:column;gap:.5rem;margin:0;padding:0;list-style:none}
.files li{display:grid;grid-template-columns:minmax(200px,auto) 1fr;
  gap:.2rem .9rem;align-items:start;font-size:.9rem}
.files .why{color:var(--ink-2)}
.wires{padding:.75rem 1.2rem;background:var(--sunk);font-size:.85rem;color:var(--ink-2);
  display:flex;gap:.5rem;flex-wrap:wrap;align-items:baseline}
.wires b{font-family:"JetBrains Mono",monospace;font-size:.68rem;letter-spacing:.1em;
  text-transform:uppercase;color:var(--ink-3);font-weight:500}
.tours{display:flex;flex-direction:column;gap:1.4rem}
.tour{background:var(--surface);border:1px solid var(--rule);border-radius:3px;
  padding:1.2rem;box-shadow:var(--shadow)}
.tour h3{margin:0 0 .35rem}
.tour-intro{color:var(--ink-2);max-width:74ch;margin:0 0 .8rem}
.src{margin:0 0 .5rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
pre.code{margin:0 0 .9rem;padding:.9rem 1rem;background:var(--code-bg);
  border:1px solid var(--rule);border-radius:2px;overflow-x:auto;
  font-family:"JetBrains Mono",monospace;font-size:.82rem;line-height:1.75;
  color:var(--ink);white-space:pre}
pre.code .ln{display:inline-block;width:2.6em;color:var(--ink-3);
  user-select:none;font-variant-numeric:tabular-nums}
.notes{margin:0 0 .9rem;padding:0 0 0 1.3rem;display:flex;flex-direction:column;gap:.5rem}
.notes li{font-size:.92rem;color:var(--ink-2)}
.notes code{display:inline-block;background:var(--sunk);border:1px solid var(--rule);
  border-radius:2px;padding:.05em .35em;font-size:.8rem;color:var(--ink);
  margin-right:.4rem;max-width:100%;overflow-wrap:anywhere}
.takeaway{margin:0;padding:.7rem .9rem;background:var(--accent-soft);
  border-left:3px solid var(--accent);border-radius:0 2px 2px 0;
  font-size:.93rem;color:var(--ink-2)}
.takeaway b{color:var(--ink);margin-right:.4rem}
.tablewrap{overflow-x:auto;border:1px solid var(--rule);border-radius:3px;background:var(--surface)}
table{border-collapse:collapse;width:100%;min-width:560px;font-size:.9rem}
th{text-align:left;font-weight:600;font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;
  color:var(--ink-3);padding:.7rem .95rem;border-bottom:1px solid var(--rule);background:var(--raise)}
td{padding:.62rem .95rem;border-bottom:1px solid var(--rule);vertical-align:top;color:var(--ink-2)}
tr:last-child td{border-bottom:0}
td:first-child{color:var(--ink);font-family:"JetBrains Mono",monospace;font-size:.82rem;white-space:nowrap}
.finder{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin:0 0 1.2rem}
.finder input{font:inherit;font-size:.95rem;padding:.5rem .75rem;border-radius:2px;
  border:1px solid var(--rule-2);background:var(--surface);color:var(--ink);min-width:min(320px,100%)}
.finder input:focus-visible,nav.toc a:focus-visible,.finder button:focus-visible{
  outline:2px solid var(--accent);outline-offset:2px}
.finder button{font:inherit;font-size:.85rem;cursor:pointer;padding:.5rem .8rem;
  border:1px solid var(--rule-2);background:var(--surface);color:var(--ink-2);border-radius:2px}
.finder .count{font-size:.85rem;color:var(--ink-3)}
.mod[hidden]{display:none}
.glos{display:grid;gap:.9rem;grid-template-columns:repeat(auto-fit,minmax(268px,1fr))}
.gl{border-top:2px solid var(--rule-2);padding-top:.7rem}
.gl h4{margin:0 0 .25rem;font-family:"JetBrains Mono",monospace;font-size:.85rem;color:var(--accent)}
.gl p{margin:0;font-size:.9rem;color:var(--ink-2)}
footer.end{border-top:1px solid var(--rule);margin-top:3rem;padding:1.5rem 0 3.5rem;
  color:var(--ink-3);font-size:.86rem}
@media (max-width:900px){
  .shell{grid-template-columns:1fr}
  nav.toc{position:static;border-bottom:1px solid var(--rule);padding-bottom:1rem}
  nav.toc ol{flex-direction:row;flex-wrap:wrap}
  .files li{grid-template-columns:1fr}
}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>

<header class="mast">
  <div class="wrap">
    <p class="eyebrow">Architecture field guide &middot; generated from the code</p>
    <h1>How <em>PAPEL</em> actually works</h1>
    <p class="big-lede">Every module, every feature, and the file that does the
      work &mdash; plus __TOURS__ pieces of the real code, explained line by
      line. Written for someone who does not read PHP.</p>
    <div class="stats">
      <div class="stat"><b>__PHP__</b><span>PHP files</span></div>
      <div class="stat"><b>__MODULES__</b><span>Modules</span></div>
      <div class="stat"><b>__PAGES__</b><span>Pages</span></div>
      <div class="stat"><b>__SHARED__</b><span>Shared parts</span></div>
      <div class="stat"><b>__TABLES__</b><span>Tables</span></div>
      <div class="stat"><b>__TESTS__</b><span>Test files</span></div>
    </div>
    <p class="built">Rebuilt __DATE__ by <code>scripts/build_field_guide.py</code>,
      which measures the codebase every time it runs and refuses to publish a
      page that names a file no longer there.</p>
  </div>
</header>

<div class="wrap shell">
<nav class="toc" aria-label="Contents">
  <h4>Start</h4>
  <ol>
    <li><a href="#request">What a page load is</a></li>
    <li><a href="#spine">The spine</a></li>
    <li><a href="#map">The module map</a></li>
    <li><a href="#chain">The approval chain</a></li>
  </ol>
  <h4>Read the code</h4>
  <ol>__TOC_CODE__</ol>
  <h4>Modules</h4>
  <ol>__TOC_MODULES__</ol>
  <h4>Reference</h4>
  <ol>
    <li><a href="#db">The database</a></li>
    <li><a href="#dead">Dead files</a></li>
    <li><a href="#glossary">PHP in ten terms</a></li>
    <li><a href="#upkeep">Keeping this current</a></li>
  </ol>
</nav>

<main>

<section id="request">
  <p class="eyebrow">Start here</p>
  <h2>What actually happens when someone opens a page</h2>
  <p class="lede">This is the one idea that makes the rest of the system
    readable. PHP is not like the JavaScript in a browser. <strong>Every single
    page view runs the whole program again from scratch</strong>, builds a
    finished HTML page as text, sends it, and then forgets everything.</p>

  <figure>
    <svg viewBox="0 0 940 300" role="img" aria-label="A page request travels from the browser to Apache, into one PHP file, which pulls in core.php for session, permission and database, queries MySQL, then prints HTML through the three shared layout files and sends it back.">
      <defs><marker id="ar" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" fill="currentColor"/></marker></defs>
      <g font-family="JetBrains Mono, monospace" font-size="11.5" fill="currentColor">
        <rect x="10" y="112" width="112" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="66" y="133" text-anchor="middle" font-size="12">Browser</text>
        <text x="66" y="150" text-anchor="middle" font-size="10" opacity=".7">clicks a link</text>
        <line x1="122" y1="138" x2="186" y2="138" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <text x="154" y="130" text-anchor="middle" font-size="9.5" opacity=".75">HTTP</text>
        <rect x="188" y="112" width="104" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="240" y="133" text-anchor="middle" font-size="12">Apache</text>
        <text x="240" y="150" text-anchor="middle" font-size="10" opacity=".7">XAMPP</text>
        <line x1="292" y1="138" x2="356" y2="138" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <text x="324" y="130" text-anchor="middle" font-size="9.5" opacity=".75">runs</text>
        <rect x="358" y="96" width="196" height="84" rx="2" fill="none" stroke="#8C1D2C" stroke-width="1.8"/>
        <text x="456" y="120" text-anchor="middle" font-size="12" fill="#8C1D2C">one .php page</text>
        <text x="456" y="139" text-anchor="middle" font-size="10" opacity=".8">student_dashboard.php</text>
        <text x="456" y="157" text-anchor="middle" font-size="10" opacity=".8">admin_review_dashboard.php</text>
        <text x="456" y="172" text-anchor="middle" font-size="9.5" opacity=".6">&hellip; one of __PAGES__</text>
        <line x1="456" y1="96" x2="456" y2="58" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <text x="466" y="80" font-size="9.5" opacity=".75">line 1: require</text>
        <rect x="330" y="14" width="252" height="44" rx="2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="4 3"/>
        <text x="456" y="32" text-anchor="middle" font-size="11.5">config/core.php</text>
        <text x="456" y="48" text-anchor="middle" font-size="9.5" opacity=".75">session &middot; who are you &middot; may you &middot; database</text>
        <line x1="456" y1="180" x2="456" y2="222" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <rect x="356" y="222" width="200" height="46" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="456" y="241" text-anchor="middle" font-size="11.5">MySQL</text>
        <text x="456" y="257" text-anchor="middle" font-size="9.5" opacity=".75">__TABLES__ tables &middot; asks, gets rows back</text>
        <line x1="554" y1="138" x2="618" y2="138" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <text x="586" y="130" text-anchor="middle" font-size="9.5" opacity=".75">prints</text>
        <rect x="620" y="96" width="180" height="84" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="710" y="118" text-anchor="middle" font-size="11.5">site_head.php</text>
        <text x="710" y="136" text-anchor="middle" font-size="11.5">site_header.php</text>
        <text x="710" y="154" text-anchor="middle" font-size="11.5">site_footer.php</text>
        <text x="710" y="171" text-anchor="middle" font-size="9.5" opacity=".65">the same on every page</text>
        <line x1="800" y1="138" x2="864" y2="138" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar)"/>
        <rect x="866" y="112" width="64" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="898" y="133" text-anchor="middle" font-size="11">HTML</text>
        <text x="898" y="150" text-anchor="middle" font-size="9.5" opacity=".7">plain text</text>
        <path d="M898 112 C898 40, 120 40, 66 108" fill="none" stroke="currentColor" stroke-width="1.1" stroke-dasharray="4 3" marker-end="url(#ar)" opacity=".65"/>
        <text x="150" y="60" font-size="9.5" opacity=".7">sent back, then the server forgets everything</text>
      </g>
    </svg>
    <figcaption>One page view, start to finish. The dashed return is the whole
      program ending &mdash; nothing stays in memory between two clicks.</figcaption>
  </figure>

  <div class="note">
    <strong>Why that matters for reading the code.</strong> Because nothing is
    remembered, every page has to re-establish everything at the top: who is
    signed in, whether they are allowed here, and a fresh connection to the
    database. That is why almost every file starts with the same few lines, and
    why <code>core.php</code> is pulled in by __CORE_USED__ other files. It is
    not duplication &mdash; it is the only way each page knows anything at all.
  </div>

  <p>The one thing that <em>does</em> survive between page loads is the
    <strong>session</strong>: a small file on the server holding "this browser is
    signed in as user 154". The browser carries a cookie with the session's id,
    the server reads the matching file, and that is how the site knows you.</p>
</section>

<section id="spine">
  <p class="eyebrow">The spine</p>
  <h2>Four files that almost everything depends on</h2>
  <p class="lede">If you learn only four files, learn these. Between them they
    are pulled into virtually every page on the site.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>File</th><th>What it is for</th></tr></thead>
    <tbody>
      <tr><td>config/core.php</td><td>The toolbox. Opens the database, starts the
        session, answers "who is signed in" and "may they be here", sends email,
        writes notifications, and holds the shared rules.</td></tr>
      <tr><td>includes/site_head.php</td><td>Everything inside &lt;head&gt;:
        fonts, colours, the light and dark palettes. Change a colour here and it
        changes everywhere.</td></tr>
      <tr><td>includes/site_header.php</td><td>The bar across the top &mdash; the
        wordmark, the menu that differs per role, the notification bell, the
        avatar menu, the sign-in panel.</td></tr>
      <tr><td>includes/site_footer.php</td><td>The foot of the page and the
        shared JavaScript: the bell, the dropdowns, the confirm dialogs, the
        loading bar.</td></tr>
    </tbody>
  </table></div>
  <p class="legend">
    <span><i style="background:var(--k-page)"></i> a page you can open</span>
    <span><i style="background:var(--k-shared)"></i> shared piece</span>
    <span><i style="background:var(--k-engine)"></i> engine / settings</span>
    <span><i style="background:var(--k-model)"></i> database logic</span>
    <span><i style="background:var(--k-test)"></i> Python test</span>
    <span><i style="background:var(--k-script)"></i> command-line script</span>
  </p>
</section>

<section id="map">
  <p class="eyebrow">The map</p>
  <h2>How the modules sit together</h2>
  <p class="lede">The arrows are what one module hands to another &mdash; a
    paper, a decision, a message. This draws the path work takes; the
    dashboards and the everyday pages (module 13) sit across all of it rather
    than at one point on it.</p>
  <figure>
    <svg viewBox="0 0 940 470" role="img" aria-label="A student submits a paper, which goes to the adviser then the coordinator for review, and once approved appears in the public repository. Storage, AI, messages and analytics attach to those steps, and every module rests on the foundation and the shared look.">
      <defs>
        <marker id="ar2" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" fill="currentColor"/></marker>
        <marker id="ar3" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" fill="#8C1D2C"/></marker>
      </defs>
      <g font-family="JetBrains Mono, monospace" font-size="11" fill="currentColor">
        <rect x="24" y="150" width="150" height="56" rx="2" fill="none" stroke="#8C1D2C" stroke-width="1.7"/>
        <text x="99" y="172" text-anchor="middle" font-size="11.5" fill="#8C1D2C">5 &middot; Submitting</text>
        <text x="99" y="190" text-anchor="middle" font-size="9.5" opacity=".75">the student's 4 steps</text>
        <line x1="174" y1="178" x2="242" y2="178" stroke="#8C1D2C" stroke-width="1.6" marker-end="url(#ar3)"/>
        <text x="208" y="170" text-anchor="middle" font-size="9" fill="#8C1D2C">a paper</text>
        <rect x="244" y="150" width="150" height="56" rx="2" fill="none" stroke="#8C1D2C" stroke-width="1.7"/>
        <text x="319" y="172" text-anchor="middle" font-size="11.5" fill="#8C1D2C">6 &middot; Review</text>
        <text x="319" y="190" text-anchor="middle" font-size="9.5" opacity=".75">adviser, then coordinator</text>
        <line x1="394" y1="178" x2="462" y2="178" stroke="#8C1D2C" stroke-width="1.6" marker-end="url(#ar3)"/>
        <text x="428" y="170" text-anchor="middle" font-size="9" fill="#8C1D2C">approved</text>
        <rect x="464" y="150" width="150" height="56" rx="2" fill="none" stroke="#8C1D2C" stroke-width="1.7"/>
        <text x="539" y="172" text-anchor="middle" font-size="11.5" fill="#8C1D2C">7 &middot; Reading</text>
        <text x="539" y="190" text-anchor="middle" font-size="9.5" opacity=".75">public repository</text>
        <line x1="614" y1="178" x2="682" y2="178" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar2)"/>
        <text x="648" y="170" text-anchor="middle" font-size="9" opacity=".75">counts</text>
        <rect x="684" y="150" width="150" height="56" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="759" y="172" text-anchor="middle" font-size="11.5">9 &middot; Analytics</text>
        <text x="759" y="190" text-anchor="middle" font-size="9.5" opacity=".75">charts and exports</text>
        <rect x="24" y="52" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="99" y="73" text-anchor="middle" font-size="11.5">11 &middot; Storage</text>
        <text x="99" y="90" text-anchor="middle" font-size="9.5" opacity=".75">Google Drive</text>
        <line x1="99" y1="150" x2="99" y2="108" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar2)"/>
        <text x="107" y="132" font-size="9" opacity=".75">the PDF</text>
        <rect x="244" y="52" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="319" y="73" text-anchor="middle" font-size="11.5">10 &middot; AI</text>
        <text x="319" y="90" text-anchor="middle" font-size="9.5" opacity=".75">reads the PDF, answers</text>
        <path d="M150 150 L246 106" fill="none" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar2)"/>
        <text x="196" y="122" font-size="9" opacity=".75">fills the form</text>
        <rect x="244" y="256" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="319" y="277" text-anchor="middle" font-size="11.5">8 &middot; Messages</text>
        <text x="319" y="294" text-anchor="middle" font-size="9.5" opacity=".75">bell and email</text>
        <line x1="319" y1="206" x2="319" y2="252" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar2)"/>
        <text x="327" y="232" font-size="9" opacity=".75">tells the student</text>
        <rect x="24" y="256" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="99" y="277" text-anchor="middle" font-size="11.5">4 &middot; Accounts</text>
        <text x="99" y="294" text-anchor="middle" font-size="9.5" opacity=".75">who exists</text>
        <path d="M99 256 L99 212" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="4 3" marker-end="url(#ar2)"/>
        <text x="20" y="236" font-size="9" opacity=".75">who advises whom</text>
        <rect x="464" y="256" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="539" y="277" text-anchor="middle" font-size="11.5">3 &middot; Signing in</text>
        <text x="539" y="294" text-anchor="middle" font-size="9.5" opacity=".75">ID + password</text>
        <line x1="464" y1="282" x2="398" y2="282" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar2)"/>
        <rect x="684" y="256" width="150" height="52" rx="2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="4 3"/>
        <text x="759" y="277" text-anchor="middle" font-size="11.5">12 &middot; Tests</text>
        <text x="759" y="294" text-anchor="middle" font-size="9.5" opacity=".75">Python, drives the site</text>
        <rect x="24" y="356" width="810" height="46" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/>
        <text x="429" y="375" text-anchor="middle" font-size="11.5">1 &middot; Foundation &mdash; config/core.php</text>
        <text x="429" y="392" text-anchor="middle" font-size="9.5" opacity=".75">session &middot; permissions &middot; database &middot; shared rules</text>
        <rect x="24" y="410" width="810" height="42" rx="2" fill="none" stroke="currentColor" stroke-width="1.4"/>
        <text x="429" y="428" text-anchor="middle" font-size="11.5">2 &middot; Look and feel &mdash; site_head / site_header / site_footer</text>
        <text x="429" y="444" text-anchor="middle" font-size="9.5" opacity=".75">the same shell around every page</text>
        <line x1="429" y1="356" x2="429" y2="322" stroke="currentColor" stroke-width="1.1" stroke-dasharray="3 3" opacity=".6"/>
        <text x="437" y="342" font-size="9" opacity=".6">everything above rests on these two</text>
      </g>
    </svg>
    <figcaption>Solid maroon is the path a paper takes. Everything else attaches
      to that path; the two bands at the bottom are underneath all of it.</figcaption>
  </figure>
</section>

<section id="chain">
  <p class="eyebrow">The rule at the centre</p>
  <h2>The approval chain, and the word <code>draft</code></h2>
  <p class="lede">This is the business rule the whole system is arranged around,
    and the one place the database column names will confuse you.</p>
  <figure>
    <svg viewBox="0 0 900 240" role="img" aria-label="A paper moves from draft to pending_faculty when submitted, to pending_admin when the adviser approves, to approved when the coordinator approves. A return from either reviewer sends it back to draft carrying feedback.">
      <defs><marker id="ar4" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" fill="currentColor"/></marker></defs>
      <g font-family="JetBrains Mono, monospace" font-size="11" fill="currentColor">
        <rect x="20" y="70" width="150" height="50" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="95" y="90" text-anchor="middle">draft</text>
        <text x="95" y="107" text-anchor="middle" font-size="9.5" opacity=".7">student is writing</text>
        <line x1="170" y1="95" x2="228" y2="95" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar4)"/>
        <text x="199" y="87" text-anchor="middle" font-size="9" opacity=".75">submit</text>
        <rect x="230" y="70" width="170" height="50" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="315" y="90" text-anchor="middle">pending_faculty</text>
        <text x="315" y="107" text-anchor="middle" font-size="9.5" opacity=".7">on the adviser's desk</text>
        <line x1="400" y1="95" x2="458" y2="95" stroke="currentColor" stroke-width="1.2" marker-end="url(#ar4)"/>
        <text x="429" y="87" text-anchor="middle" font-size="9" opacity=".75">approve</text>
        <rect x="460" y="70" width="170" height="50" rx="2" fill="none" stroke="currentColor" stroke-width="1.2"/>
        <text x="545" y="90" text-anchor="middle">pending_admin</text>
        <text x="545" y="107" text-anchor="middle" font-size="9.5" opacity=".7">on the coordinator's desk</text>
        <line x1="630" y1="95" x2="688" y2="95" stroke="#8C1D2C" stroke-width="1.6" marker-end="url(#ar4)"/>
        <text x="659" y="87" text-anchor="middle" font-size="9" fill="#8C1D2C">approve</text>
        <rect x="690" y="70" width="170" height="50" rx="2" fill="none" stroke="#8C1D2C" stroke-width="1.8"/>
        <text x="775" y="90" text-anchor="middle" fill="#8C1D2C">approved</text>
        <text x="775" y="107" text-anchor="middle" font-size="9.5" opacity=".75">public. the chain ends</text>
        <path d="M315 120 C315 180, 95 180, 95 124" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="5 3" marker-end="url(#ar4)"/>
        <path d="M545 120 C545 205, 95 205, 95 126" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="5 3" marker-end="url(#ar4)"/>
        <text x="300" y="197" text-anchor="middle" font-size="9.5" opacity=".8">returned &mdash; goes back to draft, carrying the reviewer's reason</text>
        <text x="775" y="150" text-anchor="middle" font-size="9.5" opacity=".7">the Head and the Director</text>
        <text x="775" y="164" text-anchor="middle" font-size="9.5" opacity=".7">read this; they do not approve</text>
      </g>
    </svg>
    <figcaption>Two approvals, not four. The chain was deliberately shortened.</figcaption>
  </figure>
  <div class="note">
    <strong>The trap.</strong> A returned paper goes back to <code>draft</code>.
    So <code>draft</code> means either "never submitted" <em>or</em> "sent
    back" &mdash; two very different things wearing the same word. Anywhere the
    code needs to tell them apart it asks a second question: is there a row in
    <code>approval_workflow</code> for this paper? If yes, it was submitted once
    and returned.
  </div>
</section>

<section id="code">
  <p class="eyebrow">Read the code</p>
  <h2>__TOURS__ pieces of the real thing, explained</h2>
  <p class="lede">These are lifted out of the files every time this page is
    built, so what you see below is the code as it is right now &mdash; not a
    copy that has drifted. Learn these patterns and most of the project becomes
    readable, because it repeats them.</p>
  <div class="tours">__CODE__</div>
</section>

<section id="modules">
  <p class="eyebrow">The modules</p>
  <h2>Every group, and every file in it</h2>
  <p class="lede">Each card is a module. Inside are its features, and under each
    feature the files that do the work &mdash; with why that file exists.</p>
  <div class="finder">
    <input id="q" type="search" placeholder="Search a filename or a feature&hellip;" autocomplete="off" aria-label="Search files and features">
    <button type="button" id="clear">Show all</button>
    <span class="count" id="count"></span>
  </div>
  <div class="mods" id="mods">__MODULE_CARDS__</div>
</section>

<section id="db">
  <p class="eyebrow">Reference</p>
  <h2>The database, table by table</h2>
  <p class="lede">The first four are where nearly everything happens; the rest
    support them.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Table</th><th>What it holds</th></tr></thead>
    <tbody>__DB__</tbody>
  </table></div>
</section>

<section id="dead">
  <h2>Files nothing uses</h2>
  <p class="lede">No other file refers to these. Knowing they are dead saves you
    reading them &mdash; and one of them is a trap.</p>
  <ul class="files" style="gap:.6rem">__DEAD__</ul>
</section>

<section id="glossary">
  <h2>PHP in ten terms</h2>
  <p class="lede">Enough to read almost any file in this project.</p>
  <div class="glos">__GLOSSARY__</div>
</section>

<section id="upkeep">
  <h2>Keeping this current</h2>
  <p class="lede">This page is generated, not written by hand, so it can be
    rebuilt whenever the code changes.</p>
  <div class="note">
    <strong>To rebuild it:</strong> <code>python scripts/build_field_guide.py</code>
  </div>
  <p>The script re-counts the files, re-reads every code sample from the file it
    came from, and then checks itself. It will tell you, and refuse to finish
    quietly, when:</p>
  <ul>
    <li>the guide names a file that no longer exists &mdash; so a rename cannot
      leave this page lying to you;</li>
    <li>a code sample's anchor line has been edited away;</li>
    <li>a file listed as dead has come back into use;</li>
    <li>a file exists that the guide has never mentioned &mdash; new work to
      write up.</li>
  </ul>
  <p>It also prints what changed since the last build, so you can see at a glance
    whether the explanations need revisiting. The wording lives in
    <code>scripts/field_guide_content.py</code>; that is the only file to edit
    when a feature changes.</p>
</section>

<footer class="end">
  <p>Generated __DATE__ from the codebase at <code>c:\xampp\htdocs\capstone</code>.
    File counts, line counts, the include graph and every code sample were
    measured or lifted from source, not recalled.
    PHP 8.0 &middot; MariaDB 10.4 &middot; XAMPP &middot; no framework, no build step.</p>
</footer>

</main>
</div>

<script>
(function () {
  var q = document.getElementById('q');
  var clear = document.getElementById('clear');
  var count = document.getElementById('count');
  var mods = Array.prototype.slice.call(document.querySelectorAll('#mods .mod'));
  function apply() {
    var term = q.value.trim().toLowerCase();
    var shown = 0;
    mods.forEach(function (m) {
      var hit = !term || m.textContent.toLowerCase().indexOf(term) > -1;
      m.hidden = !hit;
      if (hit) { shown++; }
    });
    count.textContent = term ? shown + ' of ' + mods.length + ' modules' : '';
  }
  q.addEventListener('input', apply);
  clear.addEventListener('click', function () { q.value = ''; apply(); q.focus(); });
})();
</script>
"""

if __name__ == '__main__':
    sys.exit(build())
