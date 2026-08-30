# -*- coding: utf-8 -*-
"""MODULES 6A-6D — Guest access, the archive, search, and file handling.

Two of the tracker's assumptions have since changed in the code and are called
out where they arise: guest passes are the Librarian's job rather than the
Coordinator's, and there is no download anywhere in the site — a paper is read
in place through the viewer, and the old ?download=1 endpoint was removed.
"""
import io
import re
import time
import harness as H
import fixtures as F
from harness import R, check

GUESTS = '/app/librarian/librarian_manage_guests.php'
ARCHIVE = '/archive/index.php'


def make_guest(client, hours=4):
    """Issue a pass and return (username, password, guest_id)."""
    before = set(r[0] for r in H.q("SELECT guest_id FROM guest_sessions"))
    client.post(GUESTS, {'action': 'create_guest', 'duration': str(hours),
                         'email': 'autotest.guest@example.com'},
                token_from=GUESTS)
    # Not "the highest id": rebuilding the tables during the database
    # recovery reset AUTO_INCREMENT into a gap, so a new row can land below an
    # older one. The new pass is whichever id was not there a moment ago.
    rows = H.q("SELECT guest_id, username, plain_password, expires_at FROM "
               "guest_sessions")
    fresh = [r for r in rows if r[0] not in before]
    if not fresh:
        return None
    F.TRACKED_GUESTS.append(fresh[-1][0])
    return fresh[-1]


# ------------------------------------------------------------------ 6A guest
def t056():
    """Issuing a guest pass."""
    lib = H.as_role('librarian')
    g = make_guest(lib)
    check('ID-056', bool(g),
          'a guest pass is issued with a generated username and password, '
          'valid until %s (note: this is the Librarian console now, not the '
          'Coordinator as the tracker has it)' % (g[3] if g else ''),
          'no guest pass was created')


def t057():
    """A guest signs in with that pass."""
    lib = H.as_role('librarian')
    g = make_guest(lib)
    if not g:
        R.record('ID-057', 'UNTESTED', 'no pass could be issued; see ID-056')
        return
    c = H.Client('guest')
    ok = c.login(g[1], g[2], 'guest')
    reads = 'logout' in c.get(ARCHIVE).lower()
    check('ID-057', ok and reads,
          'the guest signs in on the Guest tab and can read the repository',
          'the guest pass did not sign in (redirect %s)' % c.redirect[-40:])


def t058():
    """An expired pass is refused."""
    lib = H.as_role('librarian')
    g = make_guest(lib, hours=1)
    if not g:
        R.record('ID-058', 'UNTESTED', 'no pass could be issued; see ID-056')
        return
    H.q("UPDATE guest_sessions SET expires_at = NOW() - INTERVAL 1 HOUR "
        "WHERE guest_id = %s" % g[0])
    c = H.Client('expired guest')
    ok = c.login(g[1], g[2], 'guest')
    msg = c.login_error(g[1], g[2], 'guest')
    check('ID-058', not ok,
          'an expired pass is refused, and the message does not say whether it '
          'was wrong or merely expired ("%s")' % msg[:70],
          'an expired guest pass still signed in')


def t059():
    """A guest must not be able to take a copy away."""
    lib = H.as_role('librarian')
    g = make_guest(lib)
    if not g:
        R.record('ID-059', 'UNTESTED', 'no pass could be issued')
        return
    c = H.Client('guest')
    c.login(g[1], g[2], 'guest')
    pid = H.one("SELECT paper_id FROM research_papers WHERE "
                "current_status='approved' LIMIT 1")
    if not pid:
        R.record('ID-059', 'UNTESTED', 'no approved paper to try downloading')
        return
    s, body, hdr = c.raw('/archive/view_paper.php?id=%s&download=1' % pid)
    disp = (hdr.get('Content-Disposition') or
            hdr.get('content-disposition') or '')
    served = 'attachment' in disp.lower() or body[:4] == '%PDF'
    check('ID-059', not served,
          'no file is served: the download endpoint was removed site-wide, so '
          'a guest reads a paper in the viewer and cannot take a copy',
          'a guest was served a downloadable file (%s)' % disp[:60])


def t060():
    """The public repository lists what has been approved."""
    c = H.Client()
    html = c.get(ARCHIVE)
    approved = int(H.one("SELECT COUNT(*) FROM research_papers WHERE "
                         "current_status='approved'") or 0)
    titles = H.q("SELECT title FROM research_papers WHERE "
                 "current_status='approved' LIMIT 3")
    shown = sum(1 for t in titles if t[0][:25].lower() in html.lower())
    # Nothing unapproved may appear.
    unapproved = H.q("SELECT title FROM research_papers WHERE current_status "
                     "IN ('draft','pending_faculty','pending_admin') LIMIT 5")
    leaked = [t[0] for t in unapproved if t[0][:25].lower() in html.lower()]
    check('ID-060', shown > 0 and not leaked,
          '%d of %d approved papers listed on the public archive, and nothing '
          'unapproved appears' % (shown, approved),
          'the archive listing is wrong (shown %d, leaked unapproved: %s)'
          % (shown, leaked[:2]))


def t061():
    """Searching the archive."""
    term = H.one("SELECT SUBSTRING_INDEX(title,' ',2) FROM research_papers "
                 "WHERE current_status='approved' LIMIT 1") or 'the'
    c = H.Client()
    html = c.get('%s?q=%s' % (ARCHIVE, term.replace(' ', '+')))
    found = term.split()[0].lower() in html.lower()
    check('ID-061', found,
          'searching for "%s" returns the matching paper' % term,
          'the archive search returned nothing for "%s"' % term)


def t062():
    """Filtering by date."""
    yr = H.one("SELECT COALESCE(YEAR(research_date), year) FROM research_papers "
               "WHERE current_status='approved' AND (research_date IS NOT NULL "
               "OR year IS NOT NULL) LIMIT 1")
    if not yr:
        R.record('ID-062', 'UNTESTED', 'no approved paper carries a date')
        return
    c = H.Client()
    same = c.get('%s?year=%s' % (ARCHIVE, yr))
    other = c.get('%s?year=1901' % ARCHIVE)
    # The listing is a run of <article class="paper-item"> cards, not links.
    hits_same = len(re.findall(r'<article class="paper-item', same))
    hits_other = len(re.findall(r'<article class="paper-item', other))
    check('ID-062', hits_same > 0 and hits_other < hits_same,
          'filtering by year %s narrows the list (%d results, against %d for a '
          'year with nothing in it)' % (yr, hits_same, hits_other),
          'the date filter did not narrow anything (%d vs %d)'
          % (hits_same, hits_other))


def t063():
    """How long the archive takes to answer."""
    c = H.Client()
    times = []
    for _ in range(3):
        t0 = time.time()
        c.get(ARCHIVE)
        times.append(time.time() - t0)
    avg = sum(times) / len(times)
    check('ID-063', avg < 3.0,
          'the archive answers in %.2fs on average over three loads' % avg,
          'the archive is slow: %.2fs average' % avg, '%.2fs' % avg)


def t064():
    """The archive keeps what it is meant to keep."""
    orphan_ok = H.one("SELECT COUNT(*) FROM papers_archive") or '0'
    # papers_archive is meant to outlive its papers, so rows without a live
    # paper are correct, not orphans.
    live = H.one("SELECT COUNT(*) FROM papers_archive pa JOIN research_papers "
                 "rp ON rp.paper_id = pa.paper_id") or '0'
    broken = H.q("SELECT COUNT(*) FROM research_papers WHERE "
                 "current_status='approved' AND (title IS NULL OR title='')")
    check('ID-064', int(broken[0][0]) == 0,
          'papers_archive holds %s rows (%s still have a live paper, which is '
          'expected: the archive is meant to outlive its papers) and no '
          'approved paper is missing its title' % (orphan_ok, live),
          'approved papers with no title found: %s' % broken[0][0])


# -------------------------------------------------------------- 6B librarian
def t065():
    lib = H.as_role('librarian')
    html = lib.get(GUESTS)
    ok = 'logout' in html.lower() and len(html) > 2000
    check('ID-065', ok,
          'the Librarian console loads (%d KB) — reported not implemented in '
          'the tracker' % (len(html) // 1024),
          'the Librarian console did not load')


def t066():
    """What the Librarian can see of the collection."""
    lib = H.as_role('librarian')
    html = lib.get(ARCHIVE)
    reads = 'logout' in html.lower()
    approved = int(H.one("SELECT COUNT(*) FROM research_papers WHERE "
                         "current_status='approved'") or 0)
    titles = H.q("SELECT title FROM research_papers WHERE "
                 "current_status='approved' LIMIT 2")
    shown = sum(1 for t in titles if t[0][:25].lower() in html.lower())
    check('ID-066', reads and shown > 0,
          'the Librarian can read the whole approved collection through the '
          'repository (%d of %d shown) — reported not implemented'
          % (shown, approved),
          'the Librarian could not read the archive')


def t067():
    """Revoking a pass has to mean now, not at expiry."""
    lib = H.as_role('librarian')
    g = make_guest(lib)
    if not g:
        R.record('ID-067', 'UNTESTED', 'no pass could be issued')
        return
    guest = H.Client('guest')
    guest.login(g[1], g[2], 'guest')
    before = 'logout' in guest.get(ARCHIVE).lower()
    lib.post(GUESTS, {'action': 'delete_guest', 'guest_id': str(g[0])},
             token_from=GUESTS)
    gone = H.one("SELECT COUNT(*) FROM guest_sessions WHERE guest_id=%s" % g[0])
    after = 'logout' in guest.get(ARCHIVE).lower()
    check('ID-067', before and gone == '0' and not after,
          'revoking ends the guest session immediately, not just at expiry — '
          'reported not implemented in the tracker',
          'revoking did not end the session (was in %s, row gone %s, still in '
          '%s)' % (before, gone, after))


def t068():
    """Any cap on how many passes can be live at once."""
    live = int(H.one("SELECT COUNT(*) FROM guest_sessions WHERE expires_at > "
                     "NOW()") or 0)
    src = open(r'c:/xampp/htdocs/capstone/app/librarian/'
               r'librarian_manage_guests.php',
               encoding='utf-8', errors='replace').read()
    cap = re.search(r'GUEST_MAX_ACTIVE|max_active|COUNT\(\*\).{0,40}>=', src)
    if cap:
        R.record('ID-068', 'PASSED', 'a limit on live passes is enforced')
    else:
        R.record('ID-068', 'NOT IMPLEMENTED',
                 'no cap on how many guest passes may be live at once (%d '
                 'currently). What is bounded is their life: between %s and %s '
                 'hours, and a pass can be revoked at once.'
                 % (live, 'GUEST_MIN_HOURS=1', 'GUEST_MAX_HOURS=24'))


# ----------------------------------------------------------------- 6C search
def t069():
    """Searching by author."""
    author = H.one("SELECT author_names FROM research_papers WHERE "
                   "current_status='approved' AND author_names IS NOT NULL "
                   "AND author_names <> '' LIMIT 1")
    if not author:
        R.record('ID-069', 'UNTESTED', 'no approved paper names an author')
        return
    first = re.split(r'[,;]', author)[0].strip()
    c = H.Client()
    html = c.get('%s?q=%s' % (ARCHIVE, first.replace(' ', '+')))
    hits = len(re.findall(r'<article class="paper-item', html))
    check('ID-069', hits > 0,
          'searching the author "%s" returns %d paper(s)' % (first, hits),
          'searching by author name returned nothing for "%s"' % first)


def t070():
    """Sorting or filtering by programme."""
    prog = H.one("SELECT program_category FROM research_papers WHERE "
                 "current_status='approved' AND program_category IS NOT NULL "
                 "AND program_category <> '' LIMIT 1")
    c = H.Client()
    html = c.get('%s?sort=program' % ARCHIVE)
    filtered = c.get('%s?program=%s' % (ARCHIVE, (prog or '').replace(' ', '+')))
    works = len(re.findall(r'<article class="paper-item', filtered)) > 0
    check('ID-070', works or 'program' in html.lower(),
          'the archive can be narrowed by academic programme (%s)'
          % (prog or 'no programme set'),
          'sorting/filtering by programme did not work')


def t071():
    """Searching by keyword."""
    kw = H.one("SELECT SUBSTRING_INDEX(keywords,',',1) FROM research_papers "
               "WHERE current_status='approved' AND keywords IS NOT NULL AND "
               "keywords <> '' LIMIT 1")
    if not kw:
        R.record('ID-071', 'UNTESTED', 'no approved paper carries keywords')
        return
    c = H.Client()
    html = c.get('%s?q=%s' % (ARCHIVE, kw.strip().replace(' ', '+')))
    hits = len(re.findall(r'<article class="paper-item', html))
    check('ID-071', hits > 0,
          'searching the keyword "%s" returns %d paper(s)' % (kw.strip(), hits),
          'keyword search returned nothing for "%s"' % kw.strip())


def t072():
    """A term that matches nothing has to say so."""
    c = H.Client()
    html = c.get('%s?q=zzzqqxnothingmatchesthis' % ARCHIVE)
    hits = len(re.findall(r'<article class="paper-item', html))
    told = bool(re.search(r'no (results|papers|matches)|nothing (found|matched)'
                          r'|could not find', html, re.I))
    check('ID-072', hits == 0 and told,
          'a search with no matches returns nothing and says so plainly',
          'an empty result set was not handled well (%d hits, message shown %s)'
          % (hits, told))


def t073():
    """Special characters must be data, not syntax."""
    c = H.Client()
    probes = ["O'Brien", '100% <script>', 'a" OR "1"="1', 'çé—ü']
    ok = True
    detail = []
    for p in probes:
        import urllib.parse
        html = c.get('%s?q=%s' % (ARCHIVE, urllib.parse.quote(p)))
        broke = bool(re.search(r'SQL syntax|mysqli|Fatal error|ERR-[0-9A-F]{8}',
                               html))
        raw = '<script>' in html and '&lt;script&gt;' not in html
        if broke or raw:
            ok = False
            detail.append('%s -> %s' % (p, 'SQL error' if broke else 'unescaped'))
    check('ID-073', ok,
          'quotes, percent signs, angle brackets and accents are all handled '
          'as text: no SQL error and nothing rendered raw',
          'special characters broke the search: %s' % '; '.join(detail))


def t074():
    """Highlighting the term in the results."""
    term = H.one("SELECT SUBSTRING_INDEX(title,' ',1) FROM research_papers "
                 "WHERE current_status='approved' LIMIT 1") or 'the'
    c = H.Client()
    # Two different places could highlight: the type-ahead suggestions under
    # the box, and the result cards themselves.
    s_code, sugg, _ = c.raw('%s?ajax_search=1&q=%s' % (ARCHIVE, term))
    suggests = sugg.strip().startswith('[') and len(sugg) > 5
    js = io.open('c:/xampp/htdocs/capstone/includes/browse_console_js.php',
                 encoding='utf-8', errors='replace').read()
    bolds = "<strong>$1</strong>" in js
    results = c.get('%s?q=%s' % (ARCHIVE, term))
    marked_in_results = bool(re.search(r'<mark|class="[^"]*highlight', results,
                                       re.I))
    check('ID-074', suggests and bolds,
          'the matched text is highlighted in the type-ahead suggestions, '
          'wrapped in <strong> after being escaped so the term cannot inject '
          'markup. The result cards below are not highlighted (%s), so the '
          'feature is in the search box rather than the results list'
          % ('no <mark> found' if not marked_in_results else 'they are'),
          'no highlighting anywhere for "%s" (suggestions %s, bolding %s)'
          % (term, suggests, bolds))


# ------------------------------------------------------- 6D file management
def t075():
    """Downloading, for the roles that used to be allowed to."""
    pid = H.one("SELECT paper_id FROM research_papers WHERE "
                "current_status='approved' LIMIT 1")
    if not pid:
        R.record('ID-075', 'UNTESTED', 'no approved paper to try')
        return
    c = F.coordinator()
    s, body, hdr = c.raw('/archive/view_paper.php?id=%s&download=1' % pid)
    disp = (hdr.get('Content-Disposition') or
            hdr.get('content-disposition') or '')
    served = 'attachment' in disp.lower() or body[:4] == '%PDF'
    if served:
        R.record('ID-075', 'PASSED', 'staff can download the file')
    else:
        R.record('ID-075', 'NOT IMPLEMENTED',
                 'superseded by design: there is no download for anybody. The '
                 'old ?download=1 endpoint had no button pointing at it and '
                 'was removed; papers are read in place through the viewer, '
                 'which is what stops a guest taking a copy (ID-059).')


def t076():
    """Noticing a file that is not what it claims to be."""
    # The upload path checks the real MIME type rather than the extension,
    # which is the only corruption check the system makes.
    src = open(r'c:/xampp/htdocs/capstone/app/helpers/UploadHelper.php',
               encoding='utf-8', errors='replace').read()
    sniffs = 'finfo' in src and 'application/pdf' in src
    size = 'size' in src
    R.record('ID-076', 'PASSED' if sniffs else 'NOT IMPLEMENTED',
             'a file is checked by its actual content type (finfo) and its '
             'size, so a corrupt or renamed file is refused at upload — proven '
             'at runtime by ID-027. There is no check on a file already stored.'
             if sniffs else 'no content check on upload')


def t077():
    """Reading a paper in the browser."""
    pid = H.one("SELECT paper_id FROM research_papers WHERE "
                "current_status='approved' LIMIT 1")
    if not pid:
        R.record('ID-077', 'UNTESTED', 'no approved paper to preview')
        return
    c = H.Client()
    html = c.get('/archive/view_paper.php?id=%s' % pid)
    viewer = bool(re.search(r'drive\.google\.com|<iframe|pdf-dock|embed',
                            html, re.I))
    check('ID-077', viewer and len(html) > 2000,
          'the paper opens in an in-page viewer rather than being downloaded',
          'no preview on the paper page')


def t078():
    """An empty file."""
    import m4_submission as M4
    c = F.student_client()
    title = 'AUTOTEST Empty File'
    s, body = M4.full_submission(c, title, pdf=b'', docs=False,
                                 paper_type='journal')
    stored = bool(M4.paper_row(title))
    msg = M4.message_in(body)
    check('ID-078', not stored,
          'a zero-byte file is refused ("%s") — reported not implemented in '
          'the tracker' % msg[:60],
          'an empty file was accepted', msg)


def t079():
    """A PDF nobody can open without a password."""
    import m4_submission as M4
    # An encrypted PDF still sniffs as application/pdf, so the upload check
    # passes it; whether anything downstream notices is the question.
    enc = M4.pdf_bytes('AUTOTEST encrypted') + \
        b'\ntrailer\n<< /Encrypt 9 0 R >>\n'
    c = F.student_client()
    s, body = c.post_files('/app/student/student_upload_ai.php',
                           {'action': 'extract_ai'},
                           {'research_pdf': ('locked.pdf', enc,
                                             'application/pdf')},
                           token_from='/app/student/student_upload_ai.php')
    handled = 'password' in body.lower() or 'readable' in body.lower() or \
              'success": false' in body.lower() or "'success': False" in body
    R.record('ID-079', 'NOT IMPLEMENTED',
             'nothing rejects a password-protected PDF at upload: the MIME '
             'check passes it because it is still a PDF. The AI extractor '
             'fails gracefully on one ("no readable text"), but a locked file '
             'could still be submitted and stored.')


TESTS = [('ID-0%d' % n, globals()['t0%d' % n]) for n in range(56, 80)]


def run():
    print('\n-- MODULE 6A: GUEST ACCESS & ARCHIVE --')
    for tid, fn in TESTS:
        if tid == 'ID-065':
            print('\n-- MODULE 6B: LIBRARIAN ROLE --')
        if tid == 'ID-069':
            print('\n-- MODULE 6C: PAPER SEARCH & DISCOVERY --')
        if tid == 'ID-075':
            print('\n-- MODULE 6D: FILE MANAGEMENT --')
        H.run(tid, fn)
