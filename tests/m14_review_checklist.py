# -*- coding: utf-8 -*-
"""MODULE 14: the review checklist is a list the adviser chooses and may edit.

The Research Adviser picks a ready-made list (IMRaD, Undergraduate Thesis, Capstone Project,
Feasibility Study, Blank) and may add, remove or rename sections for the one paper. What is kept
is the list as it was left, in paper_checklist.items_json, and the author reads the same list.

Run on its own:

    python m14_review_checklist.py            # everything
    python m14_review_checklist.py unit pages # or just the named steps

It builds the same AUTOTEST accounts as m10, through the app's own forms, and takes them down
afterwards. Nothing here uploads to Drive: papers are seeded straight into the database, because
the checklist is about the decision and not about the upload. Mail only ever reaches
@example.com addresses. The browser side (dropdown, edit mode, the posted fields) is checked by
driving a real Chrome; that script is not part of the repository, and the cases here read the same
facts from the server's answers.

Needs the column from scripts/migrations/run_checklist_items_migration.php. tests/m13_old_schema.py
covers a server that has not got it.
"""
import json, os, re, subprocess, sys

import harness as H
import fixtures as F
import m10_section_scope as M10
from harness import R, check

DESK = '/app/faculty/faculty_review_dashboard.php'
ADMIN_DESK = '/app/admin/admin_review_dashboard.php'
UNIT = os.path.join(H.HERE, '_checklist_unit.php')
COORDINATOR_ID = M10.COORDINATOR_ID
IMRAD5 = ['Introduction', 'Methodology', 'Results', 'Discussion', 'References']
CHAPTERS = ['Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 4', 'Chapter 5', 'References']


# ------------------------------------------------------------------ helpers
def seed(title, manuscript='IMRAD', ptype='journal', status='pending_faculty'):
    pid = F.seed_paper(status, title)
    H.q("UPDATE research_papers SET manuscript_type=%s, paper_type=%s WHERE paper_id=%d"
        % (H.esc(manuscript), H.esc(ptype), pid))
    return pid


def status_of(pid):
    return H.one("SELECT current_status FROM research_papers WHERE paper_id=%d" % pid)


def stored(pid):
    """The decoded items_json for a paper, {} when there is none, None when there is no row."""
    rows = H.q("SELECT items_json FROM paper_checklist WHERE paper_id=%d" % pid)
    if not rows:
        return None
    raw = rows[0][0]
    return json.loads(raw) if raw and raw != 'NULL' else {}


def n_rows(pid):
    return int(H.one("SELECT COUNT(*) FROM paper_checklist WHERE paper_id=%d" % pid) or 0)


def approve(client, pid, template, labels, ticked=(), feedback='AUTOTEST approved.'):
    """The post the editor makes: a list key, a label per row, and a box only for the ticked ones."""
    fields = {'paper_id': str(pid), 'action': 'approve', 'feedback': feedback, 'ck_template': template}
    for i, label in enumerate(labels):
        fields['ck_label[%d]' % (i + 1)] = label
        if i in ticked:
            fields['ck_ok[%d]' % (i + 1)] = '1'
    return client.post(DESK, fields, token_from=DESK)


def text_of(html):
    return M10.strip(html)


def editor_config(html):
    m = re.search(r'id="rvCk" data-ck="([^"]*)"', html)
    if not m:
        return None
    import html as h
    return json.loads(h.unescape(m.group(1)))


# -------------------------------------------------------------------- tests
def unit():
    p = subprocess.run([H.PHP, UNIT], capture_output=True)
    out = p.stdout.decode('utf-8', 'replace')
    m = re.search(r'SUMMARY passed=(\d+) failed=(\d+)', out)
    fails = [l for l in out.splitlines() if l.startswith('FAIL')]
    check('CK-01', p.returncode == 0 and m and m.group(2) == '0',
          'the helper unit tests all pass (%s cases)' % (m.group(1) if m else '?'),
          'unit failures: %s' % '; '.join(fails[:3]), out[-200:])


def pages():
    """What each reader is shown on the review page."""
    a = M10.adviser()
    imrad = seed('AUTOTEST Checklist IMRaD', 'IMRAD', 'journal')
    cap = seed('AUTOTEST Checklist Capstone', 'Full Manuscript', 'capstone')
    feas = seed('AUTOTEST Checklist Feasibility', 'IMRAD', 'project')

    html = a.get('/app/review_paper.php?id=%d' % imrad)
    cfg = editor_config(html)
    check('CK-02', cfg and [l['key'] for l in cfg['lists']] == ['imrad', 'undergrad_thesis', 'capstone', 'feasibility', 'blank']
          and cfg['canEdit'] is True and cfg['template'] == 'imrad',
          'the adviser gets the editor: five lists, editable, starting on IMRaD for an IMRaD paper',
          'config was %s' % (json.dumps(cfg)[:200] if cfg else None))
    check('CK-03', not re.search(r'name="(?:imrad_|full_)', html) and 'Full Manuscript</span>' not in html.split('Review Checklist', 1)[-1][:3000],
          'the eleven fixed boxes and the "Full Manuscript" heading are gone from the page',
          'old boxes still on the page')

    starts = {}
    for key, pid in (('cap', cap), ('feas', feas)):
        starts[key] = (editor_config(a.get('/app/review_paper.php?id=%d' % pid)) or {}).get('template')
    check('CK-04', starts == {'cap': 'capstone', 'feas': 'feasibility'},
          'a capstone filed as a full manuscript starts on Capstone Project, and a feasibility study on its own list',
          'started on %s' % starts)

    # The Coordinator reads the adviser's list; there is nothing for them to edit.
    coord = M10.minted(COORDINATOR_ID)
    approve(a, imrad, 'imrad', IMRAD5, ticked=(0, 1))
    chtml = coord.get('/app/review_paper.php?id=%d' % imrad)
    ctext = text_of(chtml.split('Review Checklist', 1)[-1][:6000])
    check('CK-05', editor_config(chtml) is None and 'ck-select' not in chtml
          and 'recorded' in ctext and 'Indicated' in ctext and 'Not indicated' in ctext,
          'the Coordinator sees the adviser\'s list as plain rows, with no editor',
          'coordinator page: %s' % ctext[:200])
    s = M10.student()
    check('CK-06', M10.student().get('/app/review_paper.php?id=%d' % imrad) is not None and editor_config(s.get('/app/review_paper.php?id=%d' % imrad)) is None,
          'a student does not get the editor', 'a student was shown the editor')


def approving():
    """The decision saves the list as it was left, and the author reads it."""
    a = M10.adviser()
    p1 = seed('AUTOTEST Checklist Approve Custom', 'Full Manuscript', 'capstone')
    labels = ['Chapter 1', 'Chapter 2', 'Appendix: user manual', 'References']
    approve(a, p1, 'capstone', labels, ticked=(0, 2))
    d = stored(p1)
    check('CK-07', status_of(p1) == 'pending_admin' and d and d['template'] == 'capstone' and d['edited'] is True
          and [i['label'] for i in d['items']] == labels and [i['ok'] for i in d['items']] == [True, False, True, False],
          'an approval with an edited list moves the paper on and keeps the list as left, ticks and all',
          'status %s, stored %s' % (status_of(p1), d))
    student_page = text_of(M10.student().get('/app/student/paper_details.php?id=%d' % p1)
                           .split('Review Checklist', 1)[-1][:6000])
    check('CK-08', 'Capstone Project Sections' in student_page and 'Appendix: user manual' in student_page
          and 'adjusted for this paper' in student_page and 'Chapter 4' not in student_page,
          'the author sees that list, named, with the added section and without the ones removed',
          'student page said: %s' % student_page[:260])

    # every ready-made list, unedited
    for key, items in (('imrad', IMRAD5), ('undergrad_thesis', CHAPTERS), ('feasibility', None)):
        pid = seed('AUTOTEST Checklist List %s' % key, 'IMRAD', 'journal')
        if items is None:
            html = a.get('/app/review_paper.php?id=%d' % pid)
            cfg = editor_config(html)
            items = [l for l in cfg['lists'] if l['key'] == 'feasibility'][0]['items']
        approve(a, pid, key, items, ticked=range(len(items)))
        d = stored(pid)
        check('CK-09-' + key, d and d['template'] == key and d['edited'] is False and len(d['items']) == len(items)
              and all(i['ok'] for i in d['items']),
              'the %s list is kept as it stands, all ticked, and not marked as edited' % key,
              'stored %s' % d)


def old_form():
    """What posts the eleven fixed fields still works, and is read as it always was."""
    a = M10.adviser()
    pid = seed('AUTOTEST Checklist Old Form', 'Full Manuscript', 'undergrad_thesis')
    fields = {'paper_id': str(pid), 'action': 'approve', 'feedback': 'AUTOTEST old form.',
              'imrad_intro': 'on', 'imrad_result': 'on', 'full_ch1': 'on', 'full_ch3': 'on'}
    a.post(DESK, fields, token_from=DESK)
    flags = H.q("SELECT imrad_intro, imrad_method, imrad_result, full_ch1, full_ch2, full_ch3 FROM paper_checklist WHERE paper_id=%d" % pid)
    check('CK-10', status_of(pid) == 'pending_admin' and flags and flags[0] == ['1', '0', '1', '1', '0', '1'] and stored(pid) == {},
          'a post of the old eleven fields is saved in the old columns, with no list',
          'status %s, flags %s' % (status_of(pid), flags))
    text = text_of(M10.student().get('/app/student/paper_details.php?id=%d' % pid).split('Review Checklist', 1)[-1][:6000])
    check('CK-11', 'Undergraduate Thesis Sections' in text and 'IMRaD Sections' in text and 'Full Manuscript' not in text.split('Files')[0],
          'such a row reads as before, its chapters headed by the paper\'s type and never "Full Manuscript"',
          'student page said: %s' % text[:260])


def refusals():
    """A list that cannot be kept is refused in words, and the paper stays where it was."""
    a = M10.adviser()
    cases = [
        ('too many sections', ['Section %d' % i for i in range(1, 22)], 'too long'),
        ('nothing left', ['', '   '], 'at least one'),
    ]
    for name, labels, said in cases:
        pid = seed('AUTOTEST Checklist Refuse %s' % name.replace(' ', ''))
        s, body = approve(a, pid, 'blank', labels)
        words = text_of(body)
        check('CK-12-' + name.replace(' ', '-'), status_of(pid) == 'pending_faculty' and n_rows(pid) == 0 and said in words,
              'refused (%s): the paper stays with the adviser and the reason is said' % name,
              'status %s, rows %s, page: %s' % (status_of(pid), n_rows(pid), words[:160]))

    pid = seed('AUTOTEST Checklist Hostile')
    approve(a, pid, '<script>alert(1)</script>',
            ['<b>Bold</b> section', 'x' * 500, 'Same', 'same', '<img src=x onerror=alert(1)>'], ticked=(0,))
    d = stored(pid) or {}
    labels = [i['label'] for i in d.get('items', [])]
    # Five posted: a tagged name, a 500-character one, "Same" twice (any case), and a bare tag that
    # is nothing once the tag is gone. Three remain.
    check('CK-13', status_of(pid) == 'pending_admin' and d.get('template') == 'blank'
          and labels == ['Bold section', 'x' * 80, 'Same'] and [i['ok'] for i in d['items']] == [True, False, False],
          'hostile input is cleaned: tags gone, a long name cut, a repeat dropped, an unknown list becomes blank',
          'stored %s' % labels)


def second_round():
    """A paper approved, returned, revised and approved again replaces its checklist.

    The save used to be a plain INSERT against a unique key on paper_id: silent here (the old
    ticks stayed), and an exception, so the error page, from PHP 8.1, which is what staging runs.
    tests/m13_old_schema.py repeats this on the strict server."""
    a = M10.adviser()
    pid = seed('AUTOTEST Checklist Second Round', 'IMRAD', 'journal')
    approve(a, pid, 'imrad', IMRAD5, ticked=(0,))
    first = stored(pid)
    H.q("UPDATE research_papers SET current_status='pending_faculty' WHERE paper_id=%d" % pid)   # returned, revised, sent again
    approve(a, pid, 'feasibility', ['Executive Summary', 'Risks'], ticked=(0, 1))
    second = stored(pid)
    check('CK-14', status_of(pid) == 'pending_admin' and n_rows(pid) == 1 and second
          and second['template'] == 'feasibility' and [i['label'] for i in second['items']] == ['Executive Summary', 'Risks']
          and first['template'] == 'imrad',
          'approving the same paper a second time replaces its checklist, in one row',
          'rows %s, first %s, second %s' % (n_rows(pid), first, second))


def desk_window():
    """The approve window on the desk carries the same editor, started from each paper's own list."""
    a = M10.adviser()
    seed('AUTOTEST Checklist Desk A', 'IMRAD', 'journal')
    seed('AUTOTEST Checklist Desk B', 'Full Manuscript', 'capstone')
    seed('AUTOTEST Checklist Desk C', 'IMRAD', 'project')
    html = a.get(DESK + '?tab=queue')
    defaults = dict(re.findall(r'data-title="AUTOTEST Checklist Desk (\w)"[^>]*?\s+data-ck-default="(\w+)"', html, re.S))
    check('CK-15', defaults == {'A': 'imrad', 'B': 'capstone', 'C': 'feasibility'},
          'each card\'s buttons name the list that suits their paper',
          'defaults were %s' % defaults)
    check('CK-16', 'id="rcCk"' in html and 'data-ck="' in html and not re.search(r'name="(?:imrad_|full_)', html),
          'the approve window holds the editor and none of the old boxes', 'the window still has the old boxes')
    coord = M10.minted(COORDINATOR_ID)
    chtml = coord.get(ADMIN_DESK)
    check('CK-17', 'id="rcCk"' not in chtml and 'data-ck-default' not in chtml,
          'the Coordinator\'s desk has no checklist in its window', 'the Coordinator desk carries the editor')


def diagnostics():
    a = M10.adviser()
    words = text_of(a.get('/app/student/upload_diagnostics.php'))
    check('CK-18', 'checklist_items' not in words and ('schema gaps: none' in words or 'No gaps' in words),
          'the diagnostics page reports no gap for the checklist column', 'it said: %s' % words[words.find('Database'):][:200])


def paper_types():
    """Journal Article and Conference Paper are no longer offered, on the form or by the handler."""
    s = M10.student()
    html = s.get(M10.UPLOAD)
    m = re.search(r'<select[^>]*name="paper_type"[^>]*>(.*?)</select>', html, re.S)
    options = re.findall(r'<option value="([^"]*)"[^>]*>([^<]*)</option>', m.group(1)) if m else []
    offered = [(v, l.strip()) for v, l in options if v]
    check('CK-19', offered == [('capstone', 'Capstone Project'), ('undergrad_thesis', 'Undergraduate Thesis'),
                                 ('project', 'Feasibility Study')],
          'the upload form offers a Capstone Project, an Undergraduate Thesis and a Feasibility Study, and nothing else',
          'offered %s' % offered)
    for code in ('journal', 'conference'):
        title = 'AUTOTEST Removed Type %s' % code
        status, reply = M10.submit(s, title, paper_type=code)
        made = H.one("SELECT COUNT(*) FROM research_papers WHERE title=%s" % H.esc(title))
        check('CK-20-' + code, not reply.get('success') and made == '0',
              'a hand-made post of the removed type "%s" is refused and files nothing' % code,
              'status %s, reply %s, rows %s' % (status, reply, made))


STEPS = [('unit', unit), ('paper_types', paper_types), ('pages', pages), ('approving', approving), ('old_form', old_form),
         ('refusals', refusals), ('second_round', second_round), ('desk_window', desk_window),
         ('diagnostics', diagnostics)]


def main():
    only = tuple(sys.argv[1:])
    M10.setup()
    try:
        print('\n== MODULE 14: the review checklist ==')
        for name, fn in STEPS:
            if not only or name in only:
                H.run('CK-' + name, fn)
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            F.teardown()
        finally:
            for f in M10.SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
