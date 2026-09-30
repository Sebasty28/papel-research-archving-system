# -*- coding: utf-8 -*-
"""SVG builders for the PAPEL Workflow Handbook.

Each function takes a small description of one diagram and returns an inline
<svg> string sized to the handbook's text column (680 units wide). The layout
is done here rather than in a diagram tool so that the four kinds of picture -
use case, sequence, ERD and flow - share one look, and so the handbook builds
with nothing but Python and Chrome.

Text width is estimated, not measured. The estimate leans wide on purpose: a
label that wraps a little early is harmless, one that runs out of its oval is
not.
"""
import math
from html import escape

C = dict(
    maroon='#820707', dark='#630000', ink='#2B1616', cream='#FFF5F5',
    soft='#F8ECEC', border='#E6D4D4', grey='#6B5A5A', line='#8F7373',
    white='#FFFFFF', gold='#DCA92C', goldbg='#FDF4DC', golddark='#7A5A00',
    blue='#1B5E9E', bluebg='#EAF2FB', green='#2E7D32', greenbg='#EAF5EB',
    slate='#5B6770', slatebg='#EEF0F2',
)
FONT = "Inter, 'Segoe UI', Arial, sans-serif"
HEAD = "'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif"
MONO = "'JetBrains Mono', Consolas, 'Courier New', monospace"
W_DEFAULT = 680


# ================================================================ text
_NARROW = set("iljtfrI.,:;'!|()[]` ")
_WIDE = set("mwMW@%")


def tw(s, size, bold=False, mono=False):
    """Rough width of a string in px. Errs wide so wrapping errs early."""
    if mono:
        return len(s) * size * 0.61
    w = 0.0
    for ch in s:
        if ch in _NARROW:
            w += 0.31
        elif ch in _WIDE:
            w += 0.84
        elif ch.isupper():
            w += 0.67
        elif ch.isdigit():
            w += 0.58
        else:
            w += 0.55
    return w * size * (1.06 if bold else 1.0)


def wrap(s, size, maxw, bold=False):
    """Greedy word wrap. A '|' in the text forces a line break."""
    lines = []
    for part in str(s).split('|'):
        cur = ''
        for word in part.split():
            trial = (cur + ' ' + word) if cur else word
            if cur and tw(trial, size, bold) > maxw:
                lines.append(cur)
                cur = word
            else:
                cur = trial
        lines.append(cur)
    return lines


def halo_rect(x, y, s, size, anchor='start', bold=False, mono=False):
    """A white patch behind a label, so it stays legible where it crosses a line.

    Not a white text stroke (paint-order="stroke"), though that looks the same
    on screen: Chrome's PDF engine cannot print stroked text as text, so it
    redraws every such glyph as a Type3 shape, which made the handbook 12 MB
    and its diagram labels unsearchable."""
    w = tw(s, size, bold, mono) + 4
    x0 = {'start': x - 2, 'middle': x - w / 2, 'end': x - w + 2}[anchor]
    return ('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="2" fill="#FFFFFF"/>'
            % (x0, y - size * 0.86, w, size * 1.18))


def T(x, y, s, size=12, weight=400, fill=None, anchor='start', family=FONT,
      italic=False, halo=False):
    extra = ' font-style="italic"' if italic else ''
    back = halo_rect(x, y, s, size, anchor, weight >= 600, family == MONO) if halo else ''
    return ('%s<text x="%.1f" y="%.1f" font-family="%s" font-size="%s" font-weight="%s" '
            'fill="%s" text-anchor="%s"%s>%s</text>'
            % (back, x, y, family, size, weight, fill or C['ink'], anchor, extra, escape(s)))


def lines_at(x, cy, lines, size=12, lh=None, **kw):
    """Several lines of text centred vertically on cy."""
    lh = lh or size * 1.22
    y0 = cy - (len(lines) - 1) * lh / 2 + size * 0.36
    return ''.join(T(x, y0 + i * lh, ln, size=size, **kw) for i, ln in enumerate(lines))


def line(x1, y1, x2, y2, color=None, width=1.3, dash=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ''
    return ('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="%s"%s/>'
            % (x1, y1, x2, y2, color or C['line'], width, d))


def head(x1, y1, x2, y2, color, style='filled', L=10, w=4.6):
    """An arrowhead at (x2, y2) pointing away from (x1, y1).

    Drawn as a real shape rather than a marker, so it prints the same in every
    PDF viewer."""
    a = math.atan2(y2 - y1, x2 - x1)
    bx, by = x2 - L * math.cos(a), y2 - L * math.sin(a)
    px, py = w * math.sin(a), -w * math.cos(a)
    if style == 'filled':
        return ('<polygon points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="%s"/>'
                % (x2, y2, bx + px, by + py, bx - px, by - py, color))
    return ('<polyline points="%.1f,%.1f %.1f,%.1f %.1f,%.1f" fill="none" stroke="%s" '
            'stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>'
            % (bx + px, by + py, x2, y2, bx - px, by - py, color))


def svg(W, H, body, label):
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" '
            'role="img" aria-label="%s"><title>%s</title>%s</svg>'
            % (round(W), round(H), escape(label), escape(label), body))


# ================================================================ actors
def person(x, cy, label, maxw=112, size=11.5):
    """A UML stick figure with its name underneath. Returns (svg, bottom)."""
    s = C['ink']
    out = ['<g stroke="%s" stroke-width="1.8" fill="none" stroke-linecap="round">' % s,
           '<circle cx="%.1f" cy="%.1f" r="8" fill="#FFFFFF"/>' % (x, cy - 21),
           '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>' % (x, cy - 13, x, cy + 8),
           '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>' % (x - 14, cy - 6, x + 14, cy - 6),
           '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>' % (x, cy + 8, x - 11, cy + 23),
           '<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>' % (x, cy + 8, x + 11, cy + 23),
           '</g>']
    lines = wrap(label, size, maxw, bold=True)
    for i, ln in enumerate(lines):
        out.append(T(x, cy + 40 + i * 14, ln, size=size, weight=600, anchor='middle'))
    return ''.join(out), cy + 32 + 14 * len(lines)


def sysbox(x, cy, label, w=108):
    """An outside service drawn as an actor with the «system» keyword."""
    lines = wrap(label, 11, w - 14, bold=True)
    h = 26 + 13 * len(lines)
    y0 = cy - h / 2
    out = ['<rect x="%.1f" y="%.1f" width="%s" height="%s" rx="6" fill="%s" stroke="%s" '
           'stroke-width="1.3"/>' % (x - w / 2, y0, w, h, C['bluebg'], C['blue']),
           T(x, y0 + 13, '«system»', size=9, fill=C['blue'], anchor='middle', italic=True)]
    for i, ln in enumerate(lines):
        out.append(T(x, y0 + 27 + i * 13, ln, size=11, weight=700, fill=C['blue'], anchor='middle'))
    return ''.join(out), h


# ================================================================ use case
def usecase(label, cases, grid, left=(), right=(), links=(), rels=(),
            system='PAPEL System', W=W_DEFAULT):
    """A high-level UML use case diagram.

    cases   {id: text}                  '|' forces a line break
    grid    rows, top to bottom. A row is 'id' (centred) or (leftId, rightId),
            with None for an empty cell.
    left, right  [(id, name, 'person' | 'system')]
    links   [(actorId, caseId)]
    rels    [(fromCase, toCase, 'include' | 'extend')]
    """
    BX0, BX1 = 126, W - 126
    two = any(not isinstance(r, str) for r in grid)
    colx = (BX0 + (BX1 - BX0) * 0.255, BX0 + (BX1 - BX0) * 0.745)
    RX = 86 if two else 122
    ROW = 70
    BY0 = 10
    first = BY0 + 62

    pos, geo = {}, {}
    for i, row in enumerate(grid):
        y = first + i * ROW
        cells = [(W / 2, row, 'c')] if isinstance(row, str) else \
                [(colx[c], cid, c) for c, cid in enumerate(row)]
        for x, cid, col in cells:
            if not cid:
                continue
            lines = wrap(cases[cid], 12, 2 * RX - 34)
            ry = 15 + 7 * len(lines)
            pos[cid] = (x, y, col)
            geo[cid] = (RX, ry, lines)
    BY1 = first + (len(grid) - 1) * ROW + 46

    def place(actors, maxw):
        items = []
        for aid, alab, kind in actors:
            ys = [pos[c][1] for a, c in links if a == aid and c in pos]
            want = sum(ys) / len(ys) if ys else (BY0 + BY1) / 2
            if kind == 'person':
                n = len(wrap(alab, 11.5, maxw, bold=True))
                top, bot = -31, 32 + 14 * n
            else:
                n = len(wrap(alab, 11, 94, bold=True))
                h = 26 + 13 * n
                top, bot = -h / 2, h / 2
            items.append([want, aid, alab, kind, top, bot])
        items.sort(key=lambda it: it[0])
        prev = BY0 + 6
        for it in items:
            it[0] = max(it[0], prev + 12 - it[4])
            prev = it[0] + it[5]
        return items, prev

    L, lbot = place(left, 112)
    R, rbot = place(right, 112)
    BY1 = max(BY1, lbot + 8, rbot + 8)
    H = BY1 + 10

    def edge(cid, tx, ty):
        """Where a line aimed at (tx, ty) leaves the oval."""
        x, y, _ = pos[cid]
        rx, ry, _ = geo[cid]
        dx, dy = tx - x, ty - y
        if dx == 0 and dy == 0:
            return x, y
        t = 1 / math.sqrt((dx / rx) ** 2 + (dy / ry) ** 2)
        return x + dx * t, y + dy * t

    out = ['<rect x="%d" y="%d" width="%d" height="%d" rx="14" fill="%s" stroke="%s" '
           'stroke-width="1.8"/>' % (BX0, BY0, BX1 - BX0, BY1 - BY0, C['cream'], C['maroon']),
           T(W / 2, BY0 + 24, system, size=13, weight=700, fill=C['maroon'], anchor='middle',
             family=HEAD)]

    ax = {}
    for side, items in (('l', L), ('r', R)):
        x = 58 if side == 'l' else W - 58
        for cy, aid, alab, kind, _t, _b in items:
            if kind == 'person':
                anchor = (x + 17 if side == 'l' else x - 17, cy - 6)
            else:
                anchor = (x + 54 if side == 'l' else x - 54, cy)
            ax[aid] = (x, cy, alab, kind, anchor)

    # Associations first, so the ovals sit on top of them. Each one meets its
    # oval at the side facing the actor, not on the line to the oval's centre:
    # aimed at the centre, a line from an actor standing low on the page cut
    # through every oval between it and its own.
    for aid, cid in links:
        if aid not in ax or cid not in pos:
            continue
        axp, ayp = ax[aid][4]
        x, y, _ = pos[cid]
        rx = geo[cid][0]
        ex = x - rx if axp < x else x + rx
        out.append(line(axp, ayp, ex, y, C['line'], 1.25))

    for a, b, kind in rels:
        xa, ya, ca = pos[a]
        xb, yb, cb = pos[b]
        rxa, rya, _ = geo[a]
        rxb, ryb, _ = geo[b]
        word = '«%s»' % kind
        if abs(xa - xb) < 1 and abs(ya - yb) > ROW * 1.5:
            # Same column, not neighbours: bow out towards the middle of the
            # boundary instead of cutting through the ovals in between.
            s = -1 if ca == 1 else 1
            sx, sy = xa + s * rxa, ya
            ex, ey = xb + s * rxb, yb
            bow = 46
            out.append('<path d="M %.1f %.1f C %.1f %.1f %.1f %.1f %.1f %.1f" fill="none" '
                       'stroke="%s" stroke-width="1.3" stroke-dasharray="5 4"/>'
                       % (sx, sy, sx + s * bow, sy, ex + s * bow, ey, ex, ey, C['grey']))
            out.append(head(ex + s * bow, ey, ex, ey, C['grey'], 'open'))
            lx = max(sx, ex) + 40 if s > 0 else min(sx, ex) - 40
            out.append(T(lx, (sy + ey) / 2 + 4, word, size=10, fill=C['grey'], italic=True,
                         anchor='start' if s > 0 else 'end', halo=True))
        else:
            sx, sy = edge(a, xb, yb)
            ex, ey = edge(b, xa, ya)
            out.append(line(sx, sy, ex, ey, C['grey'], 1.3, '5 4'))
            out.append(head(sx, sy, ex, ey, C['grey'], 'open'))
            mx, my = (sx + ex) / 2, (sy + ey) / 2
            if abs(ex - sx) < abs(ey - sy) * 0.6:
                out.append(T(mx + 7, my + 4, word, size=10, fill=C['grey'], italic=True, halo=True))
            else:
                out.append(T(mx, my - 6, word, size=10, fill=C['grey'], italic=True,
                             anchor='middle', halo=True))

    for cid, (x, y, _c) in pos.items():
        rx, ry, lines = geo[cid]
        out.append('<ellipse cx="%.1f" cy="%.1f" rx="%s" ry="%s" fill="#FFFFFF" stroke="%s" '
                   'stroke-width="1.6"/>' % (x, y, rx, ry, C['maroon']))
        out.append(lines_at(x, y, lines, size=12, lh=14.5, weight=500, anchor='middle'))

    for aid, (x, cy, alab, kind, _a) in ax.items():
        if kind == 'person':
            out.append(person(x, cy, alab)[0])
        else:
            out.append(sysbox(x, cy, alab)[0])

    return svg(W, H, ''.join(out), label)


# ================================================================ sequence
def sequence(label, parts, steps, W=W_DEFAULT, numbered=True):
    """A UML sequence diagram.

    parts  [(id, name, kind)]  kind: person | system | db | ext | browser
    steps  a list of
        ('msg',  from, to, text)       solid arrow, a request or action
        ('ret',  from, to, text)       dashed arrow, an answer coming back
        ('self', who, text)            the part does something by itself
        ('note', who | (a, b), text)   a yellow note across one or more parts
        ('alt',  [(guard, steps), (guard, steps), ...])
        ('opt',  guard, steps)
        ('loop', guard, steps)
    """
    n = len(parts)
    MX = 66
    gap = (W - 2 * MX) / max(n - 1, 1)
    X = {p[0]: MX + i * gap for i, p in enumerate(parts)}
    FS, LH = 11.5, 14.2

    hdr, HB = [], 0
    for pid, plab, kind in parts:
        x = X[pid]
        if kind == 'person':
            g, bottom = person(x, 36, plab, maxw=min(gap - 10, 124))
            hdr.append(g)
            HB = max(HB, bottom)
            continue
        bw = min(gap - 14, 128)
        lines = wrap(plab, 11.5, bw - 16, bold=True)
        bh = 20 + 14 * len(lines)
        y0 = 20
        if kind == 'db':
            e = 7
            hdr.append('<path d="M %.1f %.1f v %.1f a %.1f %.1f 0 0 0 %.1f 0 v %.1f" fill="%s" '
                       'stroke="%s" stroke-width="1.5"/>'
                       % (x - bw / 2, y0 + e, bh - e, bw / 2, e, bw, -(bh - e), C['cream'], C['maroon']))
            hdr.append('<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%s" fill="%s" stroke="%s" '
                       'stroke-width="1.5"/>' % (x, y0 + e, bw / 2, e, C['soft'], C['maroon']))
            hdr.append(lines_at(x, y0 + e + (bh - e) / 2 + 3, lines, size=11.5, lh=14,
                                weight=700, fill=C['maroon'], anchor='middle'))
            HB = max(HB, y0 + bh + e + 6)
            continue
        fill, stroke, tcol, dash = {
            'system': (C['maroon'], C['maroon'], '#FFFFFF', ''),
            'ext': (C['bluebg'], C['blue'], C['blue'], ' stroke-dasharray="5 3"'),
            'browser': (C['goldbg'], C['gold'], C['golddark'], ''),
        }[kind]
        hdr.append('<rect x="%.1f" y="%s" width="%.1f" height="%s" rx="7" fill="%s" stroke="%s" '
                   'stroke-width="1.5"%s/>' % (x - bw / 2, y0, bw, bh, fill, stroke, dash))
        hdr.append(lines_at(x, y0 + bh / 2, lines, size=11.5, lh=14, weight=700, fill=tcol,
                            anchor='middle'))
        HB = max(HB, y0 + bh + 6)
    HB += 10

    frames, body = [], []
    count = [0]

    def label_lines(txt, maxw, number):
        prefix = '%d. ' % number if number else ''
        return wrap(prefix + txt, FS, maxw), prefix

    def draw_text(x, y, ln, i, prefix, italic, anchor):
        col = C['grey'] if italic else C['ink']
        it = ' font-style="italic"' if italic else ''
        if i == 0 and prefix and ln.startswith(prefix):
            return ('%s<text x="%.1f" y="%.1f" font-family="%s" font-size="%s" text-anchor="%s" '
                    'fill="%s"%s><tspan font-weight="700" fill="%s" font-style="normal">%s</tspan> %s</text>'
                    % (halo_rect(x, y, ln, FS, anchor), x, y, FONT, FS, anchor, col, it, C['maroon'],
                       escape(prefix.strip()), escape(ln[len(prefix):])))
        return T(x, y, ln, size=FS, anchor=anchor, italic=italic, halo=True, fill=col)

    def render(items, y, depth):
        for it in items:
            kind = it[0]
            if kind in ('msg', 'ret'):
                _, a, b, txt = it
                count[0] += 1
                x1, x2 = X[a], X[b]
                lines, prefix = label_lines(txt, max(abs(x2 - x1) - 20, 104),
                                            count[0] if numbered else None)
                cx = (x1 + x2) / 2
                for i, ln in enumerate(lines):
                    body.append(draw_text(cx, y + 12 + i * LH, ln, i, prefix, kind == 'ret', 'middle'))
                ay = y + len(lines) * LH + 7
                col = C['grey'] if kind == 'ret' else C['ink']
                body.append(line(x1, ay, x2, ay, col, 1.45, '6 4' if kind == 'ret' else None))
                body.append(head(x1, ay, x2, ay, col, 'open' if kind == 'ret' else 'filled'))
                y = ay + 13
            elif kind == 'self':
                _, a, txt = it
                count[0] += 1
                x = X[a]
                right = x + 36 + 150 <= W - 6
                s = 1 if right else -1
                lx = x + s * 36
                maxw = min(168, (W - 8 - lx) if right else (lx - 8))
                lines, prefix = label_lines(txt, maxw, count[0] if numbered else None)
                top = y + 6
                bot = top + max(20, len(lines) * LH - 2)
                body.append('<path d="M %.1f %.1f H %.1f V %.1f H %.1f" fill="none" stroke="%s" '
                            'stroke-width="1.45"/>' % (x, top, x + s * 26, bot, x + 2 * s, C['ink']))
                body.append(head(x + s * 26, bot, x, bot, C['ink'], 'filled'))
                for i, ln in enumerate(lines):
                    body.append(draw_text(lx, top + 9 + i * LH, ln, i, prefix, False,
                                          'start' if right else 'end'))
                y = max(bot, top + len(lines) * LH) + 14
            elif kind == 'note':
                _, who, txt = it
                if isinstance(who, (tuple, list)):
                    x1, x2 = X[who[0]] - 52, X[who[1]] + 52
                else:
                    x1, x2 = X[who] - 84, X[who] + 84
                x1, x2 = max(x1, 10), min(x2, W - 10)
                lines = wrap(txt, 11, x2 - x1 - 20)
                h = len(lines) * 14 + 12
                body.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4" fill="%s" '
                            'stroke="%s" stroke-width="1.2"/>'
                            % (x1, y + 4, x2 - x1, h, C['goldbg'], C['gold']))
                body.append(lines_at((x1 + x2) / 2, y + 4 + h / 2, lines, size=11, lh=14,
                                     fill=C['golddark'], anchor='middle', weight=500))
                y += h + 16
            elif kind in ('alt', 'opt', 'loop'):
                branches = it[1] if kind == 'alt' else [(it[1], it[2])]
                x0, x1 = 8 + depth * 10, W - 8 - depth * 10
                top = y + 2
                tagw = tw(kind, 10.5, True) + 20
                y = top + 24
                seps = []
                first_guard = ''
                for bi, (guard, sub) in enumerate(branches):
                    if bi == 0:
                        first_guard = guard
                    else:
                        seps.append((y, guard))
                        y += 22
                    y = render(sub, y, depth + 1)
                bottom = y + 2
                # No fill: the white patches behind labels would show on a tint.
                frames.append('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" fill="none" '
                              'stroke="%s" stroke-width="1.2"/>'
                              % (x0, top, x1 - x0, bottom - top, C['maroon']))
                # The tag and guards go with the body, over the lifelines.
                body.append('<path d="M %.1f %.1f h %.1f v 12 l -7 7 h %.1f z" fill="%s"/>'
                            % (x0, top, tagw, -(tagw - 7), C['maroon']))
                body.append(T(x0 + 8, top + 14, kind, size=10.5, weight=700, fill='#FFFFFF'))
                body.append(T(x0 + tagw + 8, top + 14, '[%s]' % first_guard, size=11, weight=600,
                              fill=C['maroon'], halo=True))
                for sy, guard in seps:
                    frames.append(line(x0, sy, x1, sy, C['maroon'], 1.1, '6 4'))
                    body.append(T(x0 + 8, sy + 15, '[%s]' % guard, size=11, weight=600,
                                  fill=C['maroon'], halo=True))
                y = bottom + 10
        return y

    y_end = render(steps, HB + 4, 0)
    H = y_end + 12
    life = ''.join(line(X[p[0]], HB - 4, X[p[0]], H - 6, C['line'], 1.2, '4 4') for p in parts)
    return svg(W, H, ''.join(frames) + life + ''.join(hdr) + ''.join(body), label)


# ================================================================ routing
NORM = {'l': (-1, 0), 'r': (1, 0), 't': (0, -1), 'b': (0, 1)}


def route(pa, sa, pb, sb, via=None, out=14):
    """An orthogonal path from side sa of one shape to side sb of another."""
    na, nb = NORM[sa], NORM[sb]
    p1 = (pa[0] + na[0] * out, pa[1] + na[1] * out)
    p2 = (pb[0] + nb[0] * out, pb[1] + nb[1] * out)
    pts = [pa, p1]
    if via:
        pts += [tuple(v) for v in via]
    else:
        ha, hb = sa in 'lr', sb in 'lr'
        if ha and hb:
            if sa == sb:
                xx = max(p1[0], p2[0]) if sa == 'r' else min(p1[0], p2[0])
                pts += [(xx, p1[1]), (xx, p2[1])]
            else:
                mx = (p1[0] + p2[0]) / 2
                pts += [(mx, p1[1]), (mx, p2[1])]
        elif not ha and not hb:
            if sa == sb:
                yy = max(p1[1], p2[1]) if sa == 'b' else min(p1[1], p2[1])
                pts += [(p1[0], yy), (p2[0], yy)]
            else:
                my = (p1[1] + p2[1]) / 2
                pts += [(p1[0], my), (p2[0], my)]
        elif ha:
            pts += [(p2[0], p1[1])]
        else:
            pts += [(p1[0], p2[1])]
    pts += [p2, pb]
    clean = [pts[0]]
    for p in pts[1:]:
        if abs(p[0] - clean[-1][0]) > .01 or abs(p[1] - clean[-1][1]) > .01:
            clean.append(p)
    return clean


def poly(pts, color, width=1.4, dash=None):
    d = ' stroke-dasharray="%s"' % dash if dash else ''
    return ('<polyline points="%s" fill="none" stroke="%s" stroke-width="%s" '
            'stroke-linejoin="round"%s/>'
            % (' '.join('%.1f,%.1f' % p for p in pts), color, width, d))


def path_label(pts, text, size=10, lpos=None, color=None):
    """Put a label on the longest straight piece of a path, or at lpos.

    lpos is (x, y) or (x, y, anchor); y is the first baseline. '|' in the text
    starts a new line."""
    if not text:
        return ''
    if lpos:
        x, y, anchor = (tuple(lpos) + ('middle',))[:3]
    else:
        best, seg = -1, None
        for a, b in zip(pts, pts[1:]):
            d = abs(a[0] - b[0]) + abs(a[1] - b[1])
            if d > best:
                best, seg = d, (a, b)
        (x1, y1), (x2, y2) = seg
        mx, my = (x1 + x2) / 2, (y1 + y2) / 2
        if abs(x1 - x2) < 1:
            x, y, anchor = mx + 6, my + 4, 'start'
        else:
            x, y, anchor = mx, my - 5, 'middle'
    return ''.join(T(x, y + i * (size + 2.5), ln, size=size, fill=color or C['grey'], italic=True,
                     anchor=anchor, halo=True) for i, ln in enumerate(text.split('|')))


# ================================================================ ERD
def card(p, side, kind, color):
    """Crow's-foot cardinality at point p on a box's side.

    '1' is two short bars (exactly one); 'N' is the three-pronged foot (many).
    """
    x, y = p
    dx, dy = NORM[side]
    px, py = -dy, dx
    out = []

    def bar(d, half=6):
        cx, cy = x + dx * d, y + dy * d
        out.append(line(cx - px * half, cy - py * half, cx + px * half, cy + py * half, color, 1.4))

    if kind == '1':
        bar(6)
        bar(10)
    else:
        tx, ty = x + dx * 13, y + dy * 13
        for s in (-1, 0, 1):
            out.append(line(tx, ty, x + px * 7 * s, y + py * 7 * s, color, 1.4))
    return ''.join(out)


def erd(label, tables, rels=(), notes=(), W=W_DEFAULT):
    """An entity-relationship diagram in crow's-foot notation.

    tables  [dict(id, x, y, w, name, sub, cols=[(name, type, key)], more)]
    rels    [dict(a, b, ca, cb, text, sa, sb, ra, rb, oa, ob, via, lpos)]
            ra / rb name the column row the line attaches to (sides l and r);
            oa / ob shift the attachment point along the side.
    notes   [dict(x, y, w, text)]
    """
    HDR, ROW = 40, 18
    boxes = {}
    for t in tables:
        w = t.get('w', 196)
        n = len(t['cols']) + (1 if t.get('more') else 0)
        boxes[t['id']] = (t['x'], t['y'], w, HDR + n * ROW + 8, t)

    def rowy(tid, col):
        x, y, w, h, t = boxes[tid]
        for i, c in enumerate(t['cols']):
            if c[0] == col:
                return y + HDR + 4 + i * ROW + ROW / 2
        raise KeyError('%s.%s is not drawn' % (tid, col))

    def anchor(tid, side, row, off):
        x, y, w, h, t = boxes[tid]
        if side in 'lr':
            yy = rowy(tid, row) if row else y + h / 2 + off
            return (x if side == 'l' else x + w, yy)
        return (x + w / 2 + off, y if side == 't' else y + h)

    out, labels = [], []
    for r in rels:
        pa = anchor(r['a'], r.get('sa', 'r'), r.get('ra'), r.get('oa', 0))
        pb = anchor(r['b'], r.get('sb', 'l'), r.get('rb'), r.get('ob', 0))
        pts = route(pa, r.get('sa', 'r'), pb, r.get('sb', 'l'), r.get('via'))
        out.append(poly(pts, C['line'], 1.4))
        out.append(card(pa, r.get('sa', 'r'), r.get('ca', '1'), C['maroon']))
        out.append(card(pb, r.get('sb', 'l'), r.get('cb', 'N'), C['maroon']))
        # Drawn after the boxes, so a label that brushes a box stays readable.
        labels.append(path_label(pts, r.get('text', ''), lpos=r.get('lpos')))

    bottom = 0
    for tid, (x, y, w, h, t) in boxes.items():
        bottom = max(bottom, y + h)
        out.append('<rect x="%s" y="%s" width="%s" height="%s" rx="6" fill="#FFFFFF" stroke="%s" '
                   'stroke-width="1.4"/>' % (x, y, w, h, C['maroon']))
        out.append('<path d="M %s %s h %s a 6 6 0 0 1 6 6 v %s h %s v %s a 6 6 0 0 1 6 -6 z" '
                   'fill="%s"/>' % (x + 6, y, w - 12, HDR - 6, -w, -(HDR - 6), C['maroon']))
        out.append(T(x + 10, y + 17, t['name'], size=12, weight=700, fill='#FFFFFF', family=MONO))
        if t.get('sub'):
            out.append(T(x + 10, y + 32, t['sub'], size=9.5, fill='#F6D9D9'))
        for i, (cname, ctype, key) in enumerate(t['cols']):
            ry = y + HDR + 4 + i * ROW
            if i % 2 == 1:
                out.append('<rect x="%s" y="%s" width="%s" height="%s" fill="%s"/>'
                           % (x + 1.5, ry, w - 3, ROW, '#FCF6F6'))
            if key:
                bg, fg = ((C['gold'], '#3D2A00') if key == 'PK' else (C['bluebg'], C['blue']))
                out.append('<rect x="%s" y="%s" width="22" height="12" rx="3" fill="%s" stroke="%s" '
                           'stroke-width="0.8"/>' % (x + 7, ry + 3, bg, fg if key == 'FK' else bg))
                out.append(T(x + 18, ry + 12.2, key, size=7.5, weight=700, fill=fg, anchor='middle'))
            out.append(T(x + 35, ry + 13, cname, size=10.5, weight=700 if key == 'PK' else 500,
                         fill=C['ink'], family=MONO))
            out.append(T(x + w - 8, ry + 13, ctype, size=8.5, fill=C['grey'], anchor='end'))
        if t.get('more'):
            ry = y + HDR + 4 + len(t['cols']) * ROW
            out.append(T(x + 35, ry + 13, '+ %d more columns' % t['more'], size=9.5,
                         fill=C['grey'], italic=True))

    for nt in notes:
        lines = wrap(nt['text'], 10.5, nt['w'] - 22)
        h = len(lines) * 14 + 14
        x, y, w = nt['x'], nt['y'], nt['w']
        out.append('<path d="M %s %s h %s l 10 10 v %s h %s z" fill="%s" stroke="%s" '
                   'stroke-width="1.1"/>' % (x, y, w - 10, h - 10, -w, C['goldbg'], C['gold']))
        out.append('<path d="M %s %s v 10 h 10" fill="none" stroke="%s" stroke-width="1.1"/>'
                   % (x + w - 10, y, C['gold']))
        for i, ln in enumerate(lines):
            out.append(T(x + 11, y + 18 + i * 14, ln, size=10.5, fill=C['golddark']))
        bottom = max(bottom, y + h)

    return svg(W, bottom + 12, ''.join(out) + ''.join(labels), label)


# ================================================================ flow / state
def flow(label, nodes, edges, H, W=W_DEFAULT, lanes=None):
    """State machine and swimlane activity diagrams.

    nodes  {id: dict(x, y, text, kind, w, sub)}   (x, y) is the centre
           kind: state | action | good | muted | start | end | decision
    edges  [dict(a, b, sa, sb, oa, ob, text, via, lpos)]
    lanes  [(title, x0, x1)]  vertical swimlanes with a header band
    """
    out = []
    if lanes:
        for i, (title, x0, x1) in enumerate(lanes):
            out.append('<rect x="%s" y="0" width="%s" height="%s" fill="%s" stroke="%s" '
                       'stroke-width="1"/>' % (x0, x1 - x0, H, '#FFFFFF' if i % 2 == 0 else '#FBF5F5',
                                               C['border']))
            out.append('<rect x="%s" y="0" width="%s" height="32" fill="%s"/>'
                       % (x0, x1 - x0, C['maroon'] if i % 2 == 0 else C['dark']))
            out.append(lines_at((x0 + x1) / 2, 16, wrap(title, 10.5, x1 - x0 - 12, bold=True),
                                size=10.5, lh=12, weight=700, fill='#FFFFFF', anchor='middle'))

    geo = {}
    shapes = []
    for nid, n in nodes.items():
        kind = n.get('kind', 'state')
        x, y = n['x'], n['y']
        if kind == 'start':
            geo[nid] = (x, y, 20, 20)
            shapes.append('<circle cx="%s" cy="%s" r="9" fill="%s"/>' % (x, y, C['ink']))
            continue
        if kind == 'end':
            geo[nid] = (x, y, 24, 24)
            shapes.append('<circle cx="%s" cy="%s" r="11" fill="#FFFFFF" stroke="%s" stroke-width="1.8"/>'
                          '<circle cx="%s" cy="%s" r="6.5" fill="%s"/>' % (x, y, C['ink'], x, y, C['ink']))
            continue
        w = n.get('w', 132)
        if kind == 'decision':
            h = n.get('h', 46)
            geo[nid] = (x, y, w, h)
            shapes.append('<polygon points="%s,%s %s,%s %s,%s %s,%s" fill="%s" stroke="%s" '
                          'stroke-width="1.5"/>' % (x, y - h / 2, x + w / 2, y, x, y + h / 2, x - w / 2, y,
                                                    C['goldbg'], C['gold']))
            shapes.append(lines_at(x, y, wrap(n['text'], 10.5, w - 26, bold=True), size=10.5,
                                   lh=12, weight=700, fill=C['golddark'], anchor='middle'))
            continue
        lines = wrap(n['text'], 11.5, w - 18, bold=True)
        h = 18 + 14 * len(lines) + (13 if n.get('sub') else 0)
        h = max(h, n.get('h', 0))
        geo[nid] = (x, y, w, h)
        fill, stroke, tcol = {
            'state': (C['cream'], C['maroon'], C['dark']),
            'action': ('#FFFFFF', C['maroon'], C['ink']),
            'good': (C['greenbg'], C['green'], C['green']),
            'muted': (C['slatebg'], C['slate'], C['slate']),
        }[kind]
        rx = 14 if kind != 'action' else 8
        shapes.append('<rect x="%.1f" y="%.1f" width="%s" height="%s" rx="%s" fill="%s" stroke="%s" '
                      'stroke-width="1.6"/>' % (x - w / 2, y - h / 2, w, h, rx, fill, stroke))
        ty = y - (6.5 if n.get('sub') else 0)
        shapes.append(lines_at(x, ty, lines, size=11.5, lh=14, weight=700, fill=tcol, anchor='middle'))
        if n.get('sub'):
            shapes.append(T(x, y + h / 2 - 8, n['sub'], size=8.8, fill=C['grey'], anchor='middle',
                            family=MONO))

    def anchor(nid, side, off):
        x, y, w, h = geo[nid]
        return {'l': (x - w / 2, y + off), 'r': (x + w / 2, y + off),
                't': (x + off, y - h / 2), 'b': (x + off, y + h / 2)}[side]

    labels = []
    for e in edges:
        pa = anchor(e['a'], e['sa'], e.get('oa', 0))
        pb = anchor(e['b'], e['sb'], e.get('ob', 0))
        pts = route(pa, e['sa'], pb, e['sb'], e.get('via'), out=e.get('out', 14))
        col = e.get('color', C['ink'])
        out.append(poly(pts, col, 1.5, e.get('dash')))
        out.append(head(pts[-2][0], pts[-2][1], pb[0], pb[1], col, 'filled'))
        labels.append(path_label(pts, e.get('text', ''), size=10, lpos=e.get('lpos'),
                                 color=e.get('tcolor')))

    # Lines, then shapes, then labels: a label must never hide under a box.
    return svg(W, H, ''.join(out) + ''.join(shapes) + ''.join(labels), label)
