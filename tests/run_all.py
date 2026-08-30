# -*- coding: utf-8 -*-
"""Run the whole suite against the running site, then clear up after itself.

    python run_all.py

Order matters: the accounts are built first through the app's own forms, every
module then works against them, and the teardown at the end removes exactly
what was made. If the run is interrupted, `python run_all.py --clean` removes
the leftovers.
"""
import io
import sys
import time
import traceback

import harness as H
import fixtures as F
import m1_auth, m3_dashboard, m4_submission, m5_review
import m6_archive, m7_notify, m8_security, m9_ai

MODULES = [m1_auth, m3_dashboard, m4_submission, m5_review,
           m6_archive, m7_notify, m8_security, m9_ai]


def setup():
    print('-- setting up ------------------------------------------------')
    F.teardown(verbose=False)          # start from a clean slate
    c = F.coordinator()
    if not c.signed_in:
        print('  cannot sign in as the Research Coordinator; is Apache up?')
        sys.exit(1)
    F.make_adviser(c)
    a = F.adviser_client()
    F.make_student(a, F.STUDENT)
    F.make_student(a, F.SECOND_STUDENT)
    adviser = F.uid_of('faculty_id', F.ADVISER['faculty_id'])
    s1 = F.uid_of('student_id', F.STUDENT['student_id'])
    print('  test adviser %s, students %s and %s created through the consoles'
          % (adviser, s1, F.uid_of('student_id',
                                   F.SECOND_STUDENT['student_id'])))


def main():
    if '--clean' in sys.argv:
        F.teardown()
        return
    t0 = time.time()
    setup()
    for mod in MODULES:
        try:
            mod.run()
        except Exception:
            print('  MODULE CRASHED:\n' + traceback.format_exc()[-500:])
    H.R.save('results.json')
    print('\n-- clearing up -----------------------------------------------')
    F.teardown()
    verdicts = {}
    for row in H.R.rows.values():
        verdicts[row['verdict']] = verdicts.get(row['verdict'], 0) + 1
    print('\n-- %d cases in %.1f minutes ----------------------------------'
          % (len(H.R.rows), (time.time() - t0) / 60))
    for k in ('PASSED', 'FAILED', 'NOT IMPLEMENTED', 'UNTESTED'):
        print('   %-18s %d' % (k, verdicts.get(k, 0)))


if __name__ == '__main__':
    main()
