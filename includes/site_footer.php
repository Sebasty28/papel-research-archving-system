<?php
/**
 * Universal site footer + the shared JS driving the header/footer/modal
 * (scroll-frosted header, avatar dropdown, notification bell, login modal).
 * Include right before </body>. Relies on $u having been set by
 * includes/site_header.php earlier on the same page.
 */
?>
<footer class="site-footer">
    <div class="wrap footer-inner">
        <?php /* The campus is in a span of its own because a phone drops it: the
                 whole line wants 486px and a 390px phone has 358, so it wrapped and
                 the links fell to a second line at the left-hand end. See the footer
                 block in includes/site_head.php. */ ?>
        <span class="footer-copy">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?><span class="footer-campus"> &middot; PUP Bi&ntilde;an Campus</span></span>
        <nav class="footer-links">
            <a href="<?= e(BASE_URL) ?>/pages/terms_and_conditions.php">Terms &amp; Conditions</a>
            <a href="<?= e(BASE_URL) ?>/pages/privacy.php">Privacy</a>
            <?php /* Which build this is. Last in the row, after the links, and quiet:
                     it is for whoever is reporting a fault, not for a reader. Nothing
                     is printed at all where there is no VERSION file to read.

                     In its pieces (app_version_parts()), not as the one string
                     app_version_label() returns, so a phone can drop some of them:
                     there it reads "0.13.0 - bb41a6c", the number and the commit
                     being what a fault report has to quote, while which copy of the
                     site it is already shows on the chip beside the logo.

                     Each piece carries its own leading space and its own bracket, so
                     hiding one takes its punctuation with it and never strands a
                     separator. The separator belongs to the commit for that reason:
                     a staging build with no commit stamped must read "(staging)" and
                     not "(staging - )". */ ?>
            <?php if ($verParts = app_version_parts()): ?>
            <span class="footer-version"><span class="footer-version-word"><?= e($verParts['word']) ?> </span><?= e($verParts['number']) ?><?php if ($verParts['env'] !== ''): ?><span class="footer-version-env"> (<?= e($verParts['env']) ?></span><?php if ($verParts['build'] !== ''): ?><span class="footer-version-build"> &middot; <?= e($verParts['build']) ?></span><?php endif; ?><span class="footer-version-env">)</span><?php endif; ?></span>
            <?php endif; ?>
        </nav>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script nonce="<?= function_exists('csp_nonce') ? csp_nonce() : '' ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Frosted header on scroll
    var siteHeader = document.getElementById('siteHeader');
    if (siteHeader) {
        var onScroll = function () {
            siteHeader.classList.toggle('scrolled', window.scrollY > 8);
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // Avatar dropdown
    var avatarBtn = document.getElementById('userAvatarBtn');
    var userDropdown = document.getElementById('userDropdown');
    if (avatarBtn && userDropdown) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            userDropdown.classList.toggle('open');
            var notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) notifDropdown.classList.remove('open');
        });
        userDropdown.addEventListener('click', function (e) { e.stopPropagation(); });
        var userDropdownClose = document.getElementById('userDropdownClose');
        if (userDropdownClose) {
            userDropdownClose.addEventListener('click', function (e) {
                e.stopPropagation();
                userDropdown.classList.remove('open');
            });
        }
    }

    // The profile menu uses the same palette choice as Settings and Quick Settings.
    var themeToggles = document.querySelectorAll('[data-profile-theme-toggle]');
    if (themeToggles.length) {
        var themeRoot = document.documentElement;
        var syncProfileTheme = function () {
            var dark = themeRoot.getAttribute('data-mode') === 'dark';
            themeToggles.forEach(function (button) {
                var label = dark ? 'Light mode' : 'Dark mode';
                button.querySelector('[data-theme-icon]').textContent = dark ? 'light_mode' : 'dark_mode';
                button.querySelector('[data-theme-label]').textContent = label;
                button.setAttribute('aria-label', label);
            });
        };
        themeToggles.forEach(function (button) {
            button.addEventListener('click', function () {
                var colour = themeRoot.getAttribute('data-mode') === 'dark' ? 'classic' : 'old-night';
                themeRoot.setAttribute('data-color', colour);
                themeRoot.setAttribute('data-mode', colour === 'old-night' ? 'dark' : 'light');
                try { localStorage.setItem('papel_color', colour); } catch (e) {}
                if (window.papelSyncQuickSettings) window.papelSyncQuickSettings();
                document.querySelectorAll('input[name="qs_color"]').forEach(function (input) {
                    input.checked = input.value === colour;
                });
                syncProfileTheme();
            });
        });
        new MutationObserver(syncProfileTheme).observe(themeRoot, {
            attributes: true,
            attributeFilter: ['data-mode', 'data-color']
        });
        syncProfileTheme();
    }

    // Notification bell dropdown + mark-read
    var notifToggle = document.getElementById('notifToggle');
    var notifDropdown = document.getElementById('notifDropdown');
    if (notifToggle && notifDropdown) {
        notifToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            notifDropdown.classList.toggle('open');
            if (userDropdown) userDropdown.classList.remove('open');
        });

        var handlerUrl = <?= json_encode(BASE_URL.'/notifications/notifications_handler.php') ?>;
        /* The handler refuses a post it cannot trace to this application: it
           can delete now, so an untraceable one must not be enough. */
        var notifToken = <?= json_encode(csrf_token()) ?>;

        /* A notification is about a paper, so clicking one goes there. Marking
           it read is sent first and the browser follows the link either way:
           a failed bookkeeping call should not strand someone on the page they
           just tried to leave. Shared between the corner dropdown and the
           centred "what's new" popup below, since both list the same rows in
           the same .notif-item shape. */
        function wireNotifItems(container) {
            container.querySelectorAll('.notif-item').forEach(function (item) {
                item.addEventListener('click', function (e) {
                    var id = item.getAttribute('data-notif-id');

                    /* An account notice has nowhere to go: what it is about is
                       the message itself, so it opens where it is rather than
                       sending the reader to a page that would only repeat it. */
                    var popup = item.getAttribute('data-notif-popup');
                    if (popup && window.papelShow) {
                        e.preventDefault();
                        var lines = [popup];
                        (item.getAttribute('data-notif-detail') || '').split('\n')
                            .forEach(function (row) {
                                if (!row.trim()) return;
                                var at = row.indexOf(':');
                                lines.push(at === -1
                                    ? row
                                    : [row.slice(0, at).trim(), row.slice(at + 1).trim()]);
                            });
                        window.papelShow('Your account was updated', lines);
                    }

                    if (!item.classList.contains('unread')) return;
                    item.classList.remove('unread');
                    try {
                        if (navigator.sendBeacon) {
                            navigator.sendBeacon(handlerUrl,
                                new Blob(['action=mark_read&notification_id=' + encodeURIComponent(id)
                                          + '&_token=' + encodeURIComponent(notifToken)],
                                         { type: 'application/x-www-form-urlencoded' }));
                        } else {
                            fetch(handlerUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: 'action=mark_read&notification_id=' + encodeURIComponent(id)
                                      + '&_token=' + encodeURIComponent(notifToken),
                                keepalive: true
                            });
                        }
                    } catch (err) { /* the link still opens */ }
                });
            });
        }
        wireNotifItems(notifDropdown);

        /* All / Unread. The rows are already here, so this is a filter rather
           than another request. */
        var notifList = document.getElementById('notifList');
        var noUnread  = document.getElementById('notifNoUnread');
        // Shown only when there is nothing at all, and only on the All tab.
        var noneYet   = document.getElementById('notifNone');
        notifDropdown.querySelectorAll('.notif-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var unreadOnly = tab.dataset.filter === 'unread';
                notifDropdown.querySelectorAll('.notif-tab').forEach(function (t) {
                    t.classList.toggle('is-on', t === tab);
                });
                var shown = 0;
                notifList.querySelectorAll('.notif-item').forEach(function (i) {
                    var show = !unreadOnly || i.classList.contains('unread');
                    i.hidden = !show;
                    if (show) shown++;
                });
                if (noUnread) noUnread.hidden = !(unreadOnly && shown === 0);
                /* With no notifications at all both empty states matched and
                   the panel said "No notifications yet" and "Nothing unread"
                   one above the other. On the Unread tab only the second one
                   is the answer to what was asked. */
                if (noneYet) noneYet.hidden = unreadOnly;
            });
        });

        var notifCloseBtn = document.getElementById('notifCloseBtn');
        if (notifCloseBtn) {
            notifCloseBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notifDropdown.classList.remove('open');
            });
        }

        /* Mark all as read, or back again once everything has been read: the
           button offers whichever move is left, so the panel is never showing
           an action that would do nothing. Kept in step with the list itself,
           since a row read on its own (or put back through its right-click
           menu, includes/notif_actions.php) can be the one that empties it. */
        var markAllBtn = document.getElementById('notifMarkAllBtn');
        function paintMarkAll() {
            if (!markAllBtn) { return; }
            var items  = notifDropdown.querySelectorAll('.notif-item');
            var unread = notifDropdown.querySelectorAll('.notif-item.unread').length;
            markAllBtn.hidden = items.length === 0;
            var toRead = unread > 0;
            markAllBtn.dataset.act = toRead ? 'mark_all_read' : 'mark_all_unread';
            var icon = markAllBtn.querySelector('.material-symbols-outlined');
            if (icon) { icon.textContent = toRead ? 'done_all' : 'mark_email_unread'; }
            var label = markAllBtn.querySelector('.notif-mark-all-label');
            if (label) { label.textContent = 'Mark all as ' + (toRead ? 'read' : 'unread'); }
        }
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var act = markAllBtn.dataset.act === 'mark_all_unread' ? 'mark_all_unread' : 'mark_all_read';
                fetch(handlerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=' + act + '&_token=' + encodeURIComponent(notifToken)
                }).then(function (res) {
                    return res.json().catch(function () { return null; });
                }).then(function (data) {
                    var nowUnread = act === 'mark_all_unread';
                    notifDropdown.querySelectorAll('.notif-item').forEach(function (i) {
                        i.classList.toggle('unread', nowUnread);
                    });
                    /* The counts the server just returned, painted everywhere
                       they appear (includes/notif_actions.php owns that), with
                       the bell's badge and the Unread tab as the fallback for
                       a page without it. */
                    if (window.papelNotifCounts && data) {
                        window.papelNotifCounts(data.unread, data.total);
                    } else {
                        var left  = data ? data.unread : (nowUnread ? notifDropdown.querySelectorAll('.notif-item').length : 0);
                        var badge = notifToggle.querySelector('.notif-badge');
                        if (!left) {
                            if (badge) { badge.remove(); }
                        } else {
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'notif-badge';
                                notifToggle.appendChild(badge);
                            }
                            badge.textContent = left > 9 ? '9+' : String(left);
                        }
                        var unreadTab = notifDropdown.querySelector('.notif-tab[data-filter="unread"]');
                        if (unreadTab) { unreadTab.textContent = left ? 'Unread (' + left + ')' : 'Unread'; }
                    }
                    paintMarkAll();
                });
            });
            /* A row read or put back on its own changes which move is left. */
            notifDropdown.addEventListener('click', function () { setTimeout(paintMarkAll, 0); });
            document.addEventListener('papel:notif-changed', paintMarkAll);
            paintMarkAll();
        }

        /* The one-shot "what's new" card site_header.php pops in the centre
           of the page right after signing in, when something is unread. Its
           rows are wired the same way the dropdown's are; only closing it is
           new: a click on the dimmed backdrop, the X, or Escape all just
           remove it, without marking anything read that was not clicked. */
        var notifPopup = document.getElementById('notifPopupBackdrop');
        if (notifPopup) {
            wireNotifItems(notifPopup);
            var closePopup = function () { notifPopup.remove(); };
            var notifPopupClose = document.getElementById('notifPopupClose');
            if (notifPopupClose) notifPopupClose.addEventListener('click', closePopup);
            notifPopup.addEventListener('click', function (e) {
                if (e.target === notifPopup) closePopup();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && document.body.contains(notifPopup)) closePopup();
            });
        }
    }

    /* The phone menu. The sheet it opens is the same <nav> the wide layout
       shows in the bar, so there is one set of links and one set of active
       states, only the painting changes with the width. */
    var navToggle = document.getElementById('navToggle');
    if (navToggle) {
        var root = document.documentElement;
        var setNav = function (on) {
            root.classList.toggle('nav-open', on);
            navToggle.setAttribute('aria-expanded', on ? 'true' : 'false');
        };
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            setNav(!root.classList.contains('nav-open'));
            if (userDropdown) userDropdown.classList.remove('open');
            var nd = document.getElementById('notifDropdown');
            if (nd) nd.classList.remove('open');
        });
        /* Following a link leaves the page anyway, but an in-page one would
           otherwise leave the sheet sitting open over the destination. */
        var mainNav = document.getElementById('mainNav');
        if (mainNav) {
            mainNav.addEventListener('click', function (e) {
                if (e.target.closest('a')) setNav(false);
            });
        }
        document.addEventListener('click', function (e) {
            if (root.classList.contains('nav-open') &&
                !e.target.closest('#mainNav, #navToggle')) setNav(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setNav(false);
        });
        /* Turned to landscape or opened on a tablet, the links are back in the
           bar and the sheet is meaningless, but the open class would still be
           swapping the button's glyph to a close icon. */
        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) setNav(false);
        });
    }

    // "Resources" nav dropdown (About/Help/Contact, shown for logged-in users)
    var navMoreBtn = document.getElementById('navMoreBtn');
    var navMoreDropdown = document.getElementById('navMoreDropdown');
    if (navMoreBtn && navMoreDropdown) {
        navMoreBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = navMoreDropdown.classList.toggle('open');
            navMoreBtn.classList.toggle('open', isOpen);
            navMoreBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (userDropdown) userDropdown.classList.remove('open');
            if (notifDropdown) notifDropdown.classList.remove('open');
        });
    }

    /* Click anywhere else and the open dropdowns close.

       "Anywhere else" is the important part: this used to fire on every click
       in the document, which meant anything *inside* a dropdown had to remember
       to stop the event, and whatever forgot would shut the panel it lived in.
       The All/Unread tabs forgot. Asking the click where it came from fixes it
       for every control, including ones added later. */
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#notifDropdown, #userDropdown, #navMoreDropdown')) {
            return;
        }
        if (userDropdown) userDropdown.classList.remove('open');
        if (notifDropdown) notifDropdown.classList.remove('open');
        if (navMoreDropdown) navMoreDropdown.classList.remove('open');
        if (navMoreBtn) { navMoreBtn.classList.remove('open'); navMoreBtn.setAttribute('aria-expanded', 'false'); }
    });

    <?php if (!$u): ?>
    // Login modal
    var modalBackdrop = document.getElementById('modalBackdrop');
    var loginPanel = document.getElementById('loginPanel');
    var expandBtn = document.getElementById('expandBtn');
    var expandIcon = document.getElementById('expandIcon');
    var selectedRoleInput = document.getElementById('selectedRoleInput');
    var idFieldLabel = document.getElementById('idFieldLabel');
    var birthdateGroup = document.getElementById('birthdateGroup');

    /* Everything except the panel is put out of reach while it is open. The
       backdrop stopped the mouse, but the keyboard walked straight past it:
       Tab and the arrow keys (includes/key_nav.php) went on into the page
       behind, where a filter could be opened, and its menu then drew on top
       of the panel it had been opened from behind. `inert` takes clicks, the
       focus order and the accessibility tree away from all of it at once. */
    /* reCAPTCHA's own containers are the exception. It puts the image
       challenge in a div of its own at the end of <body>, a sibling of this
       panel rather than a child of it, so the sweep reached it: the whole
       challenge went inert, every click on a tile fell straight through it to
       the backdrop underneath, and the backdrop's click closes the panel. The
       one thing a reader has to do to sign in therefore both refused to
       answer and shut the form on them.

       It only happened when the panel was opened from the page, after
       everything had settled and the container existed. Arriving at
       ?login_modal=1 opens the panel before reCAPTCHA has built it, so there
       was nothing to mark and the challenge worked, which is what made it
       look intermittent.

       Matched by what the element holds rather than by a class name: the
       challenge div is anonymous (no id, no class), and only the small badge
       carries one. Nothing else on the site frames recaptcha.com. */
    function isRecaptchaLayer(el) {
        return (el.classList && el.classList.contains('grecaptcha-badge'))
            || !!(el.querySelector && el.querySelector('iframe[src*="recaptcha"]'));
    }

    function setPageInert(on) {
        Array.prototype.forEach.call(document.body.children, function (el) {
            if (el === loginPanel || el === modalBackdrop) { return; }
            if (isRecaptchaLayer(el)) { return; }
            if (on) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
        });
    }

    /* A skinned dropdown left open behind the panel would still be painted
       over it: its menu is fixed, at the top level of the page, and its own
       outside-click never fired if the panel was opened from the keyboard. */
    function closeOpenMenus() {
        document.querySelectorAll('.sel-menu:not([hidden])').forEach(function (menu) {
            menu.hidden = true;
            var btn = menu._sel && menu._sel.btn;
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    // Where focus came from, so it can be put back when the panel closes.
    var loginOpener = null;

    function openLoginModal(role, trigger) {
        loginOpener = trigger || document.activeElement;
        closeOpenMenus();
        modalBackdrop.classList.add('open');
        loginPanel.classList.add('open');
        document.body.style.overflow = 'hidden';
        setPageInert(true);
        selectRole(role || 'student');
        // Into the panel, so the keyboard starts where the eye already is.
        var first = document.getElementById('modalIdentifier');
        if (first) { try { first.focus({ preventScroll: true }); } catch (err) { first.focus(); } }
    }
    function closeLoginModal() {
        modalBackdrop.classList.remove('open');
        loginPanel.classList.remove('open');
        loginPanel.classList.remove('expanded');
        updateExpandIcon();
        document.body.style.overflow = '';
        // Before the focus goes back: nothing inert can take it.
        setPageInert(false);
        if (loginOpener && document.contains(loginOpener)) {
            try { loginOpener.focus(); } catch (err) {}
        }
        loginOpener = null;
    }
    function toggleExpand() {
        loginPanel.classList.toggle('expanded');
        updateExpandIcon();
    }
    function updateExpandIcon() {
        if (!expandIcon) { return; }
        var open = loginPanel.classList.contains('expanded');
        expandIcon.textContent = open ? 'close_fullscreen' : 'open_in_full';
        /* Named for what pressing it will do, not for the state it is in, so
           it reads correctly whichever way round the panel is. */
        var btn = expandIcon.closest('button');
        if (btn) {
            btn.title = open ? 'Collapse' : 'Expand';
            btn.setAttribute('aria-label', btn.title);
            btn.setAttribute('aria-pressed', open ? 'true' : 'false');
        }
    }
    /* A pass holder signs in with the username printed on their pass, not an ID.
       Calling it "Guest ID" sent people looking for a number they were never
       given. The placeholder follows the label for the same reason, and it keeps
       the shape of a real username, which is the one thing a label cannot show. */
    var roleLabels = {
        student: 'Student ID',
        faculty: 'Faculty ID',
        guest:   'Pass username'
    };
    var rolePlaceholders = {
        student: 'Enter your Student ID',
        faculty: 'Enter your Faculty ID',
        guest:   'e.g. libraryPass_4f2a91c8'
    };
    var identifierInput = document.getElementById('modalIdentifier');
    var identifierError = document.getElementById('loginIdentifierError');
    function yearProblem(year, label) {
        var current = new Date().getFullYear();
        return Number(year) < 2000 || Number(year) > current + 1
            ? 'Check the ' + label + ' year. Use 2000 through ' + (current + 1) + '.' : '';
    }
    function identifierProblem(value, role, complete) {
        var v = value.trim();
        if (!v) { return complete ? 'Enter your ' + (role === 'guest' ? 'Library Pass username' : roleLabels[role]) + '.' : ''; }
        if (/\s/.test(v)) { return 'Remove spaces from the ' + (role === 'guest' ? 'pass username' : 'ID') + '.'; }
        if (v.indexOf('@') !== -1 && role !== 'guest') {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'Enter a complete email address, such as name@example.com.';
        }
        if (role === 'guest') {
            if (!/^[A-Za-z0-9_]+$/.test(v)) { return 'A Library Pass username uses letters, numbers and an underscore only.'; }
            if (!/^(libraryPass_|guest_)/i.test(v)) { return 'A Library Pass username starts with libraryPass_.'; }
            return complete && !/^(?:libraryPass_|guest_)[0-9a-f]{8}$/i.test(v)
                ? 'Enter the full username issued by the Librarian, such as libraryPass_4f2a91c8.' : '';
        }
        if (role === 'student') {
            if (!/^[0-9A-Za-z-]+$/.test(v)) { return 'A Student ID uses numbers and dashes, with only the campus code in letters.'; }
            if (!/^\d/.test(v)) { return 'A Student ID starts with the intake year, such as 2026-00001-BN-0.'; }
            if (v.indexOf('--') !== -1 || /[A-Za-z]{4,}/.test(v)) { return 'Check the Student ID format, such as 2026-00001-BN-0.'; }
            var intake = /^(\d{4})-/.exec(v);
            if (intake) {
                var intakeProblem = yearProblem(intake[1], 'intake');
                if (intakeProblem) { return intakeProblem; }
            }
            return complete && (v.length < 6 || v.length > 20 || (v.match(/\d/g) || []).length < 6 || /-$/.test(v))
                ? 'Enter a complete Student ID, such as 2026-00001-BN-0.' : '';
        }
        if (!/^[A-Za-z0-9-]+$/.test(v)) { return 'A Faculty ID uses letters, numbers and, on older IDs, dashes only.'; }
        if (v.length > 20) { return 'That Faculty ID is too long.'; }
        if (v.length >= 2 && !/^(?:FA|FC|FAC|HAP|DIR|LIB|COORDINATOR)/i.test(v)) {
            return 'Check the Faculty ID prefix, such as FA0000BN2026.';
        }
        var staffYear = /^FA\d{4}BN(\d{4})$/i.exec(v);
        if (staffYear) {
            var staffYearProblem = yearProblem(staffYear[1], 'Faculty ID');
            if (staffYearProblem) { return staffYearProblem; }
        }
        return complete && v.length < 6 ? 'Enter the complete Faculty ID.' : '';
    }
    function showIdentifierProblem(complete) {
        if (!identifierInput || !identifierError) { return false; }
        var problem = identifierProblem(identifierInput.value, selectedRoleInput.value, complete);
        identifierError.textContent = problem;
        identifierError.hidden = !problem;
        if (problem) { identifierInput.setAttribute('aria-invalid', 'true'); }
        else { identifierInput.removeAttribute('aria-invalid'); }
        return !!problem;
    }
    if (identifierInput) {
        identifierInput.addEventListener('input', function () { showIdentifierProblem(false); });
        identifierInput.addEventListener('blur', function () { showIdentifierProblem(true); });
    }
    function selectRole(role) {
        document.querySelectorAll('.role-card').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-role') === role);
        });
        selectedRoleInput.value = role;
        idFieldLabel.textContent = roleLabels[role] || 'ID';

        var idInput = document.getElementById('modalIdentifier');
        if (idInput) { idInput.placeholder = rolePlaceholders[role] || 'Enter your ID'; }
        showIdentifierProblem(false);

        // Where to get one: asked only by the role that has to be given one.
        var idHint = document.getElementById('idFieldHint');
        if (idHint) { idHint.hidden = role !== 'guest'; }
        var emailHint = document.getElementById('loginEmailHint');
        if (emailHint) { emailHint.hidden = role === 'guest'; }
        if (idInput) { idInput.setAttribute('aria-describedby', (role === 'guest' ? 'idFieldHint' : 'loginEmailHint') + ' loginIdentifierError'); }

        /* The birthdate was dropped from sign-in and its markup went with it,
           but this still reached for the element and threw on every role click,
           stopping whatever came after it. Guarded rather than deleted, so a
           form that still carries one keeps working. */
        if (birthdateGroup) {
            var isGuest = role === 'guest';
            birthdateGroup.style.display = isGuest ? 'none' : '';
            birthdateGroup.querySelectorAll('select').forEach(function (s) {
                if (isGuest) { s.removeAttribute('required'); } else { s.setAttribute('required', ''); }
            });
        }
    }

    document.getElementById('openModalBtn') && document.getElementById('openModalBtn').addEventListener('click', function () { openLoginModal('student', this); });
    /* The same button at the foot of the phone's menu sheet. Not .js-open-modal:
       the sheet has to be shut first, or it sits open behind the panel, and the
       opener handed over is the menu button rather than this one, since closing
       the sheet hides this one and focus cannot go back to a hidden box when the
       panel closes. */
    var navSheetLoginBtn = document.getElementById('navSheetLoginBtn');
    if (navSheetLoginBtn) {
        navSheetLoginBtn.addEventListener('click', function () {
            var burger = document.getElementById('navToggle');
            document.documentElement.classList.remove('nav-open');
            if (burger) { burger.setAttribute('aria-expanded', 'false'); }
            openLoginModal('student', burger || null);
        });
    }
    // Delegated so buttons that appear later (e.g. AJAX-swapped result lists) still work
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.js-open-modal');
        if (trigger) openLoginModal(trigger.getAttribute('data-role') || 'student', trigger);
    });
    // Escape closes it, as it closes any other dialog on the site.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && loginPanel.classList.contains('open')) { closeLoginModal(); }
    });
    document.getElementById('closeModalBtn') && document.getElementById('closeModalBtn').addEventListener('click', closeLoginModal);
    modalBackdrop && modalBackdrop.addEventListener('click', closeLoginModal);
    expandBtn && expandBtn.addEventListener('click', toggleExpand);
    document.querySelectorAll('.role-card').forEach(function (card) {
        card.addEventListener('click', function () { selectRole(card.getAttribute('data-role')); });
    });

    // Show/hide password
    var togglePasswordBtn = document.getElementById('togglePasswordBtn');
    var togglePasswordIcon = document.getElementById('togglePasswordIcon');
    var passwordInput = document.getElementById('modalPassword');
    if (togglePasswordBtn && passwordInput) {
        togglePasswordBtn.addEventListener('click', function () {
            var isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            togglePasswordIcon.textContent = isHidden ? 'visibility_off' : 'visibility';
            togglePasswordBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            togglePasswordBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        });
    }

    /* ----- Signing in without leaving the page -----
       The ID and the password are checked first, by fetch, and the page is
       only given up once they are right: app/auth/login.php answers JSON to a
       post carrying ajax=1 and redirects for everything else. A wrong password
       used to cost a whole round trip, and not a quiet one: the post left the
       page, the sign-in animation played over the wait, the repository loaded,
       and the panel came back on it with both fields empty and the message
       above them. Now nothing moves but the message.

       The form keeps its action and its method, so a browser where this script
       has not run posts it as it always did and is told the same things in the
       same words. */
    var loginForm = document.getElementById('loginModalForm');
    if (loginForm && window.fetch) {
        var loginBusy = false;

        /* The same box the server prints, in the same place: above the form,
           under the role tiles. Built fresh every time rather than refilled,
           so the five-second life flash_dismiss.php gives it starts again with
           the new message instead of running out mid-sentence on it. */
        function loginAlert(message) {
            loginPanel.querySelectorAll('.panel-alert').forEach(function (old) {
                if (old.parentNode) { old.parentNode.removeChild(old); }
            });
            var box = document.createElement('div');
            box.className = 'panel-alert error';
            box.setAttribute('role', 'alert');
            box.textContent = message;     // the server's words, never markup
            loginForm.parentNode.insertBefore(box, loginForm);
        }

        function recaptchaBox() {
            return loginForm.querySelector('[name="g-recaptcha-response"]');
        }

        /* A solved tick is spent the moment it is checked, so Google refuses
           the same token twice: without this the second attempt failed as a
           robot however right the password was. Resetting empties the field,
           which is why the gate is locked again by hand. */
        function recaptchaAgain() {
            if (typeof grecaptcha === 'undefined' || !recaptchaBox()) { return false; }
            try { grecaptcha.reset(); } catch (err) { return false; }
            if (window.papelRecaptchaExpired) { window.papelRecaptchaExpired(); }
            return true;
        }

        loginForm.addEventListener('submit', function (e) {
            if (loginBusy) { e.preventDefault(); return; }
            e.preventDefault();            // this also calls off the splash
            if (showIdentifierProblem(true)) {
                identifierInput.focus();
                return;
            }
            loginBusy = true;

            var submitBtn = loginForm.querySelector('[type="submit"]');
            if (submitBtn) { submitBtn.disabled = true; }
            /* Whether the tick box was the thing missing, decided before the
               widget is reset, since a reset empties it either way. */
            var box = recaptchaBox();
            var tickMissing = !!box && box.value === '';

            var body = new FormData(loginForm);
            body.append('ajax', '1');

            fetch(loginForm.getAttribute('action'), {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                if (data && data.success) {
                    /* Now it is a sign-in, so now the animation plays: the
                       page is about to be replaced. */
                    if (window.papelSplash) { window.papelSplash.show(); }
                    window.location.href = data.redirect;
                    return;                 // left busy: nothing else to do here
                }
                loginBusy = false;
                loginAlert((data && data.message) || 'Could not sign in. Please try again.');
                var locked = recaptchaAgain();
                if (submitBtn && !locked) { submitBtn.disabled = false; }
                var field = data && data.field;
                var correction = field === 'identifier' ? document.getElementById('modalIdentifier')
                    : field === 'password' ? document.getElementById('modalPassword') : null;
                /* Correct the field named by the server; an unknown ID should
                   not send the person back to retype a password that was never checked. */
                if (correction && !tickMissing) {
                    try { correction.focus({ preventScroll: true }); } catch (err) { correction.focus(); }
                    correction.select();
                }
            }).catch(function () {
                /* No answer, or one that is not JSON: post the form the old
                   way rather than leave somebody unable to sign in at all.
                   .submit() fires no submit event, so this cannot come back
                   round to here. */
                loginBusy = false;
                if (submitBtn) { submitBtn.disabled = false; }
                loginForm.submit();
            });
        });
    }

    <?php if ($open_modal ?? false): ?>
    openLoginModal(<?= json_encode($modal_role ?? 'student') ?>);
    <?php endif; ?>
    <?php endif; ?>
});
</script>
<?php
/* The notification bell is on every page the header is on, and an account
   notice opens its detail in the shared dialog rather than navigating. That
   dialog therefore has to exist everywhere too. require_once, and the five
   pages that pull it in themselves do the same, so only one copy is emitted
   whichever of the two runs first. */
require_once ROOT_PATH.'/includes/action_dialogs.php';
require ROOT_PATH.'/includes/theme_welcome.php';
/* The busy bar wraps fetch and XMLHttpRequest, so only one copy of it may run:
   a second would wrap the wrappers and count every request twice, and the bar
   would never reach zero. require_once, like the dialogs above. */
require_once ROOT_PATH.'/includes/loading_bar.php';
/* Arrow-key movement between links and boxes. Last on purpose: the skinned
   dropdown, the search suggestions and the PDF panel all listen for arrows on
   the document too, and listeners fire in the order they were added. Going in
   after them means they get first refusal, and this one stands down when it
   sees the key was already handled. */
require_once ROOT_PATH.'/includes/key_nav.php';
/* The close button and the five-second life of every red and green banner.
   site_head.php has it already on any page with the shared head, where it
   must be to hold a banner back before it is drawn; this is for a page that
   carries the footer without it. */
require_once ROOT_PATH.'/includes/flash_dismiss.php';
/* Right-click actions for the notification lists. Only for someone signed in:
   there is no bell, and no notification centre, for anybody else. */
if (current_user()) { require_once ROOT_PATH.'/includes/notif_actions.php'; }
/* The countdown on a student's unlocked manuscripts, and the pop-ups that
   announce one starting and one ending. It prints nothing for anybody else,
   and nothing for a student with no grant open and nothing waiting, so it
   costs those pages two indexed lookups and no markup. After loading_bar.php
   on purpose: it polls with the papelQuiet flag that file reads. */
require_once ROOT_PATH.'/includes/manuscript_timer.php';
require ROOT_PATH.'/includes/accessibility.php'; ?>
