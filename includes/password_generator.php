<?php
/**
 * The Generate button on the account-creation forms.
 *
 * Builds a password the account holder can be told over the phone and will
 * actually remember, out of what has already been typed into the form:
 *
 *     Full name  Rayver Reyes           ->  RayverReyes-056
 *     ID number  2023-00056-BN-0
 *
 * The three consoles that create accounts (students, advisers, admins) each
 * used to carry their own copy of a twelve-character random generator. They
 * share this one instead, so the format cannot drift apart between them.
 *
 * There is no First name / Last name pair anywhere in this system — the name is
 * one `full_name` column — so the two halves are read off the ends of whatever
 * was typed, and the middle is dropped.
 *
 * The ID field is whichever of `#student_id` / `#faculty_id` the page has; the
 * three formats in use disagree about where the serial sits, hence the
 * "last long run of digits" rule rather than a fixed segment:
 *
 *     2023-00056-BN-0  ->  056        (student)
 *     FAC-2026-001     ->  001        (adviser)
 *     COORDINATOR-01   ->  01         (admin, no 3-digit run to find)
 *
 * The result satisfies the password rule the services enforce on save — six
 * characters plus an uppercase and a digit — for any name of two characters or
 * more. Where it cannot (an ID with no digits in it at all), a random tail
 * stands in rather than letting the form fail validation on submit.
 *
 * Include once, before the footer, on any page with a `#generatePasswordBtn`.
 */
?>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {

    var btn = document.getElementById('generatePasswordBtn');
    if (!btn) { return; }

    var pwField = document.getElementById('password'),
        nameField = document.getElementById('full_name'),
        idField = document.getElementById('student_id') || document.getElementById('faculty_id'),
        hint = document.getElementById('passHint'),
        hintText = hint ? hint.textContent : '';

    /* Titles and generational suffixes are not part of the name: the roll is
       full of "Dr. Maria Santos", and DrSantos would be the wrong password. */
    var SKIP = /^(dr|prof|professor|engr|arch|atty|hon|rev|mr|mrs|ms|miss|sir|maam|jr|sr|ii|iii|iv)$/i;

    /* Surname particles belong to the surname — "Juan Dela Cruz" is a Dela Cruz,
       not a Cruz — so they are glued back on rather than dropped as middles. */
    var PARTICLE = /^(dela|delos|delas|de|del|dos|das|da|di|la|las|los|san|santa|sta|sto|van|von|bin)$/i;

    function nameWords(full) {
        var out = [];
        String(full || '').split(/[\s,]+/).forEach(function (raw) {
            var w = raw.replace(/[^A-Za-z]/g, '');       // "Dr." -> "Dr", "D'Cruz" -> "DCruz"
            if (w && !SKIP.test(w)) { out.push(w); }
        });
        return out;
    }

    function titleCase(w) {
        return w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
    }

    function nameHalf(full) {
        var w = nameWords(full);
        if (!w.length) { return ''; }

        /* Walk back over any particles so the whole surname comes along. Stops
           at index 1: the first word is the given name, never part of it. */
        var end = w.length - 1;
        while (end > 1 && PARTICLE.test(w[end - 1])) { end--; }

        var surname = w.slice(end).map(titleCase).join('');
        return w.length === 1 ? surname : titleCase(w[0]) + surname;
    }

    function idTail(id) {
        var groups = String(id || '').match(/\d+/g) || [];
        if (!groups.length) { return ''; }

        /* The year and the serial are both numbers; the serial is the last one
           long enough to be a serial. Short IDs have nothing else to offer. */
        var long = groups.filter(function (g) { return g.length >= 3; }),
            pick = long.length ? long[long.length - 1] : groups[groups.length - 1];
        return pick.slice(-3);
    }

    function say(message, focusOn) {
        if (hint) {
            hint.textContent = message;
            hint.classList.add('is-warn');
        }
        if (focusOn) { focusOn.focus(); }
    }

    function resetHint() {
        if (hint) {
            hint.textContent = hintText;
            hint.classList.remove('is-warn');
        }
    }

    btn.addEventListener('click', function () {
        var name = nameHalf(nameField ? nameField.value : '');
        if (!name) {
            say('Type the full name first — the password is built from it.', nameField);
            return;
        }

        var tail = idTail(idField ? idField.value : '');
        if (!tail) {
            if (idField && idField.value.trim() === '') {
                say('Type the ID number first — the password ends with it.', idField);
                return;
            }
            /* An ID with letters but no digits at all: keep the shape and stay
               inside the password rule rather than emitting a digitless one. */
            tail = String(Math.floor(Math.random() * 900) + 100);
        }

        resetHint();
        pwField.value = name + '-' + tail;
        pwField.dispatchEvent(new Event('input', { bubbles: true }));  // any live validity check re-runs
    });

    /* Clear the "type the name first" nudge as soon as they do, so the hint is
       not still scolding them about a box they have since filled in. */
    [nameField, idField].forEach(function (f) {
        if (!f) { return; }
        f.addEventListener('input', function () {
            if (hint && hint.classList.contains('is-warn')) { resetHint(); }
        });
    });
});
</script>
