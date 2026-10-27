# -*- coding: utf-8 -*-
"""MODULE 15: "Other" for the paper type and the manuscript type.

Step 1 of the upload ends both lists in Other, the way a Google Form does: the
student picks it and types what their paper is. The typed name is stored in the
same VARCHAR columns ("other:Case Study", "Other: Policy Brief") and shown
everywhere the type is shown. Other as a manuscript type stands alone: one PDF,
named after what was typed.

    python m15_other_types.py            every step
    python m15_other_types.py drafts     only the named steps (unit, page, drafts,
                                         refusals, submission)

It builds the AUTOTEST accounts through the app's own forms (tests/m10's setup),
makes one real submission to Google Drive, and removes the file, any empty
AUTOTEST folder and the accounts afterwards. Mail only reaches @example.com.
"""
import json, os, re, sys, urllib.parse

import harness as H
import fixtures as F
import m4_submission as M4
import m10_section_scope as M10
import m14_review_checklist as M14
from harness import R, check

UPLOAD = M10.UPLOAD
UNIT = os.path.join(H.HERE, '_checklist_unit.php')
T_DRAFT = 'AUTOTEST Other Types Draft'
T_PAPER = 'AUTOTEST Other Types Paper'
TYPE_NAME = 'Case Study'
MS_NAME = 'Policy Brief'
STATE = {}


def fields(title, **extra):
    """What step 1 to 4 post for a paper whose type and manuscript are both Other."""
    f = {
        'action': 'upload_paper', 'paper_type': 'other', 'paper_type_other': TYPE_NAME,
        'research_type': 'qualitative',
        'manuscript_type[]': ['Other'], 'manuscript_type_other': MS_NAME,
        'title': title, 'authors': F.STUDENT['full_name'],
        'program_category': F.STUDENT['program'],
        'research_date': '2026-03-15', 'year': '2026',
        'keywords': 'autotest, automation, suite, testing, repository',
        'section_scope': 'abstract', 'abstract': '<p>AUTOTEST abstract for the Other types suite.</p>',
    }
    f.update(extra)
    return f


def submit(client, title, files=None, **extra):
    s, body = client.post_files(UPLOAD, fields(title, **extra),
                                files if files is not None else
                                {'pdf_other': ('autotest.pdf', M4.pdf_bytes(), 'application/pdf')},
                                token_from=UPLOAD)
    try:
        return s, json.loads(body)
    except ValueError:
        return s, {'raw': body[:300]}


def save_draft(client, fields):
    """A background draft save, as the page makes it, with a list sent as a list.

    tests/m10's ajax() encodes without doseq, which turns ['IMRAD', 'Other'] into one
    string; the page sends one field per ticked box."""
    data = dict(fields, _token=client.token(UPLOAD))
    body = urllib.parse.urlencode(data, doseq=True).encode()
    s, text, _ = client.raw(UPLOAD, raw_body=body, headers={
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'})
    try:
        return s, json.loads(text)
    except ValueError:
        return s, {'raw': text[:300]}


def row(title):
    r = H.q("SELECT paper_id, paper_type, manuscript_type, current_status FROM research_papers "
            "WHERE title=%s ORDER BY paper_id DESC LIMIT 1" % H.esc(title))
    return r[0] if r else None


# -------------------------------------------------------------------- tests
def unit():
    code, out = M10.php(UNIT)
    m = re.search(r'SUMMARY passed=(\d+) failed=(\d+)', out)
    other = [l for l in out.splitlines() if l.startswith(('PASS other', 'FAIL other'))]
    fails = [l for l in out.splitlines() if l.startswith('FAIL')]
    check('OT-01', code == 0 and m and m.group(2) == '0' and len(other) >= 20,
          'the helper unit cases pass (%s, %d of them about Other)' % (m.group(1) if m else '?', len(other)),
          'failures: %s' % '; '.join(fails[:3]))


def page():
    html = M10.student().get(UPLOAD)
    sel = re.search(r'<select[^>]*name="paper_type"[^>]*>(.*?)</select>', html, re.S)
    opts = re.findall(r'<option value="([^"]*)"[^>]*>([^<]*)</option>', sel.group(1)) if sel else []
    check('OT-02', [o for o in opts if o[0]][-1:] == [('other', 'Other')] and 'id="paperTypeOther"' in html
          and 'name="paper_type_other"' in html,
          'the paper type dropdown ends in Other, with a box to type the type in',
          'options %s' % opts)
    check('OT-03', 'id="mt_other"' in html and 'name="manuscript_type_other"' in html
          and 'data-format="other"' in html and 'name="pdf_other"' in html,
          'the manuscript type has an Other box with a field beside it, and a PDF box of its own',
          'markup missing')


def drafts():
    c = M10.student()
    s, j = save_draft(c, {'action': 'save_draft', 'title': T_DRAFT, 'authors': F.STUDENT['full_name'],
                        'program_category': F.STUDENT['program'], 'year': '2026',
                        'paper_type': 'other', 'paper_type_other': '  Case   Study ',
                        'manuscript_type[]': ['IMRAD', 'Other'], 'manuscript_type_other': 'Policy, Brief'})
    r = row(T_DRAFT)
    check('OT-04', j.get('success') and r and r[1] == 'other:Case Study' and r[2] == 'Other: Policy Brief',
          'a draft keeps both typed names, cleaned, and Other alone of the manuscript types',
          'reply %s, row %s' % (j, r))
    html = c.get(UPLOAD + '?draft=%s' % (r[0] if r else 0))
    check('OT-05', '"paper_type":"other"' in html and '"paper_type_other":"Case Study"' in html
          and '"manuscript_type":"Other"' in html and '"manuscript_type_other":"Policy Brief"' in html,
          'reopening it hands both back as the form posts them (the choice, and the name in its box)',
          'resume payload lacks them')
    s, j = save_draft(c, {'action': 'save_draft', 'title': T_DRAFT + ' unnamed', 'authors': F.STUDENT['full_name'],
                        'program_category': F.STUDENT['program'], 'year': '2026',
                        'paper_type': 'other', 'manuscript_type[]': ['Other']})
    r = row(T_DRAFT + ' unnamed')
    check('OT-06', j.get('success') and r and r[1] == 'other:' and r[2] == 'Other',
          'a draft with Other chosen and nothing typed yet is kept as it stands',
          'reply %s, row %s' % (j, r))


def refusals():
    c = M10.student()
    cases = [
        ('OT-07', 'AUTOTEST Other R1', {'paper_type_other': '   '}, None, 'Please type your paper type',
         'Other with no type typed is refused, in words'),
        ('OT-08', 'AUTOTEST Other R2', {'manuscript_type_other': ''}, None, 'what your manuscript is called',
         'Other with no manuscript name is refused, in words'),
        ('OT-09', 'AUTOTEST Other R3', {}, {}, 'Please attach your Policy Brief PDF',
         'no file for it is refused, naming the file in the student\'s own words'),
        ('OT-10', 'AUTOTEST Other R4', {}, {'pdf_other': ('a.pdf', M4.pdf_bytes(), 'application/pdf'),
                                          'pdf_full': ('b.pdf', M4.pdf_bytes(), 'application/pdf')},
         'manuscript type is not ticked', 'a Full Manuscript file sent with Other is refused, not stored'),
        ('OT-11', 'AUTOTEST Other R5', {'paper_type': 'journal'}, None, None,
         'a retired paper type is still refused'),
    ]
    for cid, title, extra, files, words, what in cases:
        s, j = submit(c, title, files=files, **extra)
        msg = j.get('message') or ''
        ok = not j.get('success') and row(title) is None and (words is None or words in msg)
        check(cid, ok, what + (' ("%s")' % msg if msg else ''), 'HTTP %s %s' % (s, j))


def submission():
    c = M10.student()
    s, j = submit(c, T_PAPER)
    r = row(T_PAPER)
    if not (j.get('success') and r):
        check('OT-12', False, '', 'the submission did not go through: HTTP %s %s' % (s, j))
        return
    pid = r[0]
    STATE['pid'] = pid
    fmts = H.q("SELECT format FROM paper_manuscripts WHERE paper_id=%s" % pid)
    check('OT-12', r[1] == 'other:Case Study' and r[2] == 'Other: Policy Brief' and r[3] == 'pending_faculty'
          and fmts == [['other']],
          'a paper typed as Other and handed in as Other is filed, with its one file, and goes to the adviser '
          'without the supporting documents (they stay optional)',
          'row %s, files %s' % (r, fmts))

    a = M10.adviser()
    rev = a.get('/app/review_paper.php?id=%s' % pid)
    words = M10.strip(rev)
    check('OT-13', TYPE_NAME in words and MS_NAME in words and 'other:' not in words and 'Other: Policy' not in words,
          'the review page shows "Case Study" and "Policy Brief", never the stored markers',
          'page: %s' % words[words.find('Basic Information'):][:300])
    cfg = M14.editor_config(rev)
    check('OT-14', cfg and cfg.get('template') == 'blank',
          "the adviser's checklist starts on a blank list, since no ready-made one fits",
          'started on %s' % (cfg or {}).get('template'))
    check('OT-15', 'A Case Study does not require' in words,
          'and the documents note names the type', 'note missing')

    # Below the site header: its notification list names the paper too.
    body = lambda html: html.split('</header>', 1)[-1]
    desk = a.get('/app/faculty/faculty_review_dashboard.php')
    card = body(desk).split(T_PAPER)[1][:2500] if T_PAPER in body(desk) else ''
    check('OT-16', TYPE_NAME in M10.strip(card) and 'other:' not in card,
          'the desk card names the type', 'card: %s' % M10.strip(card)[:200])
    flt = body(a.get('/app/faculty/faculty_review_dashboard.php?type=other'))
    flc = body(a.get('/app/faculty/faculty_review_dashboard.php?type=capstone'))
    m = re.search(r'id="rcType">(.*?)</select>', desk, re.S)
    opts = re.findall(r'<option value="([^"]*)"', m.group(1)) if m else []
    check('OT-17', T_PAPER in flt and T_PAPER not in flc and opts == ['', 'capstone', 'undergrad_thesis', 'project', 'other'],
          "the desk's type filter offers the form's types and Other, and Other finds it",
          'options %s; in Other %s; in Capstone %s' % (opts, T_PAPER in flt, T_PAPER in flc))

    st = M10.student()
    dash = st.get('/app/student/student_dashboard.php?tab=process&type=other')
    check('OT-18', T_PAPER in dash and TYPE_NAME in M10.strip(dash),
          "the student's dashboard filters on Other too, and names the type", 'not listed')
    own = M10.strip(st.get('/app/student/paper_details.php?id=%s' % pid))
    check('OT-19', TYPE_NAME in own and MS_NAME in own and 'other:' not in own,
          "the author's own page names both", 'page: %s' % own[:200])

    H.q("UPDATE research_papers SET current_status='approved' WHERE paper_id=%s" % pid)
    pub = st.get('/archive/view_paper.php?id=%s' % pid)
    pw = M10.strip(pub)
    check('OT-20', st.last_status == 200 and TYPE_NAME in pw and MS_NAME in pw and 'other:' not in pw,
          'the public page names both', 'status %s: %s' % (st.last_status, pw[:200]))
    idx = st.get('/archive/index.php')
    check('OT-21', TYPE_NAME in M10.strip(idx) and 'other:Case' not in M10.strip(idx),
          'the repository lists it under its own type', 'type not on the repository page')

    co = M10.minted(M10.COORDINATOR_ID)
    an = M10.strip(co.get('/analytics/analytics_dashboard.php'))
    check('OT-22', TYPE_NAME in an and 'other:Case' not in an and 'undergrad_thesis' not in an,
          'analytics names types as people read them, not by their stored codes',
          'analytics said: %s' % an[an.find('type'):][:200])


STEPS = [('unit', unit), ('page', page), ('drafts', drafts), ('refusals', refusals), ('submission', submission)]


def main():
    only = tuple(sys.argv[1:])
    M10.setup()
    try:
        print('\n== MODULE 15: Other, for the paper type and the manuscript type ==')
        for name, fn in STEPS:
            if not only or name in only:
                H.run('OT-' + name, fn)
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            M10.cleanup()
        finally:
            F.teardown()
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
