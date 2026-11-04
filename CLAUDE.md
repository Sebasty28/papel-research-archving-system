# PAPEL — notes for Claude Code

Context for anyone (human or AI) picking this project up. Read this before
changing anything; several of the conventions below are not guessable from the
code, and a few are traps that have already cost time once.

---

## What this is

A research archiving system for PUP Biñan. Students submit papers, a Research
Adviser reviews them, the Research Coordinator approves and publishes them, and
a public repository makes the approved work readable.

**PHP 8.0.30 · MariaDB · XAMPP · vanilla JS · Chart.js.** No framework, no build
step. Files are served straight from `C:/xampp/htdocs/capstone`.

---

## The approval chain

```
Student uploads  →  Research Adviser  →  Research Coordinator  →  Published
                         (faculty)            (admin, level 1)
```

**Approval ends at the Research Coordinator.** The Head of Academic Programs and
the Director *read* what comes out; they do not approve. This was deliberately
shortened — do not add a fourth approval step without asking.

Returning a paper sets its status back to `draft` and attaches the reviewer's
feedback. So `draft` means either "never submitted" *or* "returned" — see the
`SUBMITTED` rule below.

### Roles

| Role in `users.user_role` | Who | Home page |
|---|---|---|
| `student` | Student | `app/student/student_dashboard.php` |
| `faculty` | Research Adviser | `app/faculty/faculty_review_dashboard.php` |
| `admin` + `admin_level=1` | Research Coordinator | `app/admin/admin_review_dashboard.php` |
| `admin` + `admin_level=2` | Head of Academic Programs | `app/faculty/head_review_dashboard.php` |
| `head_academic` | Head of Academic Programs | same page as above |
| `super_admin` | Director | `app/admin/super_admin_review_dashboard.php` |
| `librarian` | Librarian | `app/librarian/librarian_manage_guests.php` |

**Two roles do the HAP job** — `head_academic`, and `admin` at level 2. Both land
on the same desk. They have not been consolidated; be careful when writing a
role check that you cover both.

---

## Traps that have already bitten

### The `<style nonce="<?= ... ?>">` slicing trap

When finding the end of an opening tag in a PHP template, the first `>` is the
one inside `<?=`, **not** the end of the tag. Search for `?>">` instead.

### `.modal-backdrop` was a name collision

The site's login slide-in used a class literally called `.modal-backdrop` — the
same class Bootstrap generates. Every Bootstrap modal inherited its
`z-index: 1100` and rendered *underneath* its own veil. It is now
`.login-backdrop`. Do not reintroduce a class that Bootstrap also generates.

### Fixed panels sit behind the bars, not over them

The PDF panel (`includes/pdf_dock.php`) and Corpus (`#chat-widget`, upload page
only) are `position: fixed`, and the panel's top and bottom are set from script
on every scroll. The browser scrolls on its own thread and that script runs a
frame or two behind it, so on a quick scroll a fixed edge is briefly where a bar
has already arrived. With the panel in front of the bars, that showed as it
lying over the breadcrumb strip and the footer. The order, front to back, is:

| z-index | What |
|---|---|
| 903 | navbar while a panel is docked (900 otherwise) |
| 902 | footer (also always on the upload page), and the breadcrumb strip while a panel is docked below it |
| 901 | Corpus |
| 900 | the PDF panel |
| 880 | scroll-to-top button, the docked chat |
| 1 | the breadcrumb strip, its own level, while the PDF panel is docked right over it |

**The PDF panel sits over the breadcrumb strip when it can.** Docked to the right
with the breadcrumb links clear of it, it starts right under the navbar, over the
strip's right-hand end (`pdf-over-strip` on `<body>`, decided by `decideOver()` in
`pdf_dock.php` whenever a width could have changed). Its top is then the navbar's
own edge, which never moves, so there is nothing for a scroll to leave behind.
Docked to the left (the links are at that end), or too narrow to leave them clear,
it starts below the strip, follows it up as it scrolls away, and the strip is in
front of it, as the table above says. The docked chat is always the second kind.

The pinned panel has **no border and no background of its own**: its header and
stage cover it completely, and either one shows as a pale hairline against the
maroon strip (the 1px `--border` always; a white ground wherever the inner edge
falls between two device pixels, which happens at 110% to 175% zoom in some window
widths). The in-page card keeps both. Check such an edge by reading pixels across
it at several zoom levels, not by eye at 100%.

**A CSS variable written on `<html>` is a page-wide restyle.** Custom properties
inherit, so changing one on the root makes the browser re-check every element on
the page, including the PDF panel's text layer (thousands of spans for a
thesis). The panels' edges were written that way on every step of a scroll: 50
to 100ms a time with a thesis open, 2 seconds of style recalculation over one
scripted scroll, and the panels trailed the bars by several hundred
milliseconds (headers sliding under the strip, gaps above the footer). The
PDF panel's edges are now plain `top`/`bottom` written on the panel itself
(`placeTop()` in `pdf_dock.php`), and the chat's `--chat-lift` and
`--chat-dock-top` live on the chat and are declared non-inheriting
(`@property`). Anything that changes on every scroll step goes the same way,
never on `:root`. Test it with a long PDF open: a one-line test PDF has no text
layer to restyle and hides the problem entirely.

A section in full view on the upload page (a phone only, now: see the two panes below) is at 800, under all of these, so while one is
open the footer, the breadcrumb strip and the theme card are hidden (`visibility`, so the
locked page keeps its height). Without that, a footer on screen when the section was opened
lay across its bottom, which is what a short Step 2 shows the moment a PDF arrives.

**The upload page's two panes** (`window.papelSide` in `student_upload_ai.php`, wider than 900px).
The form's card is the **main pane** and a card beside it the **side pane**, each with a strip of tabs
like an editor's. **Every tab opens in its home place** (`HOME` in `papelSide`, applied by `homeTab()`
each time a tab opens): main, in this order, **Upload Paper** (the form; it is the page, so it never
moves and cannot be closed), **Writing View** (while a section is open for writing), the **PDF**
(when a file is attached), then **Preview** (no close), which is last because it is the one tab
about the finished paper rather than about writing it, so nothing opening should push it along;
side, **Contents** (no close), then **Corpus** (when he is opened). The owner asked for this order to hold whenever a tab opens. The side pane is on the **left
by default** (`papel.sideSide` keeps a move to the right).

- **Any tab but Upload Paper can be dragged** to the other pane or along its own: pointer events
  followed on the document (the strips are redrawn as it moves), a ghost under the pointer and a bar
  where it will land; let go over nothing and it stays. A drag lasts while the tab is open: closed
  and opened again, it is back in its home place. Where each was dragged used to be kept on the device
  (`papel.paneTabs`) and is no longer; the page clears that key. A side pane with no tabs left is not
  drawn, and the form takes its room.
  **While a tab is dragged and there is no side pane on screen** (emptied, or folded into its rail),
  a drop zone stands where it goes, below the main strip (from the card's top it covered Upload Paper
  and the tabs after it) (`.side-drop-zone`, checked before the panes in `targetAt()`):
  without it, a side pane emptied by dragging could never be given a tab again. Dropped there, the
  tab makes the side pane (unfolded). The marker for a strip with no other tab stands where the tab is, or after the strip's
  buttons, never at its left end before them. The floating Corpus button is hidden wherever the panes are
  (`body.panes`), not only while the side pane is shown, or it came back once the pane was emptied.
- **Every panel is laid over its pane's body** (`.in-pane`, at the box `layout()` measures, written as
  `--pane-*` on the panel itself), so one panel shows in either pane without being moved in the page.
  The form's card was split once, on load, into the strip and `#mainBody` (the form moved into it; a
  form moved in the page keeps what is typed and chosen, files included). Another tab on view in the
  main pane hides the form under it (`#mainBody.is-covered`). Switching tabs only hides
  (`.side-hidden`), so the PDF keeps its page, Corpus his conversation, a section its text.
- **A section on view in the Writing View is moved out of its step.** Every step
  but the one on screen is `display: none`, which hides a fixed box as surely as
  any other, so leaving step 2 with the tab open left the pane blank and the text
  apparently gone. While the section is the pane's page it lives in
  `#docPaneHolder` (`toHolder()` / `fromHolder()` in `papelSide`), and goes back
  exactly where it was when it is not. The holder is **inside `<form>`**, which is
  what makes the rest of it work: the hidden field that carries what was typed
  still posts, and step 2 shows the edit the moment the box goes home, because it
  is the same box.
- **Writing View is the section editor itself.** In panes, `toggleExpand()` sets `doc-writing` instead
  of the full view's `doc-expanded`, so none of the full view's window-covering rules apply (bars,
  chat and contents hidden, page padding taken away). It raises `papel-full-state` on every open and
  close, and calls `papelSide.adopt()` **before** giving the section the cursor: placed a frame later,
  the box was still in the form's body under the Writing View tab, and a hidden box cannot take focus.
  A section given up for another loses `.in-pane` (`layout()`), or it stayed pinned over the pane.
  **While its tab is not on view the section is parked**: put back in its place in the form
  (`park()`, a quiet `papelDoc.collapse()`), editable there, the tab kept, and taken out again when the
  tab is shown (`unpark()`, then the cursor once it is placed). It used to leave a blank box in the form
  the whole time it had a tab. The history counts a parked section as open (`viewNow()` asks
  `papelSide.parked()`) and closes it as one (`dropParked()`), or Back from it skipped a step. While the
  Writing View is beside the form, the section's place says so (`.doc-spacer-note`, with a way there).
  Folding the side pane hides only panels in it (`:not([data-pane="main"])`): a PDF moved to the main
  pane went blank. The PDF's file name in the form brings a PDF open behind another tab to the front
  and puts it away only when it is on view. A tab not on view keeps its close's room (`visibility`,
  shown on hover), so a tab is one width either way and the close never appears under the pointer of a
  double click. The
  tab's close is `papelDoc.exitFull()`, which history hears as it hears Escape. Its list is headed
  "Sections you are submitting". **On a phone** there are no panes: the full view covers the screen as
  it always did, and the side pane covers the page while the PDF or Corpus is open.
- **The room** is worked out in script (`room()`, `--room-l` / `--room-r` on `<body>`, applied with
  `!important` under `body.panes`, and fed to `--chat-inset-*` and `--pdf-dock-room` so the bars still
  run full width). The side pane's width is measured, not read: `--pdf-dock-w` can be
  `min(46vw, 46rem)`, which is not a number. Both strips are one fixed height, or the panels under
  them moved a few pixels with the tab on view.
- **The strips are in front of the pages**, so the tab on view can lie over its page's top edge. The
  panels are at 869, the main strip is raised to 875 (the form's card makes no stacking box of its own),
  and the side pane is a *frame*: a cream border round an open middle, `pointer-events: none` but on its
  strip, with the panels showing through from underneath. With the pages in front (at 899, as first
  built) the page's edge ran across the foot of the tab, and at 125% and 150% a row of the frame showed
  in the join wherever it fell between device pixels. Check a join by reading pixels at several zoom
  levels (`ui34_tabjoin.py`), not by eye at 100%. **A control that injects CSS must use the CSSOM**
  (`styleSheets[n].insertRule`): the CSP has no `'unsafe-inline'` for styles, so a `<style>` element
  added by script is ignored and the control silently proves nothing.
- **No page in the panes has a border.** The white (or cream, or maroon) page against the cream
  frame is its own edge, and a 1px `rgba(177,125,125,.22)` round each one read as a faint line under
  the tabs and down the sides. `#mainBody`, `.side-toc-body`, the docked `#chat-window`, the Writing
  View's `.doc-editor.is-expanded.in-pane`, `.side-preview` and `#pdfDock.in-pane` all carry `border: 0`.
  Judge this by reading pixels across an edge, not by eye: a divider in the form's own content can
  scroll to sit exactly on the page's top edge and is not a line round the panel.
- **Pages keep the cards' corners all round**, the top as the foot: no tab meets one at a corner but
  the main pane's first, Upload Paper, which is flush with its page's left edge (the main strip has no
  padding before it), so that one corner is square only while Upload Paper is on view.
- **Every tab is one box, on view or not** (as in an editor): a tab not on view has the same shape,
  border and 2px overlap as the one on view, with a light fill, so pressing one changes only its colour.
  It used to float 3px above the page's edge and the pressed one grew down to meet it. **A tab not on
  view fills only down to the strip's foot** (a gradient to `transparent` at `calc(100% - 2px)`): its
  fill is translucent, so over the 2px it overlaps the page it tinted the page itself and hung a band
  below the strip under every such tab, which reads as the strip seeping onto the page. The tab on
  view needs no such care: its fill is the page's own colour.
  The tab on view **flares into its page at its foot** (`::before`/`::after`, a quarter circle filled
  outside its rim in `--tab-bg`, the rim a pixel of fade), **only where no tab sits beside it**
  (`.side-tab + .side-tab.is-active::before` and `.is-active:has(+ .side-tab)::after` are
  `display: none`). A foot is the page's colour, so over a neighbour it bit a notch out of that
  tab's bottom corner: white out of the pink tabs, maroon out of the one beside the PDF. Stacking
  does not fix it, since a tab not on view is a translucent fill (8% over the strip) that the foot
  shows straight through. Between two tabs the tab on view now meets its neighbour square, as in an
  editor, and still flares at either end of the run. **No outline** on the tab on view or its
  feet: a line round it, however fine, read as harsh, and an editor's tab has none; its border is
  1px and transparent so the box is the same. Upload Paper has no left foot: its page corner is square.
- **Joined tabs**: the tab on view is rounded at the top only, laid **2px** over its page's edge (1px
  left half a row of the edge showing at 150%), in the page's colour (white for the form and the contents and Corpus, cream for the Writing View, the maroon
  band for the PDF). A name that does not fit fades out (`fadeNames()`, a mask), except the PDF's:
  longer than its window, it runs through one like an LED sign: seven of its own letters wide in the
  side pane, twelve in the main one (`data-letters`; `.is-marquee`, two copies on one track, sized
  and paced by `sizeMarquee()`, the 7ch floor lifted so narrow letters do not widen it), stops under the
  pointer, and takes its place in the loop from the clock, since the strip is redrawn on every drag
  and every tab shown. The tooltip is the whole name.
- **The side pane** resizes from one handle on its inner edge (`#sideGrip`; mouse, finger, pen or the
  arrow keys; kept as `papel.pdfDockWidth`, which the PDF opens at), folds into a rail
  (`papel.sideCollapsed`; a button per tab and one to unfold), and moves sides. Closed, Corpus is his
  round button at the end of the side strip (the main strip's when there is no side pane, the rail's
  when it is folded, never two), and his greeting lines (`chatNudge`) rise from whichever button is in
  sight (`anchor()` asks for one that is *seen*). The PDF and Corpus open in the panes and nowhere else: no
  dock buttons, no floating chat; their own close buttons are hidden since the tabs close them, and
  Escape closes only the tab on view (`pdf_dock.php` leaves a `.side-hidden` panel alone).
- **The form is held in place** (`lockForm()`, `html.side-locked`): its top a gap (`--dock-gap`, 6px)
  under the bars, its foot a gap above the window's, `#mainBody` scrolling and the page not at all.
  `goToStep()` scrolls the pane's body to its top (`papelSide.toTop()`). **The footer is hidden while
  it is held**, so the page is no taller than the window and nothing (`scrollIntoView` scrolls every
  box round its target) can slide the form under the bars. Anything that reads how much of the footer
  is showing treats a hidden one as none (`getClientRects().length`).
- **The contents** (`#sideToc`) is read from the form as it stands (`STEP_PARTS`), redrawn on a change
  of step or of the form's classes and hidden attributes, never for typing in a section. A step is
  reached as the progress bar reaches it; a part is scrolled to in the main pane's body (the form's
  tab put on view first) and given the cursor.
- **The preview** (`#papelPreview`) is the paper's page in the Public Repository as it will be once
  published, drawn by **that page itself**: `app/student/paper_preview.php` builds the paper from the
  posted form the way submit does (sections outside the choice left out) and requires
  `archive/view_paper.php` with `PAPEL_PAPER_PREVIEW` defined, which skips the page's fetching,
  access checks and post handling, draws the manuscript as a student reader sees it, hides the
  site's extras and stops every link out. So a change to the public page shows in the preview with
  no change here. The form's typed fields (never its PDFs) are posted by a form made for the moment,
  700ms after the last change and whenever the tab comes into view, and only while it is on view.
  **Each drawing goes into a new frame**, swapped in once loaded: posting into the same frame again
  made every redraw a history entry, so Back stepped through old previews instead of the form's
  steps (a new frame's first page replaces its blank one). It is drawn at 1280px and scaled to the
  pane ("Desktop"), or at the pane's width ("Fit"), kept as `papel.previewSize`.

Tested by `ui31_panes.py` and `ui32_panes_more.py` (scratch scripts, not in the repository): the
layout, every drag, the Writing View, resizing, folding, the sides, the contents and the phone.
Several of ui31's checks compare the main strip's tabs exactly and predate Preview.

Above all of them sit three full-window layers, in this order: the loading
skeleton at 2147482000, the splash at 2147483000 and the loading bar at
2147483100. Put a new fixed element on the ladder above instead of reaching
for a bigger number.
Corpus follows the footer's visible height (`--chat-lift`, set by
`watchChatLift()`): floating, he is lifted by it, so he rests above the footer
rather than being buried by it on a page short enough to show it all the time;
docked, the panel's bottom edge is that figure, so it ends at the footer's top.
The footer runs the full width in front of a docked chat, as the navbar and the
strip do, and as it already did for the PDF panel.

**`overflow-x` clips the other axis too.** `.dash-tabs` scrolls sideways when the tabs do not
fit, and `overflow-y` then computes to `auto`, never `visible`: the Quick Settings panel that
hangs out of that row was cut to the row's own height, so on every dashboard the gear opened a
panel nobody could see or press. It does not clip while the panel is open
(`.dash-tabs:has(.quick-settings-dropdown.open)`), having no need to scroll just then.

Anything that must sit flush under the navbar reads `--pdf-dock-top`, the bar's
measured bottom edge that `pdf_dock.php` keeps on `<html>`, and never a number.
The expanded section used `60px` against a bar of 37px, so 23px of the
breadcrumb strip showed above it as a gap; on a phone the bar is 61px, and a
`top: 0` override left the box's own toolbar underneath it.

**Lock the page on `<html>`, never on `<body>`.** A section in full view on the upload page
holds the form behind it still with `overflow: hidden` on `<html>`. Put on `<body>` too, the
body becomes a scroll box of its own, and the navbar, which is `position: sticky`, scrolls away
under the box; `pdf_dock.php` then measures its edge as 0 and keeps that. The page's own dialog
sets `body.style.overflow` while it is open, so the full view also forces `<body>` back to
`visible`. In full view the section tabs run down the left (a row along the top on a phone), and
only sections being submitted have one: a tab for a section left out "expanded" a hidden box,
which looked like the full view closing itself.

### The step bar pins itself and folds down

**In the panes it is a row of its own.** `placeStepBar()` lifts `.upload-steps`
out of the scrolling page and puts it between the tab strip and `#mainBody`, and
puts it back at the top of the page when the panes go away. Sticky inside the
page was the first build, and the form slid underneath it: the white card's top
edge, its title and its Step heading all went behind the bar, which is a bar
covering the thing it labels. The only way to stop a pinned bar covering the page
below it is to take it out of that page. It belongs to the form, so
`.main-tabs:not([data-on="form"]) + .upload-steps` hides it with the form, and
the radius rule that joined the strip to the body is now `~` and not `+`, since
the bar is lifted in between them.

Where there are **no panes** the window is the scroller and there is no row to
put it in, so it stays `position: sticky` with its `top` at `--pdf-dock-top`, the
navbar's measured bottom edge, never a number; the breadcrumb strip is
`position: relative` and scrolls away, so it is not part of the sum. It still
rides over the card there.

Once the form has scrolled, `.is-stuck` folds the card to one line in the same
place: each step becomes a row rather than a column, the circles go 44px to
24px, the connectors shorten. 101px becomes 38px. A threshold with room either
side of it (12px down, 4px back) keeps a form resting a pixel off the top from
flipping it open and shut on every notch, and the work is a class on that card,
never a custom property on `:root`.

Two things that cost a build each:

- **A scroll event does not bubble**, so a listener on `window` hears nothing in
  the panes, where the window never scrolls at all. It is one listener on the
  `document` in the **capture** phase, which hears both. Naming `#mainBody`
  instead would mean waiting for `papelSide` to build it, and this block is
  parsed long before that.
- **The bar fails to fit in two different ways**, because it is laid out two
  ways: `flex-wrap: wrap` in the panes, where it never overflows and the sign is
  the last step dropping below the first; `nowrap` with `overflow-x: auto` under
  768px and in a narrow upload column, where it does not wrap and the sign is
  the overflow. `tooWide()` tests both. Then `.is-tight` leaves only the step you
  are on named, and `.is-min` drops the rules between the circles.

### Back and Forward on the upload page go through the history, never `location.href`

Each step of `student_upload_ai.php` is an entry in the browser's history, and so is a section
in full view (`{papelUpload: 1, step, full, idx}`, on top of a `base` entry for the arrival).
Back goes to the step before or out of the full view, Forward to the next one (running the
Next button's checks, and stopping at an unfinished step), and Back from the first place is
leaving: asked only when there is work on the page, and done with `history.go()`. It used to
leave by setting `location.href` to the referrer, which is a new visit: the dashboard went on
the history twice, Back from it came straight back to the form, and Forward had nothing left.
`goToStep()` raises `papel-step` and the full view `papel-fullview`; the history listens, and
the popstate handler changes the page without making new entries. Test it with
`Page.navigateToHistoryEntry` over CDP, which is the browser's own Back and Forward.

**A notice that names something missing takes the student to it.**
`papelAlert(message, { field })` hands the box to `papelNeedsFill()` once the
dialog has closed, never while it is up: a scroll behind the dialog finishes
before anyone can see it happen. The field's whole column is washed (`.needs-fill`,
a `box-shadow` so nothing moves, mixed from `--maroon` so the dark theme holds)
and the wash comes off the moment the student touches the group. Leaving it up
while they type would make a box they have just answered still look wrong, which
is why `papelDoc.flag()` now clears its `is-invalid` on the first keystroke too.
`field` may be a function instead of a box, for something that marks itself.
One wash at a time: a second notice moves it.

The page's dialog keeps its width and buttons until it has faded out (`plainBox()` runs on the
next open, or 260ms after a close). Stripping them at the start of the fade showed the text
reflowing into the narrow box over the page. A question with a third answer (the leave
question) shows it as a labelled button ("Discard draft and leave"), and the X in the corner
only closes. The X used to *be* the discard, and anyone pressing it to dismiss the question
lost their draft and left.

### One wheel event is not one notch

`assests/js/papel-pdf-view.js` zooms on Ctrl + wheel, in the full-window viewer and in the
panel alike. It used to take a whole step for every wheel *event*, and a touchpad or a
free-spinning wheel sends dozens a second: a one-second flick went from 100% to the 25%
floor in 35ms, and a gentle pinch from 100% to 500% in 200ms. The movement is now gathered
(a mouse notch is 100, a step needs 40) and two steps are never closer than `WHEEL_GAP`
(140ms), so a touchpad and a mouse zoom at the same pace as the buttons, about seven steps
a second at most. Both share `STEPS`, the browser's own ladder (25, 33, 50, 67, 75, 80, 90,
100, 110, 125, 150 ...); it is finer than the old 25/50/75/100 on purpose, so do not go back
to jumps of a quarter or a half of the page.

Test it with real wheel events on a fixed clock: `Input.dispatchMouseEvent` with
`modifiers: 2`, sent **without waiting for each reply**. Calling `zoomIn()` in a loop skips
the wheel code altogether, and a driver that waits for every event lets a slow redraw pass
for a slow hand. A paper's PDF comes from Drive, which is not always to hand: `pdf_viewer.php`
with no `?paper=` takes a local file by `postMessage({type: 'papel-pdf-file', file:
<ArrayBuffer>, name})`, the way the upload page hands it one, which is enough to test with.

### `style="..."` attributes are dropped

`style-src` in the CSP has no `'unsafe-inline'`, and that blocks style
*attributes* in markup just as it blocks an unnonced `<style>` element. A div
carrying `style="display:flex;margin-bottom:.75rem"` computes as `display:block`
with `margin-bottom:0px`. It fails silently, so it reads as a layout bug.

Anything positional goes in a `<style nonce="<?= csp_nonce() ?>">` block or the
shared stylesheet. Setting `el.style.x` from JavaScript is fine, since the CSSOM
is not covered by the policy — which is why some of this looks like it works.

About 59 of these attributes are still scattered through the app. Most are
harmless (duplicating a rule the stylesheet already has) but none of them do
anything.

### An invalid ENUM value is silently thrown away

MariaDB runs here without `STRICT_TRANS_TABLES`. Writing a value the ENUM does
not list stores an **empty string** and the insert still reports success; the
only sign is a warning nobody reads.

`notifications.notification_type` accepts `submission`, `approval`, `decline`,
`reminder`, `comment`, `manuscript`, `security`, `support`, `account`. The
approval path passed `progress` for months, so every "your paper is published"
notice was stored with no kind and drew the generic bell in the notification
centre instead of the approval tick. Repaired by
`scripts/migrations/run_notification_type_repair.php`.

Check the column's ENUM before inventing a value for it.

`supporting_documents.document_type` went wrong the other way round: the ENUM
did not list `copyright_doc`, and a named optional document was written under
its own name, so both were stored as `''` and `supporting_doc_label()` quietly
read `''` as the copyright document. It is now a `VARCHAR(50)` beside a
`document_name` column (`run_supporting_doc_type_repair.php`). The `''` fallback
in `supporting_doc_label()` stays for a server that has not run it yet. A new
"kind" column should be a `VARCHAR` checked in PHP, as `paper_manuscripts.format`
is, so a wrong value fails loudly instead of vanishing.

### A draft lives in two places, and both need the id

`student_upload_ai.php` keeps the student's work twice: a row in
`research_papers` with `current_status='draft'`, and an autosaved copy in the
browser's `localStorage`. The device copy carries the row's `paper_id` in its
`draftId` field, and that field is the only thing that makes the next save an
UPDATE rather than an INSERT.

So anything that writes a draft to the server has to write the id back into the
device copy too. Updating only the page's own `draftId` variable left a copy
tagged 0 behind: the student saved, left the page, came back, answered
"Continue", and the same paper was written a second time as a new draft.

A draft holds **no PDF**, on the server or on Drive. The files stay in the
browser: coming back to a draft, the student attaches them again, and the
"Continue your draft?" prompt only lists the names they had used. That is
deliberate (nothing is stored for work nobody has handed in), so do not add a
"keep the saved file" path without asking. `save_draft` takes no `$_FILES`.

### "Upload failed. Please try again." hid its own cause

That line is the submit handler's catch-all: **every** exception that is not a
`UserFacingException` becomes it, and the real cause goes only to the server log,
against a reference. On staging (InfinityFree) that log is not somewhere anyone
testing can look, so a failure read as unexplained, and the page also prefixed its
own "Upload failed:" and dropped the reference.

- A message a person can act on is a `UserFacingException` (keywords, dates, the
  sections, Drive not connected). A plain `Exception` is a fault, and says nothing.
- `safe_error_message()` adds `detail` (the class, the message, where, and the
  wrapped error inside it) on **any copy of the site that is not production**, never
  on production and never for a `UserFacingException`. Handlers send the whole array
  (`['success' => false] + $safe`), and `papelFailureText()` puts message, reference
  and cause in the dialog. Do not go back to picking out two keys by hand.
- **Nothing after the commit may report a failed upload.** The paper is filed by
  then; the tail (old Drive files, the adviser's notification) sits in its own
  `try`. It used to fall into the handler's catch and answer "Upload failed" for a
  paper that was in fact filed, which invites filing it twice.
- Drive is asked about first (`is_gdrive_connected()`), before anything is stored. A
  copy of the site has its own database and its own Drive token, written once by
  hand, and nothing sets it up when the code is deployed.
- PHP 8.1+ makes mysqli **throw** on a failed query; 8.0 (here) stays silent and
  returns false. Staging runs 8.4, so a statement that fails quietly here can end a
  request there.
- **The 500 page and the JSON error say the cause too, off production.** An uncaught
  exception used to end as a styled page with a reference and nothing else, so a failure
  on staging could only be read from a server log nobody testing can open.
  `show_safe_error()` now takes the cause as a third argument, built by
  `error_detail_for()` (an exception) or `error_detail_plain()` (a fatal caught at
  shutdown); both return null on production and for a `UserFacingException`.
  `pages/error_page.php` shows it in a "Cause" box and the JSON branch adds `detail`.

**The first real cause, on staging, was the storage folder.** Drive was connected, and
every paper still failed: the cause the dialog finally showed was Drive answering
`404 File not found` to the folder ID in `system_settings.gdrive_parent_folder_id`, a
folder in a Google account the connected one cannot see. The connection and the folder
are two separate settings and fail separately.

- `gdrive_check_folder()` asks Drive whether the connected account can add files to a
  folder (`ok`, `unset`, `not_found`, `not_folder`, `trashed`, `read_only`, `unknown`).
  `unknown` means Drive could not be asked and is **not** a verdict; only the four
  definite faults (`gdrive_folder_is_unusable()`) stop a submission.
- The Storage Folder page (`app/admin/gdrive_settings.php`, **Director only**) checks a
  folder before saving it and refuses with the reason; it also shows whether the current
  one is usable, since "connected" says nothing about that. The Coordinator can connect
  Drive (`pages/gdrive_callback.php`) but cannot change the folder.
- A submission checks the folder first and tells the student in words, without the ID.

**Setting up a copy of the site** (staging, production) needs three things by hand, none
of which a deploy does: the database, the Drive connection (Reconnect on the Storage
Folder page, signed in as the Google account that owns or can edit the folder), and the
storage folder, which is **different on each copy** and must be one that connected
account can edit. Open the folder in that account's Drive to be sure before pasting it.
A deploy leaves `.env` and `config/gdrive_token.json` alone (see
`.github/workflows/deploy-staging.yml`), so they have to exist on the server already.

### A copy of the database can be behind the code

Staging was built from a database export on 14 October. Two schema changes came a
week later (`supporting_documents.document_type` became a `VARCHAR` and gained
`document_name`; `paper_manuscripts` was created), and staging has **no shell to run a
migration script from**. `scripts/**` is not deployed either (`deploy-staging.yml`), and
it must stay that way: several of the older scripts have no web guard. So its database
stayed as it was, five queries named `document_name`, and the Research Adviser's desk
ended in the "Something Went Wrong" page for any adviser with a paper waiting. Nothing
showed it here, where the migration had run. The first sign was a faculty login that
"went to an error".

A query that names a column or table a migration added breaks on every copy that has
not run it. So a migration ships with four things, not one:

- **The structure** in `database/schema.sql`, so a copy built from now on is right.
- **The SQL**, in `scripts/migrations/sql/`, for phpMyAdmin (the only tool such a host
  gives you). Each step checks `information_schema` first and skips itself, so it is
  safe to run twice, and the file ends with a line saying what it did.
- **A gap check** in `schema_gaps()` (`config/core.php`): what is missing, what that
  does to the site, and the statement that closes it. Staff see it under "Database" on
  `app/student/upload_diagnostics.php`, which reads "No gaps" once the SQL has run.
- **A tolerant path** until every copy is known to be caught up. Ask
  `supporting_documents_have_name()` or use `supporting_doc_name_sql()` before naming
  the column in a SELECT, and `supporting_doc_type_for_storage()` before writing a kind
  the old ENUM cannot hold (it files the document as `other` and logs why). Remove the
  tolerance only when nobody is behind.

**`schema.sql` itself can be behind the live database.** `manuscript_requests` was made by
`run_manuscript_requests_migration.php` and never exported into `schema.sql`, so staging, built
from that file, never had it. The public paper page asks about a student's grant on every visit,
so on staging every student who opened a paper in the repository got the error page, while
staff, who skip that check, were fine and the dev box showed nothing. Now
`manuscript_requests_ready()` makes the helpers answer "no requests" without the table, and
`2026-10-27_manuscript_requests.sql` adds it. After any migration, compare the live database's
tables and columns (`information_schema.COLUMNS`) with what `schema.sql` creates, not only the
change you made.

**phpMyAdmin looks at the last statement of a pasted script.** If that statement reads
from a database (`FROM information_schema.COLUMNS`), it switches to that database before
running *any* of the script, so every `DATABASE()` above it answered `information_schema`,
step 1 skipped itself, and step 2 was refused with `#1044 Access denied ... to database
'information_schema'`. The mysql client does not do this, which is why it passed there.
End such a script with a statement that reads no table (the catch-up file's closing
`SELECT CONCAT(...)` of session variables). Test a pasted script in phpMyAdmin itself, not
only with `mysql < file.sql`.

### Text cut by bytes is not text

`substr()` counts bytes. Cutting a PDF's text at a fixed length splits an "ñ", a "₱" or a
curly quote whenever one falls on the line, and the whole string is then invalid UTF-8:
every `/u` pattern reading it fails silently, and `json_encode()` returns **false**, so the
request to the AI went out with no body and the student read "The AI could not identify the
details in this document" (one in sixteen of the papers on file). Cut with `pdf_text_clip()`
(`config/groq_config.php`: scrubs invalid bytes, then `mb_strcut()`), and send JSON with
`JSON_INVALID_UTF8_SUBSTITUTE`.

**Extract with AI** fills the title, authors, year and keywords from the AI, and the Abstract
from `extract_abstract_from_text()`, which copies it word for word or returns null. Null means
only the metadata is filled: the Abstract box keeps what the student typed (and in Custom
without an Abstract nothing goes in it). A wrong abstract is worse than none, so the finder
skips table-of-contents lines, takes off a thesis page's Title / Researcher / Adviser block and
its running head, stops at the next heading, and gives up on a heading with no prose under it.
Each rule is a real paper it once got wrong; `tests/_abstract_unit.php` holds them.

### One pool of AI keys, not a key per feature

Every AI feature on the site draws from **one pool** of Groq keys, tried in
order: `GROQ_API_KEY_1` to `GROQ_API_KEY_8` in `.env`, blanks skipped, read into
`GROQ_API_KEYS` by `config/groq_config.php`. Four are set.

It used to be a key per feature — `GROQ_API_KEY_UPLOAD`, `_CHATBOT`, `_ADMIN`,
`_FACULTY`, `_SUPERADMIN`, `_STUDENT`, and two `_2` backups. That sounded like
isolation and was not. A feature whose own key was refused simply stopped, with
five other keys sitting untouched, because only two of the eight had any
fallback at all and each fallback was a single named key. The owner's reason for
replacing them was that the eight behaved as one allowance between them; that
was not measured before the change, and the eight old keys are no longer in
`.env` to measure. What *was* measured is below. The old names are still **read**,
last and in order, so a `.env` that has not been rewritten still has AI. Nothing
in the app names them.

**The pool is the fallback.** `groq_pool_request()` in `config/groq_config.php`
is the only place that logic lives, and `call_groq_api()` is a wrapper over it.
A caller says where to *start* — a key, an engine number, or null for the
session's own engine — and never what to be limited to. `groq_key_chain()` then
orders the whole pool from there, wrapping round, every key once.

What moves the call on to the next key, and what does not:

| Answer | What happens |
|---|---|
| 429 rate or daily limit | next key, at once |
| 401 / 403 | next key, and a loud log line: the pool is shorter than `.env` looks |
| 5xx | next key |
| 400, 404, anything else | **stop.** The payload is at fault; four keys asked the same bad question is four keys wasted |
| connection error | **stop.** The network is not key-specific |

**Do not wait out a 429 before trying the next key.** Groq's reply says how long
that key wants, and the obvious reading — honour it, then fall back — is
backwards. Measured on the four keys in `.env`: key 1 refused a large request
for its tokens-per-minute window while keys 2, 3 and 4 answered the *identical*
request immediately, so the next key is quicker than any wait. The shortest wait
any refusing key quoted is kept and taken **only once every key has refused**,
which is the one moment there is nothing better to do. Driven against a stub
endpoint: four refusals, a 3s pause, an answer on the key that asked for the
least time — 3.3s in all.

**One call has a time budget**, `GROQ_CHAIN_BUDGET` (25s), covering every
request and wait in the walk. Free hosting cuts a request off at about 30
seconds and answers with its own page, which carries no JSON and so tells the
student nothing. Each request's `CURLOPT_TIMEOUT` is what is left of the budget,
and the walk stops rather than begin a request it has no time to finish.

**The engines are the pool, named.** `ai_engine_names()` is one name per key
(Aincrad, Alfheim, Gun Gale, Underworld, then four more if the pool grows), used
in log lines and for PUPPY's colour on the upload page. The session holds which
engine a call *starts* on, and `ai_engine_current()` clamps a stale number to
the pool's length — a session held over from a longer pool would otherwise start
a call on no key at all. After a fallback, call `ai_engine_adopt()`, **not**
`ai_engine_switch()`: adopt moves the session to whichever key actually answered
(`groq_served_engine()`), while switch steps on by one and can land on a key the
walk has already passed over as spent.

There are only **two** PUPPY dogs drawn (maroon and yellow) and four engines, so
the colours alternate: odd engines maroon, even yellow. A missing colour asks
for a GIF that is not there and leaves a broken image, which is worse than two
engines sharing a face.

**Whether a pool buys anything is a measurable question, so measure it.** Keys
in one Groq account could share that account's allowance however well the pool
rotates. Fire one request per key and compare Groq's own
`x-ratelimit-remaining-requests` and `-remaining-tokens` headers: counters that
move independently mean independent windows. For the four keys in `.env` as of
2026-11-04 they do — key 1 ran 998, 997, 996, 995 over four of its own calls
while keys 2 and 3 each still reported 998, and key 1 refused a large request
for its token window while the other three answered the same request at once. So
these four really are about four times the capacity. Re-measure after minting new
ones; do not assume it.

`ai/groq_config.php` is a stale duplicate of all this that nothing requires. It
still reads the retired `GROQ_API_KEY` and has its own `call_groq_api()`. Do not
include it; `config/groq_config.php` is the live one.

### `bind_param` type-string length

`'sssisssi'` must have exactly one character per parameter. A mismatch fails at
runtime, not at lint. Count them.

### PHP 8.0, not 8.1+

No first-class callable syntax (`trim(...)`). Use `'trim'` or a closure.

### Heredoc indentation

PHP 7.3+ strips the closing marker's indentation from every line. If the closing
`HTML;` is indented but a body line is not, it is a parse error.

### Windows long paths

Some upload paths exceed 260 characters and git warns "Filename too long".
`git config --global core.longpaths true` if it becomes a problem.

### Headless Chrome closes the real browser — two separate ways

`chrome.exe --headless=new` with no `--user-data-dir` opens the *default*
profile — the same one the human's everyday Chrome window is signed into.
Chrome allows only one process per profile; a second process (even headless)
takes the lock by closing the window that already held it. Every verification
run under **Testing** below must pass its own `--user-data-dir` pointing at a
throwaway folder (the scratchpad is fine), or it will force-close whatever the
human has open, every time.

Cleaning up afterwards with `taskkill /F /IM chrome.exe /T` is a second,
unconditional way to cause the same symptom — it matches every `chrome.exe`
process on the machine by image name, including the human's real windows,
*regardless* of `--user-data-dir`. Track the PID of a launch you backgrounded
and kill only that PID, or let a one-shot `--dump-dom`/`--screenshot` process
exit on its own.

---

## Shared includes — use these, do not re-invent

Everything visual lives in `includes/`. A page that hand-rolls its own header or
its own palette is a bug, not a style choice.

| Include | What it gives you |
|---|---|
| `site_head.php` | Design tokens, fonts, `.wrap`, `.crumb-bar`, `.btn-sm-maroon`, `.btn-sm-outline`, Material Symbols |
| `site_header.php` | Navbar, avatar menu, notification bell, login modal |
| `site_footer.php` | Footer, Bootstrap JS, notification dropdown script |
| `console_shell.php` | Dashboard card layout (`.layout`, `.paper-card`, progress tracker) |
| `review_console.php` | The whole review desk. Four roles share it — configure with `$RC` |
| `manage_page.php` | The `mgmt-*` stylesheet for the three management consoles |
| `manage_console.php` | Bootstrap-class re-skin for management pages |
| `password_generator.php` | The Generate button on the create forms — builds `RayverReyes-056` from the name and ID already typed |
| `action_dialogs.php` | Site-styled confirm on any `[data-confirm]` element |
| `manage_save_confirm.php` | "Save these changes?" with a field-by-field diff |
| `theme.php` | Palette + light/dark, applied pre-paint |
| `scroll_jump.php` | Scroll to top/bottom button |
| `skeleton.php` | The loading skeleton: pale blocks in the shape of the page, over the content area until the page has settled |

**Never use `alert()` or `confirm()`.** Put `data-confirm="..."` on the button
and `action_dialogs.php` handles it.

**One loading indicator.** The bar along the top of the window
(`loading_bar.php`, pulled in by `site_footer.php`) reports every fetch on the
site. Drive it with `window.papelLoading.start()` and `.done()`;
`papelLoading.work.*` adds the logo pill for a long workflow. A page that
paints a progress bar of its own ends up with two of them, in two places: the
student dashboard drew one across the top of its results column, which on that
page is above the search box, so it read as a stray rule floating in the gap.

### The loading skeleton

The splash (`includes/splash.php`) plays only on the first page of a visit and
on a refresh: it returns early otherwise. So ordinary navigation from page to
page had nothing over it, and a page painted the server's markup and then
settled into its real shape in front of the reader. On the upload page that is
a jump you cannot miss: the form paints as one column and `papelSide` rebuilds
it into two panes with tab strips at `DOMContentLoaded`.

`includes/skeleton.php` covers that gap. It is required from the top of
`site_head.php`, so it decides before the first paint, and prints its blocks
from the end of `site_header.php`, so they are in `<body>` before the page's
own content. The splash and the skeleton never both appear: the skeleton
returns early when `papel-splash` is already on `<html>`.

- **A page picks its shape** with `$PAGE_SKELETON` *before* including
  `site_head.php`: `panes` (the upload page), `console` (the dashboards and the
  four review desks, defaulted in `review_console.php` so the desks need no
  edit), `repository`, `reader`, `table` (the management consoles), or nothing
  at all for the generic page. Everything else that includes `site_head.php`
  gets the generic shape with no edit.
- **It covers rather than hides.** The real content stays laid out underneath,
  so nothing that measures an element sees a hidden box. The overlay starts at
  the navbar's and breadcrumb strip's live measured bottom, not at a number:
  those two are server-rendered and never reshaped, so they stay real.
- **The shapes are measured, not judged by eye.** `.wrap` is 1168px at most with
  24px of padding, and inside it the main column, a 36px gap and the 226px rail
  come to 1120px; the reader's is 1fr + 256px with a 28px gap; the management
  grid is 304px + 1fr with 20px. The upload page's side pane is read from
  `papel.pdfDockWidth` (with `papel.sideSide` and `papel.sideCollapsed`), since
  a guessed width still shifts the page sideways when the skeleton lifts, which
  is the jump being covered.
- **It lifts at the later of the page's ready signal and 300ms.** The minimum is
  deliberate: a cached page is ready in well under a tenth of a second, and a
  skeleton that appears and vanishes inside one is a blink of its own. A page
  that rebuilds its own layout calls `papelSkeleton.hold()` while it parses and
  `papelSkeleton.done()` once the real layout is up (the upload page does this
  around `papelSide`), so it never lifts onto a half-built page. Two failsafes:
  the script gives up 1.5s after `load`, and the CSS fades the layer out at 6s
  in case the script is itself what failed. With no script at all nothing is
  ever shown.
- Block tones are `color-mix` off `--ink`, never a fixed grey, so the blocks
  track the theme. Reduced motion drops the sweep and keeps the blocks: the
  blocks are what stops the page jumping.

Measured on a navigation into the upload page: skeleton on at 22ms, the layer
present at `DOMContentLoaded` (133ms), the fade beginning at 307ms with
`body.panes`, the tab strips and `#mainBody` all already built, and the layer
out of the DOM at 515ms.

The upload page's overlay is the one place the logo is not a pill: it plays the
same GIF inside its own card and does not call `papelLoading.work.*`, since
asking for both put the animation on screen twice. The GIF is not transparent
(it carries an off-white ground), so it sits on a plate of that colour, and the
dark theme's `img { filter: brightness(.92) }` has to be switched off for it
(`#id img { filter: none }`, the id being for specificity) or the picture comes
out darker than its plate.

### Styling

Use the CSS custom properties — `--maroon`, `--dark-maroon`, `--soft-maroon`,
`--cream`, `--ink`, `--grey`, `--border`, `--white`, `--font-head`,
`--font-body`. Never hardcode a hex colour. The theme system swaps these at
runtime via `data-mode` / `data-color` on `<html>`, so a hardcoded colour breaks
dark mode silently.

Chart.js colours must be **read from the tokens at draw time** and rebuilt when
the theme changes — see `analytics/analytics_dashboard.php` for the pattern.

---

## Security conventions

Every page: `require_once config/core.php` then `require_role([...])`.

- **CSRF** — `csrf_field()` in every form, `csrf_verify()` at the top of every
  POST handler.
- **CSP nonces** — every inline `<style>`/`<script>` needs
  `nonce="<?= csp_nonce() ?>"` or it will not run.
- **Prepared statements** everywhere. The one place SQL is interpolated is a
  whitelisted sort column in the analytics page — copy that pattern if you need
  dynamic ordering.
- **Escape output** with `e()`.

### Never commit

`.env`, `client_secret*.json`, anything under `**/uploads/`, `vendor/`, `*.log`.
The `.gitignore` covers these; check `git status --short` before committing
anyway. A committed secret must be **revoked**, not just deleted — it stays in
the history.

---

## Login

Sign-in is **ID + password**. No usernames, no birthdate, no emailed one-time
code (all three were removed; the code step was deleted outright in October
2026, so there is no commented-out block to revive).

The lookup matches `username`, `email`, `student_id` **or** `faculty_id` —
students carry their ID in `student_id`, staff in `faculty_id`, and which column
depends on who created the account. All four are checked.

`admin_level` is stored in the session at login. Do not reintroduce a
`$u['admin_level'] ?? 1` that reads from a session which never had it — that bug
gave a HAP the Coordinator's navbar for months.

Staff, librarians and the Director all sign in on the **faculty** tab. Only
`selected_role` values `student`, `faculty` and `guest` exist.

---

## Email

Every message goes through `send_email()` in `config/core.php`, which wraps the
body in `email_layout()`: the maroon PUP letterhead, the PAPEL band, the
sign-off and the footer. **The subject is also printed as the heading inside
the letter**, so changing one changes both.

Bodies are built from `email_para()`, `email_details()` (the label/value table,
with IDs and passwords in monospace) and `email_action()` (the link). Never
concatenate raw HTML; `email_plain()` derives the plain-text copy from the same
markup and only understands those blocks.

`create_notification()` posts the in-app notice **and** emails it. Its subject
comes from a map keyed on the notification type; pass the fifth argument when
one type means two different things (an approval is "forwarded" to one reader
and "published" to another, a manuscript notice is a request to a librarian and
an answer to a student).

**Mail actually sends from this machine.** Anything that creates a notification
reaches a real inbox, so test against `@example.com` addresses, which are
reserved and cannot receive mail. Seeding a paper on a real student's account
and approving it will email that student.

---

## Business rules worth knowing

### Student account expiry

An account's life comes from the section: `1-x` → 5 years, `2-x` → 4, `3-x` → 3,
`4-x` → 2, `Ladderized` → 2. Counted in academic years from the A.Y. on the
account, expiring 31 July. See `student_account_years()` and
`student_expiry_date()` in `config/core.php`.

Recomputed on every create and edit, so **moving a student up a year is how you
renew them**. An expired account cannot sign in.

### What counts as a submission

Analytics counts only work actually handed in:

```sql
current_status <> 'draft' OR EXISTS (SELECT 1 FROM approval_workflow ...)
```

The second half matters: a returned paper is back in `draft` but *was*
submitted. Drafts nobody has been asked to look at are reported separately.

### One paper, one or two manuscript files

A student ticks IMRAD, Full Manuscript or both in Step 1, and
`research_papers.manuscript_type` records it (`IMRAD`, `Full Manuscript`,
`IMRAD, Full Manuscript`). A paper handed in both ways is **one paper with two
files**, never two papers.

The files live in `paper_manuscripts`, one row each (`paper_id`, `format` =
`imrad` or `full`, `file_path`, `gdrive_file_id`, `file_size`). There is no
foreign key, so the rows stay put when a paper is archived or restored.

**The mirror rule.** `research_papers.file_path / gdrive_file_id / file_size`
always describe the *primary* file: the Full Manuscript when there are two,
otherwise the only one. Older readers depend on it (`archive/home.php`, the
repositories, archive and restore, the AI summary, `?paper=` links already in
circulation), so anything that writes a paper's file writes the mirror in the
same request.

Ask `config/core.php`, do not parse `manuscript_type` by hand:
`paper_declared_formats()`, `paper_primary_format()`,
`manuscript_format_label()`, `paper_manuscript_entries()` (what a page lists:
label, viewer address, whether it is required) and `paper_manuscript_rows()`
(one query for a whole page of papers). One file is labelled "Manuscript"; two
are "Manuscript (IMRAD)" and "Manuscript (Full Manuscript)". A paper with no
row (filed before the table existed) falls back to its own columns through
`?paper=`.

Each file is served by `app/paper_file.php?manuscript=<id>` and opened in
PAPEL's viewer, which now includes the review desk (it used to link to Drive).
Whoever may open one may open both, and a student's librarian grant covers both.

Submitting is all-or-nothing: every file goes to Drive first, then one database
transaction writes the paper, the draft merge, the manuscript rows and the
supporting documents. If anything fails the transaction rolls back and the Drive
files and staging copies that request made are deleted. Returning a paper
deletes **both** Drive files (`purge_paper_drive_files()`), so the student
attaches both again; withdrawing keeps them, and resubmitting deletes the ones
it replaced. Extract with AI reads the IMRAD file when both are attached, since
the form's editors are IMRAD-shaped.

### "Other", for the paper type and the manuscript type

Both Step 1 lists end in **Other**, the way a Google Form does: the student picks it and
types what their paper is. Paper type Other (a "Case Study") has its supporting documents
optional, like a thesis. Manuscript type Other (a "Policy Brief") stands **instead of** IMRAD
and Full Manuscript, never beside them: ticking it clears the other two and the reverse, and
`manuscript_types_posted()` holds to the same rule. It is one PDF, `pdf_other`, filed as
format `other`, and named in the student's words wherever the file is mentioned.

- **No column.** The typed name rides in the VARCHARs already there, behind a marker:
  `paper_type = 'other:Case Study'` (40 characters at most, the column is 50) and
  `manuscript_type = 'Other: Policy Brief'` (commas taken out: that column is a comma list).
  A draft with nothing typed yet keeps `other:` or `Other`; submit refuses it in words.
- **Never print either column.** Go through `paper_type_label()` and `manuscript_type_label()`,
  which take the marker off. Analytics and `archive/home.php` printed the raw codes
  (`undergrad_thesis`) until this.
- **Never build a path from a paper type.** Files are filed by type on disk and on Drive, and a
  typed name is not a safe folder name: a colon is illegal in a Windows path (the first Other
  paper failed moving its PDF into `other:Case Study`), and a slash or `..` would walk out of
  `uploads/`. `paper_type_folder()` files every typed type under `other`.
- **Filters**: `paper_type_filter_options()` (the form's types, then Other) and
  `paper_type_filter_sql()`, where `other` matches every typed one. The desk and the student
  dashboard offered the retired Research Paper and Thesis and no Feasibility Study before this.
- The adviser's checklist starts on the **Blank** list for a manuscript of Other.
- **The paper type and the Academic Program are typed in** (`data-type-in` on the `<select>`,
  `includes/select_skin.php`): typing narrows the list, Up/Down and Enter pick, Escape puts the
  answer back, and the select stays the control the form posts. On the paper type a name that
  matches nothing is offered as `Use "..."` and chosen as Other, its name written into
  `paper_type_other` (`data-type-in-other` / `data-type-in-field`), so the separate name box is
  only the fallback where the script has not run. The programme takes only a listed answer: it
  decides the filing folder and every report.
- **What may be typed there is ruled on** (`other_name_problem()`, with `other_name_filler()` and
  `other_name_looks_random()`): at most four words, no greeting or filler as the whole of it, and
  nothing shaped like mashing (one letter four times over; eight letters or more made of four
  distinct ones, which is what "adasdadsaddsd" is; six consonants in a row, since English stops at
  five in "strengths"; a run along the keyboard; a word of five letters or more with no vowel). The
  box took anything before, so a greeting, mashing and a whole sentence were all filed and
  published. **Only on a submission**: a draft keeps a name halfway through being typed. The page
  says the same thing at Step 1 (`otherNameProblem()` in `student_upload_ai.php`, the same rules in
  the same order), but the server is what decides.
- Ask `config/core.php`: `paper_type_from_post()`, `paper_type_other_name()`,
  `manuscript_other_name()`, `other_name_clean()`, `other_name_problem()`. Tested by
  `tests/m15_other_types.py` (one real Drive submission), the "other" cases in
  `tests/_checklist_unit.php`, and `tests/_other_name_unit.php`, which is the list of what the
  boxes must take and must refuse: every refusal there is something a student really typed in.

### One height for every box on the upload form

`#uploadForm` sets `--field-h` (30px) and every box a student types or chooses in takes it:
`.form-control` that is not a file input, the skinned chooser's own button (`.sel-btn`,
`includes/select_skin.php`) and a plain `<select>` where the skin has not run. The form had three
heights (30px for the Other box beside Manuscript Type, 37.5 for the two choosers, 47.6 for the
fields on Step 2) and side by side they read as different kinds of control. The padding and type
size go with the height: setting height alone on a box padded for 47px clips the text. A file input
keeps its own height, being a button and a drop area rather than a line to type in.

### A draft's card on the student dashboard

A draft that has never been submitted (`$isDraftOnly`, worked out before the card so the link
below can be written first) is the one card with nothing on the approval track, so it does not
draw one: **Continue editing and the bin take that row instead** (`.card-track.is-draft`), with
the adviser's name still at its end. A draft that was sent back keeps its track, which has
something on it, and keeps its buttons under the card.

The row is three columns: Continue editing, who has it, then the bin at the far end, in the
same 1.625rem gutter `.card-status` keeps so it sits under the fold chevron.

**The card itself is a link** (`.card-open`): an `<a>` laid over it, so a click anywhere carries
on where the student left off and a right-click offers the browser's own "Open in new tab" and
"Open in split screen". The title stays plain text, since a draft has nothing filed to read. Three
things the overlay has to respect:

- it is the card's **first child**, before `.card-head`, because `browse_console_js.php` folds
  everything after `.card-head` into `.paper-card-body` and would fold the link away with it;
- everything there is to press (`.card-actions`, `.paper-card-toggle`, `.paper-action`) is raised
  above it, or the bin and the fold chevron stop answering;
- it is `tabindex="-1"` and `aria-hidden`, the Continue editing button beside it being the same
  journey said properly, so the keyboard and a screen reader are not given the same link twice.

Text on the card cannot be selected with the pointer while a link covers it. That is the price of
a card that is a link, and `tests/` does not cover it; the checks are the scratch scripts.

### Date Completed, or the year only

Date Completed is required on the upload form unless the author ticks **"No exact date: use the
year only"** (`research_date_unknown`), which switches the date off (disabled, so not posted) and
makes the Year box the answer: the paper is stored with its `year` and a NULL `research_date`, the
shape every paper filed before the date was asked for already has. Every reader goes through
`paper_date_display()`, so the year shows on its own, including the "Date Completed" fact on the
public page, the review page and the author's page (it used to say "Not provided"). There is no
column for the choice: a returned paper with no date comes back with the box ticked
(`approval_workflow` says it was handed in); a draft that never had one does not.

### Which written sections a paper was filed with

A paper has six written sections (`paper_section_labels()`): the Abstract and five
more. On arriving at Step 2 the author chooses which to submit: the Abstract only,
or any combination (`askSectionScope()` in `student_upload_ai.php`, in the shared
dialog, with a Change button to ask again). The Abstract is always included,
because the cards, the search and the AI read the `abstract` column, **except in
Custom** (below), where an author with sections of their own may leave it out.

The choice is stored **inside `imrad_content`**, under the reserved key `_scope`
(a list of section keys), and **not in a column**. `ArchiveRepository` copies a
fixed column list and `imrad_content` is on it, so archive and restore carry the
choice with no change at all. A column would have to be added to `papers_archive`,
to that list and to its bind types, and a server missing any one of them fails on
archiving. Do not add one without reading `ArchiveRepository` first.

- Ask `config/core.php`, do not parse the JSON by hand: `section_scope_parse()`,
  `section_scope_resolve()`, `paper_section_scope()`, `paper_scope_info()`,
  `paper_scope_chip()`, `paper_scope_notice()`.
- **No `_scope` means complete**, and nothing is shown or claimed for the paper:
  it was filed before the choice existed, or it is a draft saved before one was
  made. Do not read it as a statement of what the author meant.
- **The server decides.** Submit requires only the chosen sections and stores the
  others empty whatever was posted. A draft keeps everything typed, so narrowing
  the choice and widening it again loses no text.
- **Reviewers check the PDF; the repository shows the typed sections.** The adviser
  checklist is about the manuscript file and is unchanged. What the choice changes
  is what gets *published*, so that is what the notices say.
- It shows in the approval chain: a chip on the review desks (one shared card, so
  all four roles) and on the student dashboard; a notice on `review_paper.php` and
  in the Approve confirmation; the author's `paper_details.php`; the public paper
  page; and the adviser's and Coordinator's notifications.
- **Choosing must not autosave.** A bare upload form already counts as content (its
  defaults), so saving on the choice alone wrote a device copy and raised
  "Continue where you left off?" for someone who had only answered the question.
  The autosave on typing, the leave-page guard and "Save as draft" all carry the
  field already.

### Sections of the author's own

A Feasibility Study has an Executive Summary and five Aspects, a thesis has chapters, and
boxes named Methodology and Results and Discussion help neither. In the same question as above
the author can add **sections of their own name** (up to 12), typed or started from a ready-made
list: Full Manuscript, Undergraduate Thesis (the same chapters) or Feasibility Study
(`paper_custom_presets()`, which reads the lists the adviser's checklist uses, so a study is
asked for the sections it is reviewed against; an **IMRAD** list fills the form's own five). They
are shown **after** the six on every page.

**Custom is its own mode.** Clicking it swaps the six tick boxes for the list of their own, whose
first row is the Abstract, fixed in name but removable: in Custom, and only there, the Abstract
is **optional**. The other quick choices go back to the six and drop their own (warning first if
anything was typed in them). Only a row called Abstract is filed as the real Abstract; every other
row, even one named like a standard section, stays one of theirs, so it keeps the author's order.
The form posts `section_scope = '_none'` (`SECTION_SCOPE_NONE`) for "none of the six", and the
server allows that, or any choice without the Abstract, only when there is at least one section
of their own (`section_scope_for()`); otherwise the Abstract is asked for as ever. A stored
`_scope` without the Abstract is read as it stands only on a paper with sections of its own.
Each gets a box on Step 2 and is required like a ticked standard section. Plan:
`docs/PLAN_custom_sections.md`.

- **Stored inside `imrad_content` again**, under `_custom`: `[{"key","label","html"}]`, keys
  `custom_1` to `custom_24` (more keys than sections: one taken out while it holds text is kept,
  struck through, until the answer is given, and keeps its key meanwhile). No column and no
  migration, so a copy of the site that is behind needs nothing run, and `ArchiveRepository` carries
  it whole. No `_custom` means none, so every paper filed before this reads as it did.
- **Ask `config/core.php`**: `paper_custom_list()` and `paper_custom_clean()` are what a post may
  hold (names cleaned the checklist's way, repeats dropped, 12 at most, a bad key dropped, never an
  error but for a body left empty), `paper_custom_sections()` is what is stored,
  `paper_sections_for_display()` is what a page shows: the six, then theirs. The three readers
  (`review_paper.php`, `paper_details.php`, `archive/view_paper.php`) take `$sectionLabels` and
  `$sections` from it; **a new reader must, not loop over `paper_section_labels()`**, which is
  blind to them. It also counts a section holding only a picture or a table as written, as submit does.
- **A name equal to a standard section that is being submitted is dropped** (that box already covers
  it). One equal to a section left out stays: that is how a thesis puts References after its chapters
  and not before them. Choosing a ready-made list therefore unticks the standard sections.
- **Kind `custom` in `paper_scope_info()`**: their own sections *and* standard ones left out. Chip
  "Custom: N sections", and the notices count what was provided ("4 written sections") instead of
  "3 of 6". Their own on top of all six is `full`, and nothing is flagged.
- **On the page**: the editor is drawn once (`$renderEditor` in `student_upload_ai.php`) and once more as
  an inert `<template>`; `papelDoc.addEditor()` copies it. Their names travel in the hidden
  `custom_sections` field (JSON), each body in the field named by its key (`custom_1`). `apply()`
  rebuilds the boxes **before** it sets their text, or there is nothing to put it in.
- **Traps already met while building it.** `papelChoose` answers `{keys, custom}` only when it is
  given `opts.custom`; with none it answers the array it always did. A *disabled* input gives up
  focus, and the next Enter then lands on the page and answers the whole question, so at the limit
  only the Add button is disabled. A box made after load is not wired to the browser-copy autosave
  (a contenteditable raises no input event on the form), so `addEditor()` wires its own surface.
  `hasContent()` ignores `custom_sections`, as it does `section_scope`: choosing must not autosave.
- **Rows are reordered by dragging their handle** (mouse, finger or pen: pointer events, with
  `touch-action: none` on the handle so a finger does not scroll the list instead), or with the
  arrow keys on a focused handle and Alt+arrows in a name box. The Abstract row has no handle and
  nothing goes above it, since it is always first on the paper's page. The drag follows the pointer
  on the **document**, not by `setPointerCapture()` on the handle: moving the row in the page takes
  the handle out and puts it back, which ends a capture, and the first build stopped after one place.
- **A name is a person's typing.** It is written with `textContent` and escaped with `escHtml()`
  wherever markup is built (the Step 4 explanation, the extraction notice). The dialog's body scrolls
  inside the box, so twelve rows fit a short screen.

### Supporting documents

Step 3 is **one table**: what is being asked for down the left, the control and
the file's name down the right. It was three cards of stacked label-and-input
pairs, which read as three unrelated questions and left the student scrolling to
see what was still missing. The four named documents (ethics clearance, consent
form, data collection tool, copyright/IP) are fixed rows, and under them, in
their own `<tbody>`, are as many rows as the student adds and names, the way
they add sections of their own at Step 2.

- **The native control is off the page and a `<label>` stands in for it**
  (`.docs-input` clipped, `.docs-choose` styled as the button). It drew the
  browser's own "No file chosen" beside a button saying what the indicator
  already says, and the pair did not fit the cell, so the text was cut to
  "N...sen"; and its width answers to what it holds, so choosing a file made the
  button wider and pushed the box along. A label with `for` opens the picker by
  itself, keyboard included, and the focus ring goes on the label
  (`.docs-input:focus-visible + .docs-choose`) since the control is not seen.
  **The row is one shape** empty, filled or focused: the padding and the 1px
  border never change between states. Measured, not judged by eye.
- `syncSupportingDocs()` finds a document's label with
  `.docs-table th label[for="..."]`, scoped to the left-hand cell, because the
  Choose file label carries the same `for`.
- **A file that is there is a control.** `updateFileIndicator()` builds the box
  from elements (the name is the file's, so it goes in as text): left-click
  opens it in a viewer of its own, right-click offers that, the raw file and
  Remove (`.doc-pop`, the editor's own popover), and an X appears at the end on
  hover, or always where there is no hover. Removing empties the input, which is
  the only thing script may do to a file input.
- **A supporting document gets its own viewer**, `papelPdfDock.popOut(file, name)`
  (newly public; the handshake was already there for the panel's own "new tab"
  button). Not the docked panel: it holds one PDF at a time and that one is the
  manuscript, so sending a consent form to it replaced the paper the student was
  reading against, and opening a second document replaced the first. The file
  never leaves the browser either way, the new tab being handed the `File` over
  `postMessage`, same origin only.
- **What a document may be called**: `supporting_doc_name_problem()`, which
  shares other_name_problem's two tests but allows 8 words rather than 4, since
  a document really is called "Certificate of Originality and Authorship". A
  separate function on purpose: `tests/_other_name_unit.php` pins the wording of
  the other one. The page says the same thing first (`docNameProblem()`); keep
  the two together. Pressing Add clears away any row that was added and never
  filled in, so a name of nothing but spaces does not survive it.
- **Ask `config/core.php`**: `supporting_docs_extra($post, $files, $require)` is
  the authority on what the added rows may hold. It is called at the **top** of
  the submit handler, before a file is moved or sent to Drive, so a bad row is
  answered in words rather than after a minute of uploading. On a submission it
  refuses a file with no name, a name with no file, two rows under one name, and
  more than `SUPPORTING_DOC_EXTRA_MAX` (6); saving a draft refuses none of that,
  because a row halfway through being filled in is ordinary. A row left entirely
  blank is ignored either way.
- Both fields post unindexed (`extra_doc_name[]`, `extra_doc[]`), so one position
  means the same row in each, and the helper returns the row's index for reaching
  into `$_FILES['extra_doc']`. An empty row between two filled ones still maps the
  third row to index 2; do not renumber.
- Each added row is filed exactly as the old single "Other Document" was: kind
  `other`, with the student's name in `document_name` where the column exists
  (`supporting_documents_have_name()`), and without it on a server that has not
  run the repair. **Never build the file's path from the typed name**: it goes
  through `safe_name()`, for the same reason a paper type does.
- The old `other_doc` / `other_doc_name` pair is gone from the form, and the
  server still accepts it, for a page cached before this change.

**The names are stored inside `imrad_content`, under `_docs`** (`PAPER_DOCS_KEY`),
and not in a column, for the same reasons `_scope` and `_custom` are: nothing to
add to `papers_archive`, to `ArchiveRepository`'s column list or to its bind
types, and no migration for a copy of the site that is behind. Every reader takes
the written sections **by name** (`paper_sections_for_display()`), so a key they
do not know about is invisible to them; a reader that loops over the decoded JSON
instead would print it as a section. Ask `extra_doc_names_clean()` and
`paper_extra_doc_names()`, do not parse it by hand.

Only the names are kept there. The documents are rows in `supporting_documents`
once a paper is handed in, and **a draft holds no file**, so this is what lets a
draft reopened from the dashboard come back with its rows labelled and waiting
for their PDFs. A submitted paper keeps them too: a paper returned for revision
is a draft again. On the page they ride in the hidden `extra_docs` field, and
`restoreExtraDocs()` rebuilds the rows **before** the restore puts anything in
them, since a box made after load is not there for the restore to find.

### The review checklist

What the Research Adviser confirms while reading a paper. Its heading is a dropdown of
ready-made lists (`paper_checklist_templates()` in `config/core.php`): IMRaD Sections,
Undergraduate Thesis Sections and Capstone Project Sections (Chapter 1 to 5 and References: the
old "Full Manuscript" list, under the name of what is being reviewed), Feasibility Study
Sections (Executive Summary, the Marketing, Technical, Management, Financial and
Socio-Economic Aspects, References, Appendices: the chapters of the BSIE studies on file), and a Blank list. **Edit sections** lets the adviser add, remove or rename sections
**for that one paper**; only a code change edits the ready-made lists.

- **Stored as the adviser left it**, in `paper_checklist.items_json`
  (`{"v":1,"template":"...","edited":true,"items":[{"label":"...","ok":true}]}`), so changing a
  list in code never rewrites what a student was shown. A row with no `items_json` was written by
  the old form and is read from its eleven columns, its chapters headed by the paper's type and
  never "Full Manuscript". Ask `config/core.php`, do not parse by hand: `paper_checklist_load()`,
  `paper_checklist_clean()` (the authority on what a post may hold: 20 sections, 80 characters,
  tags stripped, repeats dropped, at least one), `paper_checklist_save()`,
  `paper_checklist_default()`.
- **The starting list follows the file format first, then the type**: filed as IMRAD only gives
  IMRaD, a Feasibility Study gets its own, anything else the chapters list named for its type
  (two of the three theses on record were filed as IMRAD, so the type alone would be wrong). It is
  only a starting point.
- **One editor, in two places**: `includes/review_checklist.php`, used by `app/review_paper.php`
  and by the approve window on the adviser's desk (`includes/review_console.php`). It posts
  `ck_template`, `ck_label[n]` and `ck_ok[n]`; the old eleven field names are still accepted.
- **Only the adviser edits, and only on Approve.** The Coordinator reads the adviser's list (their
  boxes used to look editable and nothing they changed was kept). Return saves no checklist.
- **The save is an upsert.** It was a plain INSERT against `unique_paper`, so a paper the
  Coordinator returned and the adviser approved again kept its old ticks here (the failure was
  silent on PHP 8.0) and threw on PHP 8.1+, which ended in the 500 page with the paper unforwarded.
- **A server without the column** (`paper_checklist_json_ready()`; see "A copy of the database can
  be behind the code") still works: three lists, no editing, a notice saying why, and the old
  columns as before.

### Programmes

`programs_map()` and `program_code()` in `config/core.php`. Full name in the
database, short code (`BSIT`) in tables and chips.

---

## Database

The data is not in the repository. The **structure** is: `database/schema.sql` (tables
only, no data) is what a new copy of the site imports (phpMyAdmin, pick the database,
Import; or `php scripts/setup.php`), and staging was built from it on 14 October. After a
migration, export the structure again so a fresh copy comes out right. A copy that was
built *before* the migration has to be brought up to date by hand: a migration script in
`scripts/migrations/`, or, where there is no shell, the SQL in `scripts/migrations/sql/`.

Migrations are plain PHP, safe to run twice, and idempotent — they check whether
the column exists first. Run them from the CLI:

```bash
php scripts/migrations/run_student_cohort_migration.php
```

Recent ones: `run_student_cohort_migration.php` (academic_year, section),
`run_account_expiry_migration.php` (expires_on),
`run_notification_type_repair.php` (notices that lost their kind),
`run_drop_otp_column.php` (removed `users.is_otp_exempt`; already applied),
`run_supporting_doc_type_repair.php` (`supporting_documents.document_type` from
ENUM to `VARCHAR`, plus `document_name`),
`run_paper_manuscripts_migration.php` (creates `paper_manuscripts` and backfills
one row for every paper that already has a file),
`run_checklist_items_migration.php` (adds `paper_checklist.items_json`, which the editable review
checklist is stored in).

Run the last two on any other server before it takes this code. Until
`paper_manuscripts` exists the pages fall back to the paper's own columns and
the upload page refuses a paper ticked both ways (and says so), so nothing
breaks, but nothing can be handed in twice either.

A server with no shell (staging) cannot run those. `scripts/migrations/sql/2026-10-21_catch_up.sql`
does the last two in plain SQL for phpMyAdmin: SQL tab, with the site's database selected,
paste, Go. It is safe to run twice, repairs the rows already written, and closes with a
line per step saying what it did. It was checked against the PHP migrations (same tables,
same rows) and by pasting it into phpMyAdmin itself. See "A copy of the database can be
behind the code" under Traps before adding the next one.
`scripts/migrations/sql/2026-10-23_checklist_items.sql` does the checklist column
(`run_checklist_items_migration.php`, `paper_checklist.items_json`) the same way, and
`2026-10-27_manuscript_requests.sql` the `manuscript_requests` table and the `'manuscript'`
notification type (`run_manuscript_requests_migration.php`), which were never in `schema.sql`.

Papers deleted from `research_papers` leave rows behind in `approval_workflow`,
`paper_checklist`, `supporting_documents` and `paper_manuscripts`. `papers_archive` is *meant* to
outlive its papers — never treat its rows as orphans.

---

## Testing

There is no test framework. Changes are verified against the running app:

1. Sign in over HTTP with a real account and fetch the page.
2. For behaviour, inject a probe script into the fetched HTML and run it in
   headless Chrome (`--headless=new --dump-dom`), writing results into
   `document.title`. Always pass `--user-data-dir=<scratch folder>` — see
   the trap above; without it, this closes the human's actual browser.
3. For layout, take a screenshot and measure with `getBoundingClientRect()`.

**Verify against the running site, not by reading code.** Several bugs in this
project's history looked correct in source and failed in the browser — a form
closed early by a stray `</div>`, a dialog painted under its own backdrop, a
`\b` regex that never matched at end-of-string.

Seed realistic data, assert, then clean up. Never leave test rows behind.

**Scripted sign-in no longer works.** reCAPTCHA is configured, and it exists so a
script cannot use the sign-in form ("Please confirm you are not a robot"). Write a
session file for the account instead (see "Signed-in page without signing in" in
the verification notes), which is what signing in does. The older suite in
`tests/` (`run_all.py`) signs in by form as the Research Coordinator, whose
password has also changed since it was written, so it cannot start; and its
`full_submission` helper predates the two-manuscript upload (it posts
`manuscript_type` as one word and the file as `research_pdf`). `tests/m10_section_scope.py`
is standalone: it creates the AUTOTEST accounts through the app's own forms using a
minted Coordinator session, submits for real (four Drive uploads), and removes the
Drive files and any empty AUTOTEST folder before it removes the accounts. It takes
step names to run a part (`python m10_section_scope.py legacy`; `custom_cheap` and
`custom_surfaces` cover sections of the author's own with no upload at all).
`tests/m11_submit_failures.py` is its companion for what a failed submission says: it
sets Google Drive aside (token, in the database and in the file) and renames the
notifications table for one request, to produce real failures, and puts both back in a
`finally`. If a run is killed, check `system_settings` for a `gdrive_system_token__aside`
key and `config/` for `gdrive_token.json.aside`, and that a `notifications` table exists.
`tests/m12_storage_folder.py` covers the storage folder: it points the real local
setting at the exact ID staging could not use, briefly, and puts the row back (value,
user and time) in a `finally`, with a copy kept in the system temp folder
(`papel_folder_setting.json`) that the next run restores from if one was killed. Drive is
only ever read. The test client's timeout is 180 seconds because a real submission is a
dozen sequential Drive calls and a slow connection makes that over a minute.

`tests/m13_old_schema.py` is the companion for a database that is behind the code. It
copies the live database into a scratch one (`papel_m13`), puts it back to its 14 October
shape (including no `manuscript_requests`, which `schema.sql` never had), and serves the same code from a second PHP server (`php -S 127.0.0.1:8093`) pointed
at the copy with mysqli throwing as it does from PHP 8.1. It then fills the adviser's desk,
files papers with the copyright document and a named extra one (three real Drive uploads in
all, removed afterwards), runs the catch-up SQL, and files again. The live database is never
written to. `tests/_old_schema_prepend.php` is what points that server, and the CLI probes,
at the scratch database: `auto_prepend_file` is **not** run for `php -r`, so a probe has to
be a script file. If a run is killed, drop `papel_m13` and stop the `php.exe` on port 8093
by its PID.

`tests/m14_review_checklist.py` covers the review checklist against the running site, with no
Drive: the unit cases in `tests/_checklist_unit.php`, what the review page offers each reader,
approving with each list and with edited ones, the old form's fields, refused and hostile lists,
a second round of approval, the desk's approve window, and the Coordinator's read-only view.
The dropdown, edit mode and posted fields were driven in a real Chrome (typing with
`Input.insertText` and Enter); that script is not in the repository. `m13` also covers the
checklist column.

### When there is no running app (cloud and web sessions)

Claude Code on the web, scheduled cloud agents and `/code-review ultra` all run
against a plain checkout of this repository on Linux. There is no XAMPP, no
MariaDB, no `.env`, no `uploads/` and no browser, so **none of the three steps
above can be carried out there**. A session that tries to sign in over HTTP or
launch headless Chrome is burning its turn on something that cannot work.

Still worth doing without the app:

- Read and change code, and say exactly what changed.
- Sweep the whole tree for a pattern: the dead `style="..."` attributes, a role
  check that covers `head_academic` but misses `admin` at level 2, a
  `bind_param` type string whose length does not match its arguments.
- Rewrite documentation, including the stale files under **Known gaps**.
- Review a diff.

Not possible there, and not to be claimed:

- **Confirming a fix works.** Say "changed, not verified in the browser" and
  leave the check to a session on the dev box. Several bugs here looked correct
  in source and failed in the browser, and that history does not stop applying
  just because the browser is missing.
- **Anything that reads the database**: a column's ENUM, a row count, whether a
  migration has run. The schema is not in the repository. Ask instead of
  guessing, and recall that an invalid ENUM value is accepted silently.
- **Running a migration** from `scripts/migrations/`.

The Windows paths in this file (`C:/xampp/htdocs/capstone`, `C:/xampp/tmp`)
describe the dev box, not the cloud checkout.

---

## House style

- Comments explain **why**, not what. If a line is unusual, say what would go
  wrong without it.
- British spelling in prose; American in code identifiers where the codebase
  already uses it.
- Match surrounding code density and idiom.
- Prefer fixing the shared include over patching one page — that is how the
  three management consoles ended up consistent.

---

## Known gaps

- **Staging's database is behind the code until the three files in `scripts/migrations/sql/`
  (`2026-10-21_catch_up.sql`, `2026-10-23_checklist_items.sql` and `2026-10-27_manuscript_requests.sql`)
  have been run there.** The adviser's checklist is limited to three fixed lists with no editing until
  the second one is, and students cannot ask a Librarian for a manuscript until the third one is. The pages work around the gaps (the adviser's desk no longer fails),
  but the copyright document is filed as "Other Document", a student's name for an extra
  document is lost, and a paper cannot be handed in as both IMRAD and Full Manuscript. Open
  `app/student/upload_diagnostics.php` as staff: "Database" reads "No gaps" once it is done.
  Delete this entry then.
- **`time_to_approval` is only written from now on.** `PaperService` stamps it
  on the Coordinator's approval, so the tile fills for papers approved since
  that was added. Papers approved before it have no figure and never will.
- **`CSRF_KEY` is still the placeholder** in `.env`. Changing it logs everyone
  out once.
- **The Director's password is unrecoverable.** Testing Director-only pages
  means minting a session file directly in `C:/xampp/tmp` (26-character id from
  the `0-9a-v` alphabet — PHP rejects anything else).
- **17 files in `docs/`**, several stale. `ADMIN_L2_DASHBOARD.md` in particular
  describes routing that has since changed.
