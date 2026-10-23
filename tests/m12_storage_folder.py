# -*- coding: utf-8 -*-
"""MODULE 12: the Google Drive storage folder.

On staging every paper failed with "Upload failed", and the cause, once the page
could show it, was Drive answering 404 "File not found" to the folder the setting
named: a folder in a Google account the connected one cannot see. Nothing had
ever looked at that setting after it was typed in. These cases cover the folder
check, the Storage Folder page (Director only) and the submission.

Run on its own:

    python m12_storage_folder.py

It changes the real local setting briefly (to the exact id staging could not use)
and puts the original row back in a finally. A copy of that row is also written to
the system temp folder first, and the next run restores from it if this one was
killed before it could. Drive is only ever read, and no paper is filed.
"""
import html as htmllib, json, os, re, subprocess, sys, tempfile

import harness as H
import fixtures as F
import m10_section_scope as M
from harness import R, check

PROBE = os.path.join(H.HERE, '_folder_probe.php')
SAVED = os.path.join(tempfile.gettempdir(), 'papel_folder_setting.json')
SETTINGS = '/app/admin/gdrive_settings.php'
DIRECTOR = 1

# The folder staging could not use, from the screenshot it failed with.
BOGUS = '15fa2BoR85Xr-E8j20LCvi32cXZ3qZWHH'
APACHE_LOG = r'C:\xampp\apache\logs\error.log'


def probe(*args):
    code, out = M.php(PROBE, *args)
    lines = [l for l in out.strip().splitlines() if l.strip().startswith('{')]
    try:
        return json.loads(lines[-1]) if lines else {}
    except ValueError:
        return {'unreadable': out[-200:]}


def words(page):
    return htmllib.unescape(M.strip(page))


def put_setting(value, updated_by=None, updated_at=None):
    by = 'NULL' if updated_by in (None, '', 'NULL') else str(int(updated_by))
    at = 'updated_at' if updated_at in (None, '', 'NULL') else H.esc(updated_at)
    H.q("UPDATE system_settings SET setting_value=%s, updated_by=%s, updated_at=%s WHERE setting_key='gdrive_parent_folder_id'"
        % (H.esc(value), by, at))


def restore_from(saved):
    put_setting(saved['setting_value'], saved.get('updated_by'), saved.get('updated_at'))


def heal_if_a_run_was_killed():
    """A run killed between changing the setting and restoring it leaves it wrong."""
    if os.path.isfile(SAVED):
        with open(SAVED) as f:
            saved = json.load(f)
        now = probe('setting')
        if now.get('setting_value') != saved.get('setting_value'):
            print('  restoring the storage folder setting left by an interrupted run')
            restore_from(saved)
        os.remove(SAVED)


def unit():
    good = probe('setting')['setting_value']
    fid = probe('file_id')['id']
    a = probe('check', BOGUS)
    check('SD-01', a.get('state') == 'not_found' and a.get('unusable') is True,
          'the folder staging could not use is reported as not found, as Drive reports it',
          'got %s' % a)
    b = probe('check', 'https://drive.google.com/drive/folders/%s?usp=sharing' % good)
    check('SD-02', b.get('state') == 'ok' and b.get('name'),
          'the folder this site uses is accepted, even pasted as a sharing URL ("%s")' % b.get('name'),
          'got %s' % b)
    c = probe('check', fid) if fid else {}
    check('SD-03', c.get('state') == 'not_folder' and c.get('unusable') is True,
          'a paper PDF is recognised as a file, not a folder', 'got %s (file id %s)' % (c, bool(fid)))
    d = probe('check', '')
    check('SD-04', d.get('state') == 'unset' and d.get('unusable') is False,
          'no folder at all is a valid setting (uploads go to the root of the account), not a fault', 'got %s' % d)


def submission(saved):
    c = M.student()
    marker = os.path.getsize(APACHE_LOG)
    try:
        put_setting(BOGUS, saved.get('updated_by'), saved.get('updated_at'))
        s, j = M.submit(c, 'AUTOTEST Folder Refused')
    finally:
        restore_from(saved)
    msg = j.get('message') or ''
    check('SD-05', s == 422 and 'storage folder cannot be used' in msg and 'Nothing was submitted' in msg,
          'with an unusable folder the student is told so before anything is stored ("%s...")' % msg[:70],
          'HTTP %s %s' % (s, j))
    check('SD-06', 'the Director' in msg and 'Research Coordinator' in msg,
          'and is told who to tell, without a folder id or any internals', msg)
    check('SD-07', BOGUS not in msg and 'detail' not in j, 'the id is not shown to a student', j)
    check('SD-08', not M.paper_id('AUTOTEST Folder Refused'), 'no paper was stored', 'a paper row exists')
    with open(APACHE_LOG, 'rb') as f:
        f.seek(marker)
        newlog = f.read().decode('utf-8', 'replace')
    check('SD-09', 'Submission refused: the Google Drive storage folder %s cannot be used (not_found)' % BOGUS in newlog,
          'the refusal is logged with the folder and the reason', 'not in the log')
    now = probe('setting')
    check('SD-10', now.get('setting_value') == saved['setting_value'],
          'the setting is back as it was', 'now %s' % now)


def settings_page(saved):
    good = saved['setting_value']
    d = M.minted(DIRECTOR)

    page = words(d.get(SETTINGS))
    check('SD-11', 'Reachable:' in page and 'MAIN FINAL - PAPEL STORAGE' in page and 'cannot be used' not in page,
          'the Storage Folder page says the current folder is reachable, by name', page[page.find('Storage folder in use'):][:200])
    check('SD-12', 'Students can submit papers, and reviewers can open them' in page,
          'and the connection card still says submissions work', 'connection card changed')

    # A folder staging could not use is refused, with the reason, and not saved.
    s, body = d.post(SETTINGS, {'action': 'update_gdrive_folder', 'gdrive_folder_id': BOGUS}, token_from=SETTINGS)
    t = words(body)
    check('SD-13', 'That folder was not saved.' in t and 'cannot find this folder' in t,
          'saving an unusable folder is refused, and says why', t[t.find('That folder'):][:160])
    check('SD-14', probe('setting').get('setting_value') == good, 'and the setting is untouched',
          probe('setting'))

    # So is a file.
    fid = probe('file_id')['id']
    s, body = d.post(SETTINGS, {'action': 'update_gdrive_folder', 'gdrive_folder_id': fid}, token_from=SETTINGS)
    t = words(body)
    check('SD-15', 'That folder was not saved.' in t and 'a file, not a folder' in t,
          'a file pasted in place of a folder is refused', t[t.find('That folder'):][:160])
    check('SD-16', probe('setting').get('setting_value') == good, 'and the setting is untouched', probe('setting'))

    # And an empty box, as before.
    s, body = d.post(SETTINGS, {'action': 'update_gdrive_folder', 'gdrive_folder_id': ''}, token_from=SETTINGS)
    check('SD-17', 'Enter a folder link or ID first.' in words(body), 'an empty box is still asked for', 'message gone')

    # The folder that works is accepted, from a pasted URL, and named.
    url = 'https://drive.google.com/drive/folders/%s?usp=sharing' % good
    s, body = d.post(SETTINGS, {'action': 'update_gdrive_folder', 'gdrive_folder_id': url}, token_from=SETTINGS)
    t = words(body)
    check('SD-18', 'Storage folder updated to "MAIN FINAL - PAPEL STORAGE"' in t,
          'a usable folder is saved, and named in the confirmation', t[t.find('Storage folder'):][:160])
    check('SD-19', probe('setting').get('setting_value') == good, 'as the bare id, read out of the URL', probe('setting'))

    # What the page says when the stored folder is already bad (staging's state).
    try:
        put_setting(BOGUS, saved.get('updated_by'), saved.get('updated_at'))
        t = words(d.get(SETTINGS))
    finally:
        restore_from(saved)
    check('SD-20', 'This folder cannot be used.' in t and 'No paper can be submitted until a usable folder is saved' in t,
          'a folder that has gone bad is called out on the page, with what it blocks', t[t.find('Storage folder in use'):][:240])
    check('SD-21', 'storage folder below cannot be used' in t and 'Students can submit papers' not in t,
          'and the connection card stops claiming that students can submit', 'connection card still says it is fine')


def disconnected(saved):
    """With no connection the folder cannot be checked, so it is not saved."""
    d = M.minted(DIRECTOR)
    token_file = r'C:\xampp\htdocs\capstone\config\gdrive_token.json'
    aside = token_file + '.aside'
    import shutil
    moved_db = moved_file = False
    try:
        H.q("UPDATE system_settings SET setting_key='gdrive_system_token__aside' WHERE setting_key='gdrive_system_token'")
        moved_db = True
        if os.path.isfile(token_file):
            shutil.move(token_file, aside)
            moved_file = True
        s, body = d.post(SETTINGS, {'action': 'update_gdrive_folder', 'gdrive_folder_id': saved['setting_value']},
                         token_from=SETTINGS)
        t = words(body)
    finally:
        if moved_file and os.path.isfile(aside):
            shutil.move(aside, token_file)
        if moved_db:
            H.q("UPDATE system_settings SET setting_key='gdrive_system_token' WHERE setting_key='gdrive_system_token__aside'")
    check('SD-22', 'Connect Google Drive first' in t, 'with no connection a folder is not saved, and the reason is given',
          t[t.find('Connect'):][:120])
    back = H.one("SELECT CHAR_LENGTH(setting_value) FROM system_settings WHERE setting_key='gdrive_system_token'")
    check('SD-23', back == '512' and os.path.isfile(token_file), 'the Drive token is back in both places',
          'length %s, file %s' % (back, os.path.isfile(token_file)))


def run(only=()):
    def want(name):
        return not only or name in only

    print('\n== MODULE 12: the Google Drive storage folder ==')
    saved = probe('setting')
    if not saved.get('setting_value'):
        print('  no storage folder is set here, so there is nothing to put back; stopping')
        return
    with open(SAVED, 'w') as f:
        json.dump(saved, f)
    try:
        if want('unit'):
            H.run('SD-unit', unit)
        if want('submission'):
            H.run('SD-submission', lambda: submission(saved))
        if want('settings_page'):
            H.run('SD-settings', lambda: settings_page(saved))
        if want('disconnected'):
            H.run('SD-disconnected', lambda: disconnected(saved))
    finally:
        restore_from(saved)
        now = probe('setting')
        check('SD-24', now.get('setting_value') == saved['setting_value'] and
              str(now.get('updated_at')) == str(saved.get('updated_at')),
              'the storage folder setting is exactly as it was found (value and who/when)',
              'found %s, now %s' % (saved, now))
        if os.path.isfile(SAVED):
            os.remove(SAVED)


def main():
    heal_if_a_run_was_killed()
    M.setup()
    try:
        run(tuple(sys.argv[1:]))
    finally:
        print('\n-- clearing up -----------------------------------------------')
        try:
            M.cleanup()
        finally:
            F.teardown()
            for f in M.SESSIONS:
                try:
                    os.remove(f)
                except OSError:
                    pass
    bad = [k for k, v in R.rows.items() if v['verdict'] != 'PASSED']
    print('\n%d cases, %d not passed' % (len(R.rows), len(bad)))
    sys.exit(1 if bad else 0)


if __name__ == '__main__':
    main()
