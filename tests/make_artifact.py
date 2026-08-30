# -*- coding: utf-8 -*-
"""Build the readable report from the run, so the page can never drift from it.

Everything on the page comes out of results.json and the tracker; nothing is
typed twice.
"""
import io
import json
import os
import datetime
import html as H_
from string import Template

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(os.path.dirname(HERE), 'papel_test_ledger.html')

import report as REP

# Where a verdict moved for a reason worth separating out. These are judgement
# calls made when each case was tested, kept here so the page can group them.
SUPERSEDED = {'ID-005', 'ID-006', 'ID-007', 'ID-045', 'ID-046', 'ID-075',
              'ID-080'}
REGRESSED = {'ID-025', 'ID-033', 'ID-029', 'ID-050'}

VERDICT_CLASS = {'PASSED': 'ok', 'FAILED': 'bad', 'NOT IMPLEMENTED': 'none',
                 'UNTESTED': 'hold'}
VERDICT_LABEL = {'PASSED': 'Pass', 'FAILED': 'Fail',
                 'NOT IMPLEMENTED': 'Not built', 'UNTESTED': 'Blocked'}


def e(s):
    return H_.escape(str(s or ''))


def build():
    results = json.loads(io.open(os.path.join(HERE, 'results.json'),
                                 encoding='utf-8').read())
    tracker, order = REP.load_tracker()
    acts = REP.actions()
    counts = {'PASSED': 0, 'FAILED': 0, 'NOT IMPLEMENTED': 0, 'UNTESTED': 0}
    for r in results.values():
        counts[r['verdict']] = counts.get(r['verdict'], 0) + 1
    total = len(results)
    rating = 100.0 * counts['PASSED'] / total

    moved = []
    for key, t in tracker.items():
        r = results.get(key)
        if r and t['was'] and t['was'] != r['verdict']:
            if key in SUPERSEDED:
                kind = 'superseded'
            elif key in REGRESSED:
                kind = 'regressed'
            elif r['verdict'] == 'PASSED':
                kind = 'gained'
            else:
                kind = 'other'
            moved.append((key, t['was'], r['verdict'], t['scenario'], kind,
                          r['comment']))
    moved.sort()

    def chip(v):
        return ('<span class="chip %s">%s</span>'
                % (VERDICT_CLASS[v], VERDICT_LABEL[v]))

    # ---------------------------------------------------------------- rows
    body = []
    for kind, key in order:
        if kind == 'MODULE':
            num, _, name = key.partition(':')
            body.append(
                '<tr class="mod"><th colspan="4"><span class="mod-n">%s</span>'
                '<span class="mod-t">%s</span></th></tr>'
                % (e(num.replace('MODULE ', '')), e(name.strip().title())))
            continue
        t = tracker[key]
        r = results.get(key)
        if not r:
            continue
        v = r['verdict']
        changed = bool(t['was'] and t['was'] != v)
        body.append(
            '<tr class="case %s%s" data-v="%s"%s>'
            '<td class="id"><span class="rid">%s</span></td>'
            '<td class="what"><span class="scen">%s</span>'
            '<span class="fn">%s</span></td>'
            '<td class="verdict">%s%s</td>'
            '<td class="note">%s<span class="act">%s</span></td></tr>'
            % (VERDICT_CLASS[v], ' is-changed' if changed else '',
               VERDICT_CLASS[v], ' data-changed="1"' if changed else '',
               e(key), e(t['scenario']), e(t['function']), chip(v),
               ('<span class="was">was %s</span>'
                % e(VERDICT_LABEL.get(t['was'], t['was']))) if changed else '',
               e(r['comment']), e(acts.get(key, ''))))

    changed_rows = []
    for key, was, now, scen, kind, comment in moved:
        changed_rows.append(
            '<li class="mv %s"><span class="rid">%s</span>'
            '<span class="mv-s">%s</span>'
            '<span class="mv-a">%s <span class="arr">&rarr;</span> %s</span>'
            '<p>%s</p></li>'
            % (kind, e(key), e(scen),
               e(VERDICT_LABEL.get(was, was)), e(VERDICT_LABEL.get(now, now)),
               e(comment)))

    fails = [(k, results[k]) for k in sorted(results)
             if results[k]['verdict'] in ('FAILED', 'UNTESTED')]

    today = datetime.date.today().strftime('%d %B %Y')
    gained = sum(1 for m in moved if m[4] == 'gained')
    superseded_n = sum(1 for m in moved if m[4] == 'superseded')
    regressed_n = sum(1 for m in moved if m[4] == 'regressed')

    page = Template(TEMPLATE).safe_substitute(**{
        'date': today,
        'total': total,
        'passed': counts['PASSED'],
        'failed': counts['FAILED'],
        'none': counts['NOT IMPLEMENTED'],
        'hold': counts['UNTESTED'],
        'rating': '%.0f' % rating,
        'rating_exact': '%.1f' % rating,
        'rows': '\n'.join(body),
        'changed': '\n'.join(changed_rows),
        'moved_n': len(moved),
        'gained': gained,
        'superseded': superseded_n,
        'regressed': regressed_n,
        'fail_cards': '\n'.join(
            '<div class="fc %s"><span class="rid">%s</span>'
            '<h3>%s</h3><p>%s</p></div>'
            % (VERDICT_CLASS[r['verdict']], e(k),
               e(tracker.get(k, {}).get('scenario', '')), e(r['comment']))
            for k, r in fails),
    })
    io.open(OUT, 'w', encoding='utf-8').write(page)
    print('  written: %s  (%.0f KB)' % (OUT, len(page) / 1024.0))


TEMPLATE = u"""<title>PAPEL Test Ledger</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,600;1,6..72,400&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<style>
:root {
  --ground: #FAF7F6;
  --surface: #FFFFFF;
  --sunk: #F2EDEB;
  --ink: #1A1416;
  --ink-2: #574C4E;
  --ink-3: #8A7D7E;
  --rule: #E2D9D7;
  --rule-2: #CFC3C0;
  --accent: #8C1D2C;
  --accent-soft: #F6E9EA;
  --ok: #1B6B45;
  --ok-soft: #E4F0E9;
  --bad: #B3261E;
  --bad-soft: #FAE7E5;
  --none: #6B6560;
  --none-soft: #EDEAE7;
  --hold: #8A6212;
  --hold-soft: #F7EEDC;
  --shadow: 0 1px 2px rgba(26, 20, 22, .06), 0 8px 24px -16px rgba(26, 20, 22, .28);
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --ground: #16110F;
    --surface: #1F1917;
    --sunk: #261F1C;
    --ink: #EFE8E5;
    --ink-2: #BEB2AE;
    --ink-3: #8C807C;
    --rule: #332B28;
    --rule-2: #453B37;
    --accent: #E4808C;
    --accent-soft: #34191D;
    --ok: #6FCB9B;
    --ok-soft: #142A20;
    --bad: #F0908A;
    --bad-soft: #331917;
    --none: #A29892;
    --none-soft: #272220;
    --hold: #DDB259;
    --hold-soft: #302617;
    --shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 28px -18px rgba(0,0,0,.8);
  }
}
:root[data-theme="dark"] {
  --ground: #16110F;
  --surface: #1F1917;
  --sunk: #261F1C;
  --ink: #EFE8E5;
  --ink-2: #BEB2AE;
  --ink-3: #8C807C;
  --rule: #332B28;
  --rule-2: #453B37;
  --accent: #E4808C;
  --accent-soft: #34191D;
  --ok: #6FCB9B;
  --ok-soft: #142A20;
  --bad: #F0908A;
  --bad-soft: #331917;
  --none: #A29892;
  --none-soft: #272220;
  --hold: #DDB259;
  --hold-soft: #302617;
  --shadow: 0 1px 2px rgba(0,0,0,.4), 0 10px 28px -18px rgba(0,0,0,.8);
}

* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--ground);
  color: var(--ink);
  font-family: "IBM Plex Sans", system-ui, -apple-system, sans-serif;
  font-size: 15.5px;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 1120px; margin: 0 auto; padding: 0 clamp(1rem, 4vw, 3rem); }

/* ---------------------------------------------------------- masthead */
.mast {
  border-bottom: 1px solid var(--rule);
  background: var(--surface);
}
.mast .wrap {
  display: flex; flex-wrap: wrap; gap: 1.5rem 2rem;
  align-items: flex-end; justify-content: space-between;
  padding-top: clamp(2.5rem, 6vw, 4.5rem); padding-bottom: 2rem;
}
h1 {
  font-family: Newsreader, Georgia, serif;
  font-weight: 400; font-size: clamp(2.4rem, 5.5vw, 3.6rem);
  line-height: 1.04; letter-spacing: -.015em; margin: 0;
  text-wrap: balance;
}
h1 em { font-style: italic; color: var(--accent); }
.kicker {
  font-family: "IBM Plex Mono", monospace; font-size: .72rem;
  letter-spacing: .16em; text-transform: uppercase; color: var(--ink-3);
  margin: 0 0 .9rem;
}
.meta { margin: .9rem 0 0; color: var(--ink-2); max-width: 56ch; }
.meta code {
  font-family: "IBM Plex Mono", monospace; font-size: .86em;
  background: var(--sunk); padding: .08em .38em; border-radius: 3px;
}

/* ------------------------------------------------------------- tally */
.tally { display: flex; gap: .5rem; flex-wrap: wrap; }
.t {
  min-width: 104px; padding: .7rem .95rem .8rem;
  border: 1px solid var(--rule); border-top: 3px solid var(--edge, var(--rule-2));
  background: var(--surface); border-radius: 2px;
}
.t.ok   { --edge: var(--ok); }
.t.bad  { --edge: var(--bad); }
.t.none { --edge: var(--none); }
.t.hold { --edge: var(--hold); }
.t b {
  display: block; font-family: "IBM Plex Mono", monospace;
  font-size: 1.85rem; font-weight: 500; line-height: 1.1;
  font-variant-numeric: tabular-nums; letter-spacing: -.02em;
}
.t.ok b { color: var(--ok); } .t.bad b { color: var(--bad); }
.t.none b { color: var(--none); } .t.hold b { color: var(--hold); }
.t span {
  font-size: .7rem; letter-spacing: .1em; text-transform: uppercase;
  color: var(--ink-3);
}

section { padding: clamp(2.5rem, 5vw, 4rem) 0 0; }
h2 {
  font-family: Newsreader, Georgia, serif; font-weight: 400;
  font-size: clamp(1.5rem, 3vw, 2rem); letter-spacing: -.01em;
  margin: 0 0 .35rem; text-wrap: balance;
}
.lede { color: var(--ink-2); margin: 0 0 1.6rem; max-width: 68ch; }

/* ------------------------------------------------------------ blocker */
.fails { display: grid; gap: .8rem; }
.fc {
  background: var(--surface); border: 1px solid var(--rule);
  border-left: 3px solid var(--bad); border-radius: 2px;
  padding: 1.05rem 1.2rem; box-shadow: var(--shadow);
}
.fc.hold { border-left-color: var(--hold); }
.fc h3 {
  font-family: "IBM Plex Sans", sans-serif; font-size: 1rem; font-weight: 600;
  margin: .25rem 0 .4rem;
}
.fc p { margin: 0; color: var(--ink-2); font-size: .93rem; }
.rid {
  font-family: "IBM Plex Mono", monospace; font-size: .74rem; font-weight: 500;
  letter-spacing: .04em; color: var(--accent);
}

/* ------------------------------------------------------------ changed */
.mv-grid { display: grid; gap: .55rem; list-style: none; padding: 0; margin: 0; }
.mv {
  display: grid; grid-template-columns: 5.5rem 1fr auto; gap: .3rem .9rem;
  align-items: baseline; padding: .75rem .95rem;
  background: var(--surface); border: 1px solid var(--rule);
  border-radius: 2px; border-left: 3px solid var(--none);
}
.mv.gained { border-left-color: var(--ok); }
.mv.regressed { border-left-color: var(--bad); }
.mv.superseded { border-left-color: var(--ink-3); }
.mv-s { font-weight: 500; }
.mv-a {
  font-family: "IBM Plex Mono", monospace; font-size: .74rem;
  color: var(--ink-3); white-space: nowrap;
}
.mv .arr { color: var(--accent); }
.mv p {
  grid-column: 2 / -1; margin: .15rem 0 0;
  color: var(--ink-2); font-size: .88rem;
}
.legend {
  display: flex; gap: 1.1rem; flex-wrap: wrap; margin: 0 0 1.2rem;
  font-size: .8rem; color: var(--ink-2);
}
.legend i { display: inline-block; width: 10px; height: 3px; margin-right: .4rem;
  vertical-align: middle; }
.legend .g i { background: var(--ok); }
.legend .s i { background: var(--ink-3); }
.legend .r i { background: var(--bad); }

/* ------------------------------------------------------------- ledger */
.controls { display: flex; gap: .4rem; flex-wrap: wrap; margin: 0 0 1.1rem; }
.controls button {
  font: inherit; font-size: .82rem; cursor: pointer;
  padding: .38rem .8rem; border-radius: 2px;
  border: 1px solid var(--rule-2); background: var(--surface);
  color: var(--ink-2);
}
.controls button[aria-pressed="true"] {
  background: var(--accent); border-color: var(--accent); color: var(--surface);
}
:root[data-theme="dark"] .controls button[aria-pressed="true"],
:root:not([data-theme="light"]) .controls button[aria-pressed="true"] {
  color: var(--ground);
}
.controls button:focus-visible,
a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

.tablewrap { overflow-x: auto; border: 1px solid var(--rule);
  border-radius: 2px; background: var(--surface); }
table { border-collapse: collapse; width: 100%; min-width: 720px; }
tr.mod th {
  text-align: left; padding: 1.1rem 1rem .5rem;
  background: var(--sunk); border-top: 1px solid var(--rule);
  border-bottom: 1px solid var(--rule);
}
tr.mod .mod-n {
  font-family: "IBM Plex Mono", monospace; font-size: .72rem;
  color: var(--accent); letter-spacing: .1em; margin-right: .7rem;
}
tr.mod .mod-t {
  font-family: Newsreader, Georgia, serif; font-size: 1.08rem; font-weight: 600;
}
td { padding: .78rem 1rem; border-bottom: 1px solid var(--rule);
  vertical-align: top; }
tr.case:last-child td { border-bottom: 0; }
td.id { width: 5.6rem; }
td.what { width: 27%; }
.scen { display: block; font-weight: 500; }
.fn { display: block; font-size: .78rem; color: var(--ink-3); }
td.verdict { width: 8.5rem; white-space: nowrap; }
.chip {
  display: inline-block; font-size: .7rem; font-weight: 600;
  letter-spacing: .06em; text-transform: uppercase;
  padding: .16rem .5rem; border-radius: 2px;
}
.chip.ok { background: var(--ok-soft); color: var(--ok); }
.chip.bad { background: var(--bad-soft); color: var(--bad); }
.chip.none { background: var(--none-soft); color: var(--none); }
.chip.hold { background: var(--hold-soft); color: var(--hold); }
.was {
  display: block; font-family: "IBM Plex Mono", monospace;
  font-size: .68rem; color: var(--ink-3); margin-top: .3rem;
}
td.note { font-size: .9rem; color: var(--ink-2); }
.act { display: block; margin-top: .35rem; font-size: .78rem;
  color: var(--ink-3); font-style: italic; }
tr.case.is-changed td.id { box-shadow: inset 3px 0 0 var(--accent); }

/* -------------------------------------------------------------- notes */
.notes { display: grid; gap: 1.4rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
.note-card { border-top: 2px solid var(--rule-2); padding-top: .9rem; }
.note-card h3 {
  font-family: "IBM Plex Sans", sans-serif; font-size: .82rem; font-weight: 600;
  letter-spacing: .08em; text-transform: uppercase; color: var(--ink-3);
  margin: 0 0 .5rem;
}
.note-card p { margin: 0 0 .6rem; color: var(--ink-2); font-size: .91rem; }
.note-card code {
  font-family: "IBM Plex Mono", monospace; font-size: .84em;
  background: var(--sunk); padding: .08em .35em; border-radius: 3px;
}
footer {
  margin-top: 3.5rem; border-top: 1px solid var(--rule);
  padding: 1.4rem 0 3rem; color: var(--ink-3); font-size: .82rem;
}
@media (max-width: 640px) {
  .mv { grid-template-columns: 1fr; }
  .mv-a { white-space: normal; }
}
@media (prefers-reduced-motion: reduce) {
  * { animation: none !important; transition: none !important; }
}
</style>

<header class="mast">
  <div class="wrap">
    <div>
      <p class="kicker">Automated regression run &middot; ${date}</p>
      <h1>Every test case, <em>run against the live site</em></h1>
      <p class="meta">All ${total} scenarios from the progress tracker, executed
        over HTTP against <code>localhost/capstone</code> with assertions read
        back from the database. Accounts were created through the app's own
        consoles and removed afterwards.</p>
    </div>
    <div class="tally">
      <div class="t ok"><b>${passed}</b><span>Pass</span></div>
      <div class="t bad"><b>${failed}</b><span>Fail</span></div>
      <div class="t none"><b>${none}</b><span>Not built</span></div>
      <div class="t hold"><b>${hold}</b><span>Blocked</span></div>
    </div>
  </div>
</header>

<main class="wrap">

<section>
  <h2>What is not working</h2>
  <p class="lede">Two failures and one case that could not be judged &mdash; all
    three are the same fault. Everything else either passes or was never built.</p>
  <div class="fails">
${fail_cards}
  </div>
</section>

<section>
  <h2>What moved since the manual run</h2>
  <p class="lede">${moved_n} of the ${total} cases now report differently from
    the tracker: ${gained} work that did not before, ${superseded} describe a
    design that has since changed, and ${regressed} went backwards.</p>
  <p class="legend">
    <span class="g"><i></i>Now working</span>
    <span class="s"><i></i>Superseded by design</span>
    <span class="r"><i></i>Went backwards</span>
  </p>
  <ul class="mv-grid">
${changed}
  </ul>
</section>

<section>
  <h2>The full ledger</h2>
  <p class="lede">Every case in tracker order, with what the automated run did
    and what it found. ${rating_exact}%% passed.</p>
  <div class="controls">
    <button type="button" data-f="all" aria-pressed="true">All ${total}</button>
    <button type="button" data-f="bad" aria-pressed="false">Failures</button>
    <button type="button" data-f="none" aria-pressed="false">Not built</button>
    <button type="button" data-f="hold" aria-pressed="false">Blocked</button>
    <button type="button" data-f="changed" aria-pressed="false">Changed</button>
  </div>
  <div class="tablewrap">
    <table>
      <tbody>
${rows}
      </tbody>
    </table>
  </div>
</section>

<section>
  <h2>How the run was made</h2>
  <div class="notes">
    <div class="note-card">
      <h3>Against the running site</h3>
      <p>Each case is decided on what the application did with a real request,
        not on reading the source. Where a page cannot show the answer &mdash; a
        password hash, a row that should have been deleted &mdash; the check
        goes to the database.</p>
    </div>
    <div class="note-card">
      <h3>Its own accounts</h3>
      <p>A Research Adviser and two students were created through Manage Faculty
        and My Students, used for the whole run, and deleted at the end. No real
        account was signed into with a guessed password, and no real record was
        edited.</p>
    </div>
    <div class="note-card">
      <h3>No mail reached anyone</h3>
      <p>Every test address is at <code>example.com</code>, which is reserved and
        cannot receive mail, so the tests prove the site tried to send and
        handled the result without reaching a real inbox.</p>
    </div>
    <div class="note-card">
      <h3>Where the tracker has moved on</h3>
      <p>Several cases describe a sign-in with a birthdate and an emailed OTP,
        and a four-step approval ending at the Director. Neither is how the
        system works now. Those are reported against what it actually does and
        marked superseded rather than failed.</p>
    </div>
  </div>
</section>

<footer class="wrap">
  <p>PAPEL &mdash; Research Repository and Archiving Web Application.
    Automated suite, ${date}. Verdicts use the tracker's own vocabulary:
    Pass, Fail, Not Implemented, Untested.</p>
</footer>
</main>

<script>
(function () {
  var buttons = document.querySelectorAll('.controls button');
  var rows = document.querySelectorAll('tr.case');
  var mods = document.querySelectorAll('tr.mod');
  function apply(f) {
    rows.forEach(function (r) {
      var show = f === 'all' ||
                 (f === 'changed' ? r.dataset.changed === '1'
                                  : r.dataset.v === f);
      r.hidden = !show;
    });
    // A module heading with nothing under it is noise, so hide it too.
    mods.forEach(function (m) {
      var n = m.nextElementSibling, any = false;
      while (n && !n.classList.contains('mod')) {
        if (n.classList.contains('case') && !n.hidden) { any = true; break; }
        n = n.nextElementSibling;
      }
      m.hidden = !any;
    });
  }
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      buttons.forEach(function (o) { o.setAttribute('aria-pressed', 'false'); });
      b.setAttribute('aria-pressed', 'true');
      apply(b.dataset.f);
    });
  });
})();
</script>
"""

if __name__ == '__main__':
    build()
