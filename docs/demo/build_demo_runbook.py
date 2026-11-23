"""Build the PAPEL Demo Day Runbook as a PDF.

    python docs/demo/build_demo_runbook.py

Writes PAPEL_Demo_Runbook.html and .pdf beside this file, from the Markdown of
the same name. The same shape as docs/handbook/build_presentation_guide.py, and
for the same reason: the words belong in Markdown where they can be edited
without touching any code, and Chrome does the printing, so nothing has to be
installed but a browser.

Chrome is always given a throwaway --user-data-dir. Without one it opens the
everyday profile, and because Chrome allows one process per profile it would
close the window the person at this computer is using.
"""
from __future__ import annotations

import html
import shutil
import subprocess
import tempfile
from pathlib import Path

import markdown

HERE = Path(__file__).resolve().parent
SOURCE = HERE / 'PAPEL_Demo_Runbook.md'
OUT_HTML = HERE / 'PAPEL_Demo_Runbook.html'
OUT_PDF = HERE / 'PAPEL_Demo_Runbook.pdf'

CANDIDATES = [
    Path(r'C:\Program Files\Google\Chrome\Application\chrome.exe'),
    Path(r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe'),
    Path(r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'),
]

CSS = '''
@page { size: A4; margin: 17mm 15mm 16mm;
  @bottom-left { content: "PAPEL  \\2022  Demo Day Runbook"; color: #7d6666; font: 8pt Arial, sans-serif; }
  @bottom-right { content: counter(page); color: #7d6666; font: 8pt Arial, sans-serif; } }
* { box-sizing: border-box; }
html { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
body { font-family: 'Inter', Arial, 'Segoe UI', sans-serif; color: #29272a; font-size: 10.3pt;
       line-height: 1.5; margin: 0; }
h1 { font-size: 20pt; color: #820707; margin: 0 0 6pt; line-height: 1.2; letter-spacing: -.2pt; }
/* Part headings open a page: each part is read on its own, often by a different
   person on the team. */
h1:not(:first-of-type) { page-break-before: always; padding-top: 2pt; }
h2 { font-size: 13pt; color: #820707; margin: 16pt 0 5pt; page-break-after: avoid;
     border-bottom: 1px solid #e6d4d4; padding-bottom: 3pt; }
h3 { font-size: 11pt; margin: 11pt 0 4pt; page-break-after: avoid; }
p, li { margin: 0 0 5pt; }
ul, ol { margin: 0 0 7pt; padding-left: 15pt; }
li { page-break-inside: avoid; }
code { font-family: 'JetBrains Mono', Consolas, monospace; font-size: 9pt;
       background: #f8ecec; padding: 1pt 3pt; border-radius: 3px; color: #630000; }
pre { background: #f8ecec; border-left: 3px solid #820707; padding: 7pt 9pt; border-radius: 4px;
      page-break-inside: avoid; margin: 0 0 8pt; }
pre code { background: none; padding: 0; }
table { border-collapse: collapse; width: 100%; margin: 0 0 9pt; font-size: 9.4pt;
        page-break-inside: avoid; }
th { background: #820707; color: #fff; text-align: left; padding: 4pt 6pt; font-weight: 700; }
td { border: 1px solid #e6d4d4; padding: 4pt 6pt; vertical-align: top; }
tr:nth-child(even) td { background: #fdf9f9; }
/* A blockquote is a warning in this document, never an aside. */
blockquote { margin: 0 0 9pt; padding: 7pt 10pt; background: #fdf4dc; border-left: 4px solid #dca92c;
             border-radius: 4px; page-break-inside: avoid; }
blockquote p { margin: 0; }
blockquote p + p { margin-top: 4pt; }
hr { border: 0; border-top: 1px solid #e6d4d4; margin: 12pt 0; }
strong { color: #29272a; }
h2 + table, h3 + table { margin-top: 2pt; }
.cover { text-align: center; padding: 40pt 0 16pt; border-bottom: 2px solid #820707; margin-bottom: 14pt; }
.cover img { width: 86px; margin-bottom: 10pt; }
.cover .t { font-family: 'Plus Jakarta Sans', Arial, sans-serif; font-size: 25pt; font-weight: 800;
            color: #820707; letter-spacing: -.4pt; }
.cover .s { color: #6b5a5a; font-size: 10.5pt; margin-top: 5pt; }
'''


def chrome() -> Path:
    for c in CANDIDATES:
        if c.is_file():
            return c
    raise SystemExit('No Chrome or Edge found; install one or edit CANDIDATES.')


def build() -> None:
    body = markdown.markdown(
        SOURCE.read_text(encoding='utf-8'),
        extensions=['tables', 'fenced_code', 'sane_lists', 'attr_list'])
    logo = (HERE.parent.parent / 'assests' / 'images' / 'Logo-Papel.png')
    logo_tag = '<img src="%s" alt="">' % logo.as_uri() if logo.is_file() else ''
    cover = ('<div class="cover">%s<div class="t">Demo Day Runbook</div>'
             '<div class="s">PAPEL &middot; Research Archiving System &middot; PUP Bi&ntilde;an Campus</div>'
             '<div class="s">What to prepare before the defence, and how to show each workflow</div></div>'
             % logo_tag)
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8">'
            '<title>%s</title>'
            '<link rel="stylesheet" href="%s">'
            '<style>%s</style></head><body>%s%s</body></html>'
            % (html.escape('PAPEL Demo Day Runbook'),
               (HERE.parent.parent / 'assests' / 'fonts' / 'papel-fonts.css').as_uri(),
               CSS, cover, body))
    OUT_HTML.write_text(page, encoding='utf-8')

    tmp = Path(tempfile.mkdtemp(prefix='papel_demo_'))
    try:
        subprocess.run([str(chrome()), '--headless=new', '--disable-gpu',
                        '--user-data-dir=%s' % (tmp / 'profile'),
                        '--no-pdf-header-footer',
                        '--print-to-pdf=%s' % OUT_PDF,
                        '--print-to-pdf-no-header',
                        OUT_HTML.as_uri()], check=True, capture_output=True, timeout=180)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    print('  wrote     %s' % OUT_HTML.relative_to(HERE.parent.parent))
    print('  wrote     %s (%.1f KB)' % (OUT_PDF.relative_to(HERE.parent.parent),
                                        OUT_PDF.stat().st_size / 1024))


if __name__ == '__main__':
    print('PAPEL Demo Day Runbook')
    build()
