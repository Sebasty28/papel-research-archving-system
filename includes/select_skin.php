<?php
/**
 * Dropdowns in the site's own clothes, list included.
 *
 * A `<select>` can be styled down to its border, and then the moment it opens
 * the operating system draws the list: Windows grey, Segoe UI, square corners,
 * its own highlight colour. On a page built out of maroon and cream that is the
 * one control that always looks borrowed.
 *
 * There is no CSS for the open list (no browser allows it) so the list has to
 * be drawn. The rule followed here is that the real control stays:
 *
 *   - The `<select>` remains in the DOM and keeps its name and value, so forms
 *     submit exactly as before and every page that reads `.value` still works.
 *   - Setting `.value` or `.selectedIndex` from script updates the skin, because
 *     assignment is intercepted per element. Several pages fill a select from
 *     another one, and none of them fire an event when they do.
 *   - Options added or removed later are picked up, which is what Contact
 *     Support does when it rebuilds the list of names for a chosen role.
 *   - Keyboard behaviour is rebuilt rather than lost: arrows, Home and End,
 *     Enter and Escape, and type-ahead.
 *
 * The menu is positioned fixed, not absolute, because several of these sit
 * inside panels with `overflow: hidden` and an absolutely positioned list would
 * be cut off at the panel's edge.
 *
 * The same clothes are available to a box that can be typed in as well as
 * picked from: put `data-combo` on an `<input list="...">` and it gains the
 * chevron, the site's own list and the same keyboard, while staying the input
 * the form submits. See the combo section near the bottom.
 *
 * A real `<select>` can be made one that is typed in too: `data-type-in`. Typing
 * narrows its list, the arrows move through it and Enter picks; the select stays
 * the control the form submits, so its value is still always one of its options.
 * With `data-type-in-other="<value>"` and `data-type-in-field="<id of a text input>"`
 * a name that matches nothing can be kept: it is offered as `Use "..."`, and
 * picking it chooses that option and puts the name in that input (the upload
 * form's paper type, whose Other takes a name). Without them, text that matches
 * nothing is not an answer, and the box goes back to what was chosen.
 *
 * Anything that should keep the native control can say so with `data-no-skin`.
 */
?>
<link rel="stylesheet" href="<?= e(asset_url('assests/css/papel-select-skin.css')) ?>">

<script src="<?= e(asset_url('assests/js/papel-select-skin.js')) ?>"></script>
