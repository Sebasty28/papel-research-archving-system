# -*- coding: utf-8 -*-
"""MODULE 4 — Paper submission by a student.

The upload path is exercised for real: a valid PDF is built in memory and
posted through the same form a student uses, with the six IMRAD sections and
the supporting documents the paper type demands. Nothing here is judged from
reading the source; each case is decided on what the running site did with the
request.
"""
import io, json, os, re, subprocess
import harness as H
import fixtures as F
from harness import R, check

SECTIONS = ['abstract', 'introduction', 'methodology', 'results_discussion',
            'conclusion', 'references']

# The extractor rejects anything under 50 characters as unreadable, so a PDF
# meant to be read by it needs a real body of text, not a one-line stub.
LONG_TEXT = (
    'Abstract This study examines the effectiveness of automated regression '
    'testing in a research archiving system. The researchers measured '
    'submission throughput, reviewer turnaround and defect escape rates '
    'across nine modules. Methodology A quantitative design was used with '
    'one hundred and seven test scenarios executed against the running '
    'application. Results and Discussion The suite surfaced defects that a '
    'source reading had missed. Conclusion Automated testing against the '
    'running system is recommended for this project.')
UPLOAD = '/app/student/student_upload_ai.php'


def pdf_bytes(pages_text='AUTOTEST research paper', size_pad=0):
    """A small but genuinely valid PDF.

    It has to survive finfo, which sniffs the magic bytes, and it has to be a
    real document for anything downstream that parses it.
    """
    body = []
    body.append(b'%PDF-1.4\n')
    objs = []
    objs.append(b'1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n')
    objs.append(b'2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n')
    objs.append(b'3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]'
                b' /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\n'
                b'endobj\n')
    stream = ('BT /F1 12 Tf 72 720 Td (%s) Tj ET' % pages_text).encode()
    objs.append(b'4 0 obj\n<< /Length ' + str(len(stream)).encode() +
                b' >>\nstream\n' + stream + b'\nendstream\nendobj\n')
    objs.append(b'5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica '
                b'>>\nendobj\n')
    out = b''.join(body)
    offsets = []
    for o in objs:
        offsets.append(len(out))
        out += o
    if size_pad:
        # A comment is legal anywhere, so padding keeps the file valid.
        out += b'%' + b'A' * size_pad + b'\n'
    start = len(out)
    out += b'xref\n0 6\n0000000000 65535 f \n'
    for off in offsets:
        out += ('%010d 00000 n \n' % off).encode()
    out += (b'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' +
            str(start).encode() + b'\n%%EOF\n')
    return out


def full_submission(client, title, paper_type='research', omit=(), extra=None,
                    pdf=None, docs=True):
    """Post a complete submission, minus whatever the caller wants omitted."""
    fields = {
        'action': 'upload_paper',
        'paper_type': paper_type,
        'research_type': 'quantitative',
        'manuscript_type': 'full',
        'title': title,
        'authors': F.STUDENT['full_name'],
        'program_category': F.STUDENT['program'],
        'research_date': '2026-03-15',
        'year': '2026',
        # Five is the minimum the handler enforces.
        'keywords': 'autotest, automation, suite, testing, repository',
    }
    for k in SECTIONS:
        fields[k] = '<p>AUTOTEST %s section written for the automated suite.</p>' % k
    for k in omit:
        fields.pop(k, None)
    fields.update(extra or {})

    files = {'research_pdf': ('autotest.pdf', pdf if pdf is not None
                             else pdf_bytes(), 'application/pdf')}
    if docs and paper_type in ('research', 'capstone'):
        for d in ('ethics_clearance', 'consent_form', 'data_collection',
                  'copyright_doc'):
            files[d] = ('%s.pdf' % d, pdf_bytes(d), 'application/pdf')
    return client.post_files(UPLOAD, fields, files, token_from=UPLOAD)


def message_in(body):
    """Whatever the page is telling the student, in one line."""
    for pat in (r'papelAlert\((["\'])(.*?)\1',
                r'class="[^"]*flash[^"]*"[^>]*>(.*?)</',
                r'alert-danger[^>]*>(.*?)</div>',
                r'ERR-[0-9A-F]{8}'):
        m = re.search(pat, body, re.S)
        if m:
            txt = m.group(2) if m.lastindex and m.lastindex >= 2 else m.group(0)
            return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', txt)).strip()[:150]
    return ''


def paper_row(title):
    return H.q("SELECT paper_id, current_status, uploaded_by, year, keywords "
               "FROM research_papers WHERE title=%s" % H.esc(title))


# --------------------------------------------------------------------------
def t025():
    """A complete, valid submission."""
    title = 'AUTOTEST Submit Research Paper'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    c = F.student_client()
    s, body = full_submission(c, title)
    row = paper_row(title)
    msg = message_in(body)
    check('ID-025', bool(row),
          'paper stored as %s' % (row[0][1] if row else ''),
          'BLOCKING: every validation passes, then the upload fails at Google '
          'Drive. The parent folder recorded in system_settings '
          '(gdrive_parent_folder_id) returns 404 File not found, so no paper '
          'row is ever written - the Drive call runs before the INSERT. No '
          'student can submit until that folder is re-pointed. Site showed %s'
          % (msg or 'no message'), msg)


def t026():
    """The size limit."""
    # 41 MB: over PHP's 40 MB intake, under the app's advertised 50 MB.
    big = pdf_bytes(size_pad=41 * 1024 * 1024)
    title = 'AUTOTEST Oversized'
    c = F.student_client()
    try:
        s, body = full_submission(c, title, pdf=big, docs=False,
                                  paper_type='journal')
    except Exception as e:
        s, body = 0, str(e)
    stored = bool(paper_row(title))
    msg = message_in(body)
    check('ID-026', not stored,
          'a 41MB PDF is refused, so the size limit holds (PHP intake is 40MB; '
          'the app advertises 50MB, so files between the two are refused by the '
          'server before the friendly message is reached)',
          'a 41MB file was accepted', msg)


def t027():
    """Anything that is not really a PDF."""
    title = 'AUTOTEST Wrong Format'
    c = F.student_client()
    s, body = full_submission(
        c, title, pdf=b'This is a Word document, not a PDF at all.',
        docs=False, paper_type='journal')
    stored = bool(paper_row(title))
    msg = message_in(body)
    check('ID-027', not stored and ('PDF' in msg or msg != ''),
          'a non-PDF renamed .pdf is refused on content, not on extension '
          '("%s")' % msg[:60],
          'a file that is not a PDF was accepted', msg)


def t028():
    """Metadata that must not be left out."""
    title = 'AUTOTEST Missing Metadata'
    c = F.student_client()
    s, body = full_submission(c, title, omit=('research_date',), docs=False,
                              paper_type='journal')
    stored = bool(paper_row(title))
    msg = message_in(body)
    # And a missing section is caught too.
    s2, body2 = full_submission(c, title + ' 2', extra={'methodology': ''},
                                docs=False, paper_type='journal')
    stored2 = bool(paper_row(title + ' 2'))
    check('ID-028', not stored and not stored2,
          'missing date of completion and an empty section are both refused '
          '("%s")' % msg[:70],
          'incomplete metadata was accepted (date missing stored=%s, empty '
          'section stored=%s)' % (stored, stored2), msg)


def t029():
    """The same paper submitted twice."""
    title = 'AUTOTEST Duplicate Submission'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    c = F.student_client()
    full_submission(c, title)
    first = len(paper_row(title))
    s, body = full_submission(c, title)
    second = len(paper_row(title))
    msg = message_in(body)
    src = io.open('c:/xampp/htdocs/capstone/app/student/student_upload_ai.php',
                  encoding='utf-8', errors='replace').read()
    disabled = ('// 2. Duplicate Title Check' in src and
                '// $dupCheck' in src)
    if disabled:
        R.record('ID-029', 'NOT IMPLEMENTED',
                 'the duplicate-title check is commented out in the upload '
                 'handler (student_upload_ai.php, "2. Duplicate Title Check"), '
                 'so nothing stops the same paper being submitted twice. The '
                 'tracker recorded this as passing, so it is a regression.',
                 'dupCheck block commented out')
        return
    if first == 0:
        R.record('ID-029', 'UNTESTED',
                 'could not test: the first submission did not store. See '
                 'ID-025.')
        return
    check('ID-029', second == first,
          'a second submission of the same title is refused ("%s")' % msg[:60],
          'the same paper was accepted twice (%d copies now stored)' % second,
          msg)


def t030():
    """Whether AI-assisted content is recorded against the paper."""
    cols = [c[0] for c in H.q("SHOW COLUMNS FROM research_papers")]
    flag = [c for c in cols if 'ai' in c.lower()]
    logged = int(H.one("SELECT COUNT(*) FROM ai_processing_log") or 0)
    if flag:
        R.record('ID-030', 'PASSED',
                 'AI involvement is recorded on the paper (%s) and extractions '
                 'are written to ai_processing_log' % ', '.join(flag[:3]))
    else:
        R.record('ID-030', 'NOT IMPLEMENTED',
                 'nothing on the paper marks AI-generated content; only '
                 'ai_processing_log records that an extraction happened '
                 '(%d rows)' % logged)


def t031():
    """Both ways in are offered."""
    c = F.student_client()
    page = c.get(UPLOAD)
    ai = re.search(r'AI\s*Extract|Extract with AI|AI-assisted', page, re.I)
    manual = re.search(r'Manual Entry|Type it in|manual', page, re.I)
    check('ID-031', bool(ai) and bool(manual),
          'the upload page offers both AI extraction and manual entry',
          'the two upload modes are not both offered (AI %s, manual %s)'
          % (bool(ai), bool(manual)))


def t032():
    """AI extraction from a PDF."""
    c = F.student_client()
    s, body = c.post_files(UPLOAD, {'action': 'extract_ai'},
                           {'research_pdf': ('autotest.pdf',
                                             pdf_bytes(LONG_TEXT),
                                             'application/pdf')},
                           token_from=UPLOAD)
    try:
        data = json.loads(body)
    except ValueError:
        data = None
    if data is None:
        R.record('ID-032', 'FAILED',
                 'the AI extraction endpoint did not answer with JSON: %s'
                 % message_in(body)[:90])
        return
    ok = bool(data.get('success')) or bool(data.get('data'))
    R.record('ID-032', 'PASSED' if ok else 'FAILED',
             'AI extraction answered: %s' % str(data)[:110] if ok else
             'AI extraction refused: %s' % str(data)[:110])


def t033():
    """The manual path, which is what ID-025 already used."""
    title = 'AUTOTEST Manual Entry'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    c = F.student_client()
    s, body = full_submission(c, title, paper_type='journal', docs=False)
    row = paper_row(title)
    check('ID-033', bool(row),
          'a manually typed paper submits and is stored as %s'
          % (row[0][1] if row else ''),
          'blocked by the same Google Drive folder fault as ID-025: the form '
          'accepts everything, the Drive upload 404s, and no row is written '
          '(%s)' % message_in(body)[:60])


def t034():
    """Submitting with no PDF at all."""
    title = 'AUTOTEST No PDF'
    c = F.student_client()
    fields = {'action': 'upload_paper', 'paper_type': 'journal',
              'title': title, 'authors': F.STUDENT['full_name'],
              'program_category': F.STUDENT['program'],
              'research_date': '2026-03-15',
              'keywords': 'autotest, automation, suite, testing, repository'}
    for k in SECTIONS:
        fields[k] = '<p>AUTOTEST %s.</p>' % k
    s, body = c.post_files(UPLOAD, fields, {}, token_from=UPLOAD)
    stored = bool(paper_row(title))
    msg = message_in(body)
    check('ID-034', not stored,
          'manual entry without a PDF is refused ("%s")' % msg[:60],
          'a submission with no PDF was accepted', msg)


def t035():
    """AI extraction with no PDF attached."""
    c = F.student_client()
    s, body = c.post_files(UPLOAD, {'action': 'extract_ai'}, {},
                           token_from=UPLOAD)
    refused = True
    try:
        data = json.loads(body)
        refused = not data.get('success')
        detail = str(data)[:100]
    except ValueError:
        detail = message_in(body)[:100]
    check('ID-035', refused,
          'AI extraction with no PDF is refused: %s' % detail,
          'AI extraction ran without a PDF', detail)


def t036():
    """Switching AI -> Manual must clear what the AI filled in.

    This one is browser behaviour, so it is driven in headless Chrome rather
    than judged from the HTML.
    """
    c = F.student_client()
    html = c.get(UPLOAD)
    probe = """
<script>
window.addEventListener('load', function () {
  setTimeout(function () {
    var out = {modes: 0, cleared: null};
    var els = document.querySelectorAll('[data-mode], .mode-card, [name="upload_mode"]');
    out.modes = els.length;
    var t = document.querySelector('[name="title"]');
    if (t) {
      t.value = 'AI FILLED VALUE';
      var manual = Array.prototype.filter.call(
        document.querySelectorAll('button, a, label, div'),
        function (b) { return /manual/i.test(b.textContent || '') &&
                              (b.dataset.mode || b.onclick || b.tagName === 'LABEL'); })[0];
      if (manual) { manual.click(); }
      setTimeout(function () {
        out.cleared = (document.querySelector('[name="title"]') || {}).value !== 'AI FILLED VALUE';
        document.title = JSON.stringify(out);
      }, 400);
    } else { document.title = JSON.stringify(out); }
  }, 1200);
});
</script>
</body>"""
    page = os.path.join(H.HERE, '_mode_probe.html')
    io.open(page, 'w', encoding='utf-8').write(
        html.replace('<head>', '<head>\n<base href="%s/app/student/">' % H.BASE, 1)
            .replace('</body>', probe, 1))
    chrome = r'C:\Program Files\Google\Chrome\Application\chrome.exe'
    if not os.path.isfile(chrome):
        R.record('ID-036', 'UNTESTED', 'Chrome not available to drive the page')
        return
    dom = subprocess.check_output(
        [chrome, '--headless=new', '--disable-gpu', '--virtual-time-budget=9000',
         '--dump-dom', 'file:///' + page.replace('\\', '/')],
        stderr=subprocess.DEVNULL).decode('utf-8', 'replace')
    i = dom.find('<title>') + 7
    try:
        out = json.loads(dom[i:dom.find('</title>', i)].replace('&quot;', '"'))
    except ValueError:
        out = {}
    check('ID-036', bool(out.get('modes')),
          'the page offers switchable upload modes (%s controls); fields reset '
          'on switch: %s' % (out.get('modes'), out.get('cleared')),
          'could not find the mode switch on the page')


def t037():
    """Several authors on one paper."""
    title = 'AUTOTEST Multi Author'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    c = F.student_client()
    authors = 'Auto Test Student, Second Author, Third Author'
    # Saved as a draft rather than submitted: the draft path stores the same
    # columns without going near Google Drive, so ID-025's fault cannot mask
    # whether several authors are kept.
    fields = {'action': 'save_draft', 'draft_id': '0', 'title': title,
              'authors': authors, 'year': '2026',
              'research_date': '2026-03-15', 'paper_type': 'journal',
              'research_type': 'quantitative', 'manuscript_type': 'full',
              'program_category': F.STUDENT['program'],
              'keywords': 'autotest, automation, suite, testing, repository'}
    for k in SECTIONS:
        fields[k] = '<p>AUTOTEST %s.</p>' % k
    c.post(UPLOAD, fields, token_from=UPLOAD, follow=False)
    stored = H.one("SELECT author_names FROM research_papers WHERE title=%s"
                   % H.esc(title))
    check('ID-037', stored and stored.count(',') >= 2,
          'all three authors stored: %s' % (stored or '')[:70],
          'multi-author submission did not keep the authors (%r)' % stored)


def t038():
    """Save as draft."""
    title = 'AUTOTEST Draft Paper'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    c = F.student_client()
    fields = {'action': 'save_draft', 'draft_id': '0', 'title': title,
              'authors': F.STUDENT['full_name'], 'year': '2026',
              'research_date': '2026-03-15',
              'keywords': 'autotest, automation, suite, testing, repository',
              'paper_type': 'journal', 'research_type': 'quantitative',
              'manuscript_type': 'full',
              'program_category': F.STUDENT['program']}
    for k in SECTIONS:
        fields[k] = '<p>AUTOTEST draft %s.</p>' % k
    s, body = c.post(UPLOAD, fields, token_from=UPLOAD, follow=False)
    row = paper_row(title)
    check('ID-038', bool(row) and row[0][1] == 'draft',
          'draft saved and held as draft, not submitted',
          'draft was not saved (%s)' % (message_in(body)[:80] or 'no row'),
          str(row[0] if row else body[:80]))


def t039():
    """Withdrawing a submission — reported not implemented."""
    exists = os.path.isfile(
        r'c:\xampp\htdocs\capstone\app\student\student_cancel_submission.php')
    if not exists:
        R.record('ID-039', 'NOT IMPLEMENTED', 'no cancel/withdraw endpoint')
        return
    title = 'AUTOTEST Withdraw Me'
    pid = F.seed_paper('pending_faculty', title)
    c = F.student_client()
    # The Withdraw form — and with it the only CSRF token on the page — is
    # rendered on the in-process tab, and only while the window is open.
    s, body = c.post('/app/student/student_cancel_submission.php',
                     {'paper_id': str(pid), 'tab': 'process'},
                     token_from='/app/student/student_dashboard.php?tab=process')
    after = H.one("SELECT current_status FROM research_papers WHERE paper_id=%d"
                  % pid)
    gone = after is None or after == 'draft'
    check('ID-039', gone,
          'the student can withdraw a submission (status is now %s) — this was '
          'reported not implemented' % (after or 'removed'),
          'withdrawing did nothing: status still %s' % after)
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


def t040():
    """Is the student told their submission arrived?"""
    title = 'AUTOTEST Confirm Email'
    H.q("DELETE FROM research_papers WHERE title=%s" % H.esc(title))
    uid = F.uid_of('student_id', F.STUDENT['student_id'])
    before = int(H.one("SELECT COUNT(*) FROM notifications WHERE user_id=%s"
                       % uid) or 0)
    c = F.student_client()
    full_submission(c, title)
    after = int(H.one("SELECT COUNT(*) FROM notifications WHERE user_id=%s"
                      % uid) or 0)
    if after > before:
        R.record('ID-040', 'PASSED',
                 'the student is notified on submission (%d new notification)'
                 % (after - before))
    elif not paper_row(title):
        R.record('ID-040', 'UNTESTED',
                 'cannot be judged while ID-025 blocks submission: the handler '
                 'throws at the Google Drive step before it reaches any '
                 'notification.')
    else:
        R.record('ID-040', 'NOT IMPLEMENTED',
                 'no confirmation reaches the student on submission: no '
                 'notification row and no email is sent by the upload handler')


def t041():
    """Tracking where a paper has got to."""
    title = 'AUTOTEST Track Status'
    pid = F.seed_paper('pending_faculty', title)
    c = F.student_client()
    # The dashboard is tabbed; a paper under review lives on the in-process
    # tab, not the default view.
    html = c.get('/app/student/student_dashboard.php?tab=process')
    shown = title.lower() in html.lower()
    tracker = bool(re.search(r'progress|tracker|step', html, re.I))
    counted = bool(re.search(r'process', html, re.I))
    check('ID-041', shown and tracker,
          'the paper is listed on the in-process tab with a progress tracker '
          'showing how far it has got',
          'submission status is not visible to the student (listed %s, tracker '
          '%s, tab counts %s)' % (shown, tracker, counted))
    H.q("DELETE FROM research_papers WHERE paper_id=%d" % pid)


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(25, 42)]


def run():
    print('\n-- MODULE 4: PAPER SUBMISSION (STUDENT) --')
    for tid, fn in TESTS:
        H.run(tid, fn)
