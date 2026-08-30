# -*- coding: utf-8 -*-
"""Turn the run into the tracker's own format.

The output is a CSV with the same nine columns as the Google Sheet, so it can
be pasted straight in. The Module Function, Test Case Scenario and Product
Quality Component columns are carried over from the tracker unchanged, so the
document stays recognisably the same one; Action and Actual Input describe what
the automated run actually did, which is not always what the manual script did.
"""
import csv
import io
import json
import os
import sys
import datetime

HERE = os.path.dirname(os.path.abspath(__file__))
TRACKER = os.path.join(os.path.dirname(HERE), 'tracker.csv')

# What the suite signs in as. Named once so every row can point at it.
FIXTURES = ('Adviser AUTOTEST-FC1 / Autotest1; student 2099-00001-BN-0 / '
            'Autotest1; second student 2099-00002-BN-0; Coordinator RC-00123')


def load_tracker():
    """The original rows, keyed by ID, plus the module each one sits under."""
    rows = list(csv.reader(io.open(TRACKER, encoding='utf-8')))
    out, module = {}, ''
    order = []
    for r in rows:
        if not r or not r[0].strip():
            continue
        a = r[0].strip()
        if a.startswith('MODULE'):
            module = a
            order.append(('MODULE', a))
        elif a.startswith('ID-'):
            out[a] = {
                'module': module,
                'function': r[1].strip() if len(r) > 1 else '',
                'scenario': ' '.join(r[2].split()) if len(r) > 2 else '',
                'action': ' '.join(r[3].split()) if len(r) > 3 else '',
                'input': ' '.join(r[4].split()) if len(r) > 4 else '',
                'quality': r[7].strip() if len(r) > 7 else '',
                'was': (r[5].strip() or r[6].strip() or '') if len(r) > 6 else '',
            }
            order.append(('ID', a))
    return out, order


def actions():
    """One line per test, taken from the docstring the test carries."""
    sys.path.insert(0, HERE)
    import m1_auth, m3_dashboard, m4_submission, m5_review
    import m6_archive, m7_notify, m8_security, m9_ai
    out = {}
    for mod in (m1_auth, m3_dashboard, m4_submission, m5_review,
                m6_archive, m7_notify, m8_security, m9_ai):
        for tid, fn in mod.TESTS:
            doc = (fn.__doc__ or '').strip().split('\n')[0]
            out[tid] = doc
    return out


def main():
    results = json.loads(io.open(os.path.join(HERE, 'results.json'),
                                 encoding='utf-8').read())
    tracker, order = load_tracker()
    acts = actions()
    today = datetime.date.today().strftime('%d %B %Y')

    counts = {'PASSED': 0, 'FAILED': 0, 'NOT IMPLEMENTED': 0, 'UNTESTED': 0}
    for r in results.values():
        counts[r['verdict']] = counts.get(r['verdict'], 0) + 1
    total = len(results)
    rating = '%.2f%%' % (100.0 * counts['PASSED'] / total) if total else '0'

    out_path = os.path.join(os.path.dirname(HERE),
                            'PAPEL_automated_test_results.csv')
    f = io.open(out_path, 'w', encoding='utf-8-sig', newline='')
    w = csv.writer(f)

    w.writerow(['PAPEL IAS Test Cases Progress Tracker'])
    w.writerow([])
    w.writerow(['Project name:', 'Papel: Research Repository and Archiving Web App',
                '', '', '', '', 'Test Conditions', '', ''])
    w.writerow(['Project owner/s:', 'Belando, Sebastian Rafael M.',
                '', '', '', '', 'Total Test Cases', total, 'Rating'])
    w.writerow(['', 'Bering, Char Mae Grace', '', '', '', '',
                'Pass:', counts['PASSED'], rating])
    w.writerow(['', 'Reyes, Rayver S.', '', '', '', '',
                'Fail:', counts['FAILED'], ''])
    w.writerow(['Run:', 'Automated suite, %s' % today, '', '', '', '',
                'Untested:', counts['UNTESTED'], ''])
    w.writerow(['Method:', 'HTTP against the running application at '
                'localhost/capstone, with database assertions',
                '', '', '', '', 'Not Implemented:', counts['NOT IMPLEMENTED'], ''])
    w.writerow([])
    w.writerow(['Test Case Scenario ID ', 'Name of the Module Function ',
                'Test Case Scenario', 'Action', 'Actual Input', 'Pass', 'Fail',
                'Product Quality Component', 'Comments/Suggestion'])

    for kind, key in order:
        if kind == 'MODULE':
            w.writerow([key, '', '', '', '', '', '', '', ''])
            continue
        t = tracker[key]
        r = results.get(key)
        if not r:
            w.writerow([key, t['function'], t['scenario'], t['action'],
                        t['input'], '', '', t['quality'],
                        'Not covered by the automated run.'])
            continue
        verdict = r['verdict']
        pass_col = verdict if verdict != 'FAILED' else '-'
        fail_col = 'FAILED' if verdict == 'FAILED' else ''
        comment = r['comment']
        # Say when the result has moved since the manual run, because that is
        # the thing a reader most wants to spot.
        was = t['was']
        if was and was != verdict:
            comment = '[was %s] %s' % (was, comment)
        action = acts.get(key) or t['action']
        w.writerow([key, t['function'], t['scenario'], action,
                    r.get('evidence') or FIXTURES, pass_col, fail_col,
                    t['quality'], comment])
    f.close()

    print('  written: %s' % out_path)
    print('  %d cases: %d passed, %d failed, %d not implemented, %d untested '
          '(%s)' % (total, counts['PASSED'], counts['FAILED'],
                    counts['NOT IMPLEMENTED'], counts['UNTESTED'], rating))

    # What moved since the manual run, which is the interesting part.
    moved = []
    for key, t in tracker.items():
        r = results.get(key)
        if not r:
            continue
        if t['was'] and t['was'] != r['verdict']:
            moved.append((key, t['was'], r['verdict'], t['scenario']))
    moved.sort()
    print('\n  %d cases changed verdict since the manual tracker:' % len(moved))
    for k, a, b, sc in moved:
        print('   %-8s %-16s -> %-16s %s' % (k, a, b, sc[:44]))


if __name__ == '__main__':
    main()
