<?php
/**
 * What a management console may be told, said in the browser.
 *
 * The three consoles (an adviser's students, the Coordinator's advisers and
 * librarians, the Director's admins) post one form that both creates and
 * edits an account. The server decides (see "What a management console may be
 * told" in config/core.php); this is the same set of rules, in the same order
 * and the same words, so the person is told while they are still looking at
 * the box rather than after a round trip that empties half the form.
 *
 * Three things to keep in mind when changing it:
 *
 *   - **The words are the server's.** A rule stated here in one wording and
 *     there in another reads as two different complaints about one answer.
 *   - **It must run before manage_save_confirm.php**, whose own submit
 *     listener opens the "Save these changes?" dialog. Listeners fire in the
 *     order they were added, so this file is required first and stops the
 *     event with stopImmediatePropagation() when something is wrong: without
 *     that, a refused form still asked whether to save it.
 *   - **It is not the guard.** Script that does not run, a field renamed in
 *     the markup, a crafted post: all of them go straight to the server,
 *     which refuses them in the same words.
 *
 * The message goes under the field in .mgmt-hint.is-bad, which the student
 * console already uses for its academic-year note, so a console needs no
 * styling of its own to pick this up.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
(function () {
    var forms = document.querySelectorAll('form.js-manage-form');
    if (!forms.length) { return; }

    /* ---- The rules, as config/core.php states them --------------------- */

    /* Letters (any alphabet), spaces, full stops, hyphens, apostrophes. The
       \p{L} class needs the u flag, which every browser this site supports
       has; the fallback keeps the Latin range rather than refusing everyone. */
    var NAME_OK;
    try { NAME_OK = new RegExp("^[\\p{L}\\p{M}.'\\-\\s]+$", 'u'); }
    catch (err) { NAME_OK = /^[A-Za-z.'\-\s]+$/; }

    /* other_name_looks_random(), the test the upload form uses on a paper type
       somebody has typed: one letter four times over, a long run of three or
       four letters shuffled, six consonants together, a run along the
       keyboard, a long word with no vowel in it. */
    function looksRandom(text) {
        var low = String(text).toLowerCase();
        var letters = low.replace(/[^a-z]/g, '');
        if (!letters) { return false; }
        if (/(.)\1{3,}/.test(letters)) { return true; }
        if (letters.length >= 8) {
            var seen = {}, n = 0;
            for (var i = 0; i < letters.length; i++) {
                if (!seen[letters[i]]) { seen[letters[i]] = 1; n++; }
            }
            if (n <= 4) { return true; }
        }
        if (/[bcdfghjklmnpqrstvwxz]{6,}/.test(letters)) { return true; }
        var runs = ['asdf', 'sdfg', 'dfgh', 'fghj', 'ghjk', 'qwer', 'wert', 'erty',
                    'rtyu', 'tyui', 'zxcv', 'xcvb', 'cvbn'];
        for (var r = 0; r < runs.length; r++) {
            if (letters.indexOf(runs[r]) !== -1) { return true; }
        }
        var words = low.split(/[^a-z]+/);
        for (var w = 0; w < words.length; w++) {
            if (words[w].length >= 5 && !/[aeiouy]/.test(words[w])) { return true; }
        }
        return false;
    }

    function nameProblem(v) {
        v = v.trim();
        if (v === '')            { return 'Enter their full name.'; }
        if (v.length > 100)      { return 'That name is too long for the form.'; }
        if (/\d/.test(v))        { return 'A full name has no numbers in it.'; }
        if (!NAME_OK.test(v))    { return 'A full name is letters, spaces, full stops, hyphens and apostrophes only.'; }
        if (v.replace(/[^A-Za-zÀ-ɏ]/g, '').length < 2) {
            return 'Please write their name out in letters.';
        }
        if (looksRandom(v))      { return 'That does not look like a name. Please type it as it is written.'; }
        return null;
    }

    function studentNumberProblem(v) {
        v = v.trim();
        if (v === '')                        { return 'Enter their student number.'; }
        if (v.length < 6 || v.length > 20)   { return 'A student number is between 6 and 20 characters, like 2026-00001-BN-0.'; }
        if (!/^[0-9A-Za-z-]+$/.test(v))      { return 'A student number is numbers and dashes, with no spaces or symbols, like 2026-00001-BN-0.'; }
        if (!/^[0-9]/.test(v))               { return 'A student number starts with the intake year, like 2026-00001-BN-0.'; }
        if (v.indexOf('--') !== -1 || v.slice(-1) === '-') {
            return 'Check the dashes in that student number: 2026-00001-BN-0.';
        }
        if (/[A-Za-z]{4,}/.test(v))          { return 'Only the campus code may be letters, as BN is in 2026-00001-BN-0.'; }
        if ((v.match(/[0-9]/g) || []).length < 6) {
            return 'A student number has at least six digits in it, like 2026-00001-BN-0.';
        }
        return null;
    }

    function staffIdProblem(v) {
        v = v.trim();
        var what = 'staff ID';
        if (v === '')                        { return 'Enter their ' + what + '.'; }
        if (v.length < 3 || v.length > 20)   { return 'A ' + what + ' is between 3 and 20 characters, like FAC-2026-001.'; }
        if (!/^[0-9A-Za-z-]+$/.test(v))      { return 'A ' + what + ' is letters, numbers and dashes, with no spaces or symbols.'; }
        if (v.indexOf('--') !== -1 || v.charAt(0) === '-' || v.slice(-1) === '-') {
            return 'Check the dashes in that ' + what + ': FAC-2026-001.';
        }
        if (!/[0-9]/.test(v))                { return 'A ' + what + ' has a number in it, like FAC-2026-001.'; }
        if (looksRandom(v)) {
            return 'That does not look like a ' + what + '. It is the one the campus issued, like FAC-2026-001.';
        }
        return null;
    }

    /* The browser's own type="email" passes fsjdfhsjfhjsd@gmail, as PHP's
       filter does: a bare host is a valid address on a network that has one,
       and a typo on the public internet. Every account made here is emailed
       its password, so the domain has to be whole. */
    function emailProblem(v) {
        v = v.trim();
        if (v === '')           { return 'Enter their email address.'; }
        if (v.length > 150)     { return 'That email address is too long for the form.'; }
        if (!/^[^\s@]+@[^\s@]+$/.test(v)) {
            return 'That is not an email address. It wants the shape name@example.com.';
        }
        var domain = v.slice(v.lastIndexOf('@') + 1);
        if (!/^[A-Za-z0-9.-]+\.[A-Za-z]{2,24}$/.test(domain)) {
            return 'That address needs the whole domain, like gmail.com rather than gmail.';
        }
        return null;
    }

    function academicYearProblem(v) {
        v = v.trim();
        if (v === '')         { return 'Enter the academic year.'; }
        if (v.length > 20)    { return 'That academic year is too long for the form.'; }
        var m = v.match(/^(\d{2}|\d{4})\s*[-\/]\s*(\d{2}|\d{4})$/);
        if (!m)               { return 'Write the academic year as one year to the next, like 26-27.'; }
        if ((parseInt(m[2], 10) % 100) !== ((parseInt(m[1], 10) % 100) + 1) % 100) {
            return 'An academic year runs from one year to the next, like 26-27.';
        }
        return null;
    }

    function sectionProblem(v) {
        v = v.trim();
        if (v === '')          { return 'Enter their section.'; }
        if (v.length > 40)     { return 'That section is too long for the form.'; }
        if (v.toLowerCase() === 'ladderized') { return null; }
        if (!/^[1-4]\s*-?\s*[A-Za-z0-9]{0,3}$/.test(v)) {
            return 'A section starts with the year level, like 4-1 or 4A, because that is what '
                 + "the account's length is counted from. For a ladderized intake, type Ladderized.";
        }
        return null;
    }

    /* Which rule belongs to which box, by the name the form posts. The staff
       consoles both post faculty_id; only the students console has the rest. */
    var RULES = {
        full_name:     nameProblem,
        student_id:    studentNumberProblem,
        faculty_id:    staffIdProblem,
        email:         emailProblem,
        academic_year: academicYearProblem,
        section:       sectionProblem
    };

    /* ---- Saying so ----------------------------------------------------- */

    /* Directly under the box it is about, not at the foot of the group: the
       students console puts the academic year and the section in one
       .mgmt-field, side by side, so a note held there belonged to whichever of
       the two complained last and the other field was marked with nothing to
       read. The name is on the note as well, so the two never trade places. */
    function noteFor(field, make) {
        var anchor = field.closest('.mgmt-with-btn') || field;
        var hold = anchor.parentNode;
        var note = null;
        for (var i = 0; i < hold.children.length; i++) {
            var c = hold.children[i];
            if (c.classList.contains('js-field-note') && c.getAttribute('data-for') === field.name) {
                note = c; break;
            }
        }
        if (!note && make) {
            note = document.createElement('small');
            note.className = 'mgmt-hint is-bad js-field-note';
            note.setAttribute('data-for', field.name);
            hold.insertBefore(note, anchor.nextSibling);
        }
        return note;
    }

    function clear(field) {
        var note = noteFor(field, false);
        if (note) { note.hidden = true; note.textContent = ''; }
        field.removeAttribute('aria-invalid');
    }

    function complain(field, why) {
        var note = noteFor(field, true);
        note.textContent = why;
        note.hidden = false;
        field.setAttribute('aria-invalid', 'true');
    }

    /* A field only answers for itself if it is on the form and has something
       in it that the server will read. A password left blank while editing is
       "keep the one they have", and the same is true of any box a console does
       not show: it is not in form.elements at all. */
    function fieldsOf(form) {
        var found = [];
        Object.keys(RULES).forEach(function (name) {
            var el = form.elements[name];
            if (el && el.type !== 'hidden' && !el.disabled) { found.push([el, RULES[name]]); }
        });
        return found;
    }

    forms.forEach(function (form) {
        fieldsOf(form).forEach(function (pair) {
            var el = pair[0], rule = pair[1];
            /* Cleared on the first keystroke: a box somebody has just started
               answering must not still be marked wrong while they type. */
            el.addEventListener('input', function () { clear(el); });
            /* And checked when they leave it, which is where a typo is
               noticed without having to press the button to find out. */
            el.addEventListener('blur', function () {
                if (el.value.trim() === '') { return; }      // not finished, not wrong
                var why = rule(el.value);
                if (why) { complain(el, why); } else { clear(el); }
            });
        });

        form.addEventListener('submit', function (e) {
            var first = null;
            fieldsOf(form).forEach(function (pair) {
                var el = pair[0], why = pair[1](el.value);
                if (why) {
                    complain(el, why);
                    if (!first) { first = el; }
                } else {
                    clear(el);
                }
            });
            if (!first) { return; }
            e.preventDefault();
            /* And nothing else gets the event: manage_save_confirm.php listens
               on the same form and would otherwise ask whether to save a form
               that is not going anywhere. */
            e.stopImmediatePropagation();
            try { first.focus({ preventScroll: false }); } catch (err) { first.focus(); }
        });
    });
})();
</script>
