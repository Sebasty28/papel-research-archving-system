# -*- coding: utf-8 -*-
"""Shared machinery for the PAPEL automated test suite.

Everything here talks to the running site over HTTP, the way a person would,
because this project has a history of changes that read correctly in source and
failed in the browser. Where a check cannot be made through a page — a password
hash, a row that should have been deleted — it goes to the database directly.

Three things are deliberate:

  * Test accounts are created through the app's own forms, so making them is
    itself a test rather than a fixture.
  * Every test address is @example.com, which is reserved and undeliverable, so
    a test can never mail a real person.
  * Anything created is tagged AUTOTEST and torn down at the end. Nothing here
    touches a row it did not create.
"""
import io, json, os, random, re, subprocess, sys
import urllib.request, urllib.parse, urllib.error, http.cookiejar

BASE = 'http://localhost/capstone'
MYSQL = r'c:\xampp\mysql\bin\mysql.exe'
PHP = r'c:\xampp\php\php.exe'
DB = 'capstone_db'
SESS_DIR = r'C:\xampp\tmp'
HERE = os.path.dirname(os.path.abspath(__file__))

# Everything this suite creates carries this, so cleanup can be exact.
TAG = 'AUTOTEST'


# --------------------------------------------------------------- database
def q(sql, as_rows=True):
    """Run SQL, return a list of rows (each a list of columns), no header."""
    p = subprocess.run([MYSQL, '-uroot', DB, '-N', '-B', '-e', sql],
                       capture_output=True)
    out = p.stdout.decode('utf-8', 'replace')
    err = p.stderr.decode('utf-8', 'replace').strip()
    if p.returncode != 0:
        raise RuntimeError('SQL failed: %s' % err[:200])
    if not as_rows:
        return out
    return [line.split('\t') for line in out.splitlines() if line.strip()]


def one(sql, default=None):
    """First column of the first row, or default."""
    r = q(sql)
    return r[0][0] if r and r[0] else default


def esc(s):
    """Quote a value for inline SQL. Only ever used on test-owned strings."""
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


# ------------------------------------------------------------------- HTTP
class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Redirects are assertions here, so they are never followed silently."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client(object):
    """One browser: its own cookies, its own CSRF token."""

    def __init__(self, name='anon'):
        self.name = name
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.last_status = None
        self.last_headers = {}
        self.signed_in = False
        self.redirect = ''
        self.last_url = BASE + '/'

    def raw(self, path, data=None, headers=None, timeout=60, raw_body=None):
        """One request, redirects NOT followed, so a 302 can be asserted."""
        url = path if path.startswith('http') else BASE + path
        body = raw_body
        if data is not None:
            body = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(url, data=body)
        req.add_header('User-Agent', 'PAPEL-autotest')
        for k, v in (headers or {}).items():
            req.add_header(k, v)
        try:
            r = self.op.open(req, timeout=timeout)
            status, text, hdrs = r.getcode(), r.read(), dict(r.headers)
        except urllib.error.HTTPError as e:
            status, text, hdrs = e.code, e.read(), dict(e.headers)
        self.last_status = status
        self.last_headers = hdrs
        self.last_url = url
        return status, text.decode('utf-8', 'replace'), hdrs

    def get(self, path, **kw):
        """Fetch, following up to five redirects."""
        s, t, h = self.raw(path, **kw)
        hops = 0
        while s in (301, 302, 303, 307) and hops < 6:
            loc = h.get('Location') or h.get('location')
            if not loc:
                break
            loc = urllib.parse.urljoin(self.last_url, loc)
            s, t, h = self.raw(loc)
            hops += 1
        return t

    def token(self, path='/archive/index.php'):
        """A CSRF token valid for this session."""
        html = self.get(path)
        m = re.search(r'name="_token"\s+value="([^"]+)"', html)
        if not m:
            m = re.search(r'name=[\'"]_token[\'"][^>]*value=[\'"]([^\'"]+)', html)
        return m.group(1) if m else ''

    def post(self, path, fields, token_from='/archive/index.php', follow=True):
        """POST with a fresh CSRF token from this same session.

        Returns (status_of_the_post, body_after_following_the_redirect), so a
        handler that redirects on success can be judged on both.
        """
        data = dict(fields)
        if '_token' not in data:
            data['_token'] = self.token(token_from)
        s, t, h = self.raw(path, data=data)
        if follow and s in (301, 302, 303):
            loc = h.get('Location') or h.get('location') or ''
            if loc:
                # The whole chain, not one hop, and resolved against the page
                # that sent it: these handlers redirect with a bare filename,
                # and the flash the test is looking for is only on the page at
                # the end of the chain.
                return s, self.get(urllib.parse.urljoin(self.last_url, loc))
        return s, t


    def post_files(self, path, fields, files, token_from=None, follow=True):
        """A multipart POST, for the pages that take a PDF.

        Built by hand rather than with a library so the suite needs nothing
        outside the standard library, and so a deliberately malformed part can
        be sent when that is the point of the test.
        """
        import uuid
        crlf = chr(13) + chr(10)
        data = dict(fields)
        if '_token' not in data:
            data['_token'] = self.token(token_from or '/archive/index.php')
        boundary = '----papel' + uuid.uuid4().hex
        out = []
        for k, v in data.items():
            head = ('--' + boundary + crlf +
                    'Content-Disposition: form-data; name="' + str(k) + '"' +
                    crlf + crlf + str(v) + crlf)
            out.append(head.encode())
        for field, (filename, content, ctype) in files.items():
            if isinstance(content, str):
                content = content.encode()
            head = ('--' + boundary + crlf +
                    'Content-Disposition: form-data; name="' + str(field) +
                    '"; filename="' + str(filename) + '"' + crlf +
                    'Content-Type: ' + str(ctype) + crlf + crlf)
            out.append(head.encode())
            out.append(content)
            out.append(crlf.encode())
        out.append(('--' + boundary + '--' + crlf).encode())
        body = b''.join(out)
        s, t, h = self.raw(path, raw_body=body, headers={
            'Content-Type': 'multipart/form-data; boundary=' + boundary,
            'Content-Length': str(len(body))})
        if follow and s in (301, 302, 303):
            loc = h.get('Location') or h.get('location') or ''
            if loc:
                return s, self.get(urllib.parse.urljoin(self.last_url, loc))
        return s, t

    def login(self, identifier, password, tab='faculty'):
        """Sign in through the real form, exactly as the modal does."""
        tok = self.token()
        s, t, h = self.raw('/app/auth/login.php', data={
            '_token': tok, 'action': 'login', 'identifier': identifier,
            'password': password, 'selected_role': tab})
        loc = h.get('Location') or h.get('location') or ''
        self.redirect = loc
        self.signed_in = (s in (301, 302, 303)) and 'login_modal' not in loc
        return self.signed_in

    def login_error(self, identifier, password, tab='student'):
        """Attempt a sign-in and return the message the site shows."""
        self.login(identifier, password, tab)
        html = self.get('/archive/index.php?login_modal=1')
        m = re.search(r'login_error[^>]*>(.*?)</', html, re.S)
        if m:
            return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', '', m.group(1))).strip()
        for pat in (r'That ID or password is not right',
                    r'Enter your ID and password',
                    r'does not belong to the selected login section',
                    r'expired on \w+ \d+, \d{4}',
                    r'[Tt]oo many (?:sign-in )?attempts',
                    r'try again in \d+',
                    r'guest username or password is not right'):
            m2 = re.search(pat, html)
            if m2:
                return m2.group(0)
        return ''

    def signed_in_now(self):
        return 'logout.php' in self.get('/archive/index.php').lower()


# --------------------------------------------------------- minted sessions
def mint(user):
    """A signed-in client for a role whose password we do not hold.

    The Director's password is unrecoverable and the staff passwords are not
    ours to know, but authorisation still has to be tested for every role. PHP
    reads its session straight off disk, so writing the file is the same as
    having signed in. The tests that actually guard the door — role validation
    and IDOR — use real sign-ins instead, so nothing security-critical rests on
    this shortcut.
    """
    sid = ''.join(random.choice('0123456789abcdefghijklmnopqrstuv')
                  for _ in range(26))
    script = ('<?php $u = json_decode($argv[1], true);'
              'file_put_contents($argv[2], "user|" . serialize($u));')
    tmp = os.path.join(HERE, '_mint.php')
    io.open(tmp, 'w', encoding='utf-8').write(script)
    subprocess.run([PHP, tmp, json.dumps(user),
                    os.path.join(SESS_DIR, 'sess_' + sid)], capture_output=True)
    c = Client(user.get('full_name', 'minted'))
    # The app renames its cookie and scopes it to the site root, so a minted
    # session handed back under PHPSESSID on /capstone is simply never sent.
    c.jar.set_cookie(http.cookiejar.Cookie(
        0, 'papel_sid', sid, None, False, 'localhost.local', True, False,
        '/', True, False, None, False, None, None, {}))
    return c


def as_role(role, level=1):
    """A minted client for one of the six roles."""
    people = {
        'student':      (154, 'Rayver S. Reyes', 'student', 1),
        'faculty':      (152, 'Chico Ramos', 'faculty', 1),
        'coordinator':  (151, 'Michael Anjelo Miguel', 'admin', 1),
        'hap':          (198, 'Archie Arevalo', 'admin', 2),
        'librarian':    (227, 'Franchesca Louise Bernardo', 'librarian', 0),
        'director':     (1, 'Super Admin', 'super_admin', 1),
    }
    uid, name, urole, lvl = people[role]
    email = one('SELECT COALESCE(email,"") FROM users WHERE user_id=%d' % uid) or ''
    uname = one('SELECT COALESCE(username,"") FROM users WHERE user_id=%d' % uid) or ''
    return mint({'user_id': uid, 'username': uname, 'email': email,
                 'full_name': name, 'user_role': urole, 'admin_level': lvl})


# ------------------------------------------------------------------ result
class Results(object):
    def __init__(self):
        self.rows = {}

    def record(self, tid, verdict, comment='', evidence=''):
        self.rows[tid] = {'verdict': verdict, 'comment': comment,
                          'evidence': evidence}
        mark = {'PASSED': 'PASS', 'FAILED': 'FAIL', 'NOT IMPLEMENTED': 'N/I',
                'UNTESTED': 'UNT'}.get(verdict, verdict)
        print(('  %-8s %-5s %s' % (tid, mark, comment)).encode(
            'ascii', 'replace').decode())
        sys.stdout.flush()

    def save(self, path):
        io.open(path, 'w', encoding='utf-8').write(
            json.dumps(self.rows, indent=1, ensure_ascii=False))

    def load(self, path):
        if os.path.isfile(path):
            self.rows.update(json.loads(io.open(path, encoding='utf-8').read()))


R = Results()


def check(tid, condition, pass_msg, fail_msg, evidence=''):
    """Record PASSED or FAILED from one boolean."""
    R.record(tid, 'PASSED' if condition else 'FAILED',
             pass_msg if condition else fail_msg, evidence)
    return bool(condition)


def run(tid, fn):
    """Run one test; an exception is a FAILED, never a crashed run."""
    try:
        fn()
    except Exception as e:
        R.record(tid, 'FAILED', 'test error: %s' % str(e)[:100])
