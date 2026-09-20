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
        <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &middot; PUP Bi&ntilde;an Campus</span>
        <nav class="footer-links">
            <a href="<?= e(BASE_URL) ?>/pages/terms_and_conditions.php">Terms &amp; Conditions</a>
            <a href="<?= e(BASE_URL) ?>/pages/privacy.php">Privacy</a>
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
        /* The handler refuses a post it cannot trace to this application — it
           can delete now, so an untraceable one must not be enough. */
        var notifToken = <?= json_encode(csrf_token()) ?>;

        /* A notification is about a paper, so clicking one goes there. Marking
           it read is sent first and the browser follows the link either way —
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

        var markAllBtn = document.getElementById('notifMarkAllBtn');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                fetch(handlerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=mark_all_read&_token=' + encodeURIComponent(notifToken)
                }).then(function () {
                    notifDropdown.querySelectorAll('.notif-item.unread').forEach(function (i) { i.classList.remove('unread'); });
                    var badge = notifToggle.querySelector('.notif-badge');
                    if (badge) badge.remove();
                    var unreadTab = notifDropdown.querySelector('.notif-tab[data-filter="unread"]');
                    if (unreadTab) unreadTab.textContent = 'Unread';
                });
            });
        }

        /* The one-shot "what's new" card site_header.php pops in the centre
           of the page right after signing in, when something is unread. Its
           rows are wired the same way the dropdown's are; only closing it is
           new — a click on the dimmed backdrop, the X, or Escape all just
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
       states — only the painting changes with the width. */
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
           bar and the sheet is meaningless — but the open class would still be
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
       behind, where a filter could be opened — and its menu then drew on top
       of the panel it had been opened from behind. `inert` takes clicks, the
       focus order and the accessibility tree away from all of it at once. */
    function setPageInert(on) {
        Array.prototype.forEach.call(document.body.children, function (el) {
            if (el === loginPanel || el === modalBackdrop) { return; }
            if (on) { el.setAttribute('inert', ''); } else { el.removeAttribute('inert'); }
        });
    }

    /* A skinned dropdown left open behind the panel would still be painted
       over it — its menu is fixed, at the top level of the page, and its own
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
    /* A guest signs in with the username printed on their pass, not an ID.
       Calling it "Guest ID" sent people looking for a number they were never
       given. The placeholder follows the label for the same reason. */
    var roleLabels = {
        student: 'Student ID',
        faculty: 'Faculty ID',
        guest:   'Guest username'
    };
    var rolePlaceholders = {
        student: 'Enter your Student ID',
        faculty: 'Enter your Faculty ID',
        guest:   'e.g. guest_4f2a91c8'
    };
    function selectRole(role) {
        document.querySelectorAll('.role-card').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-role') === role);
        });
        selectedRoleInput.value = role;
        idFieldLabel.textContent = roleLabels[role] || 'ID';

        var idInput = document.getElementById('modalIdentifier');
        if (idInput) { idInput.placeholder = rolePlaceholders[role] || 'Enter your ID'; }

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
/* The close button and the five-second life of every red and green banner. */
require_once ROOT_PATH.'/includes/flash_dismiss.php';
/* Right-click actions for the notification lists. Only for someone signed in:
   there is no bell, and no notification centre, for anybody else. */
if (current_user()) { require_once ROOT_PATH.'/includes/notif_actions.php'; }
require ROOT_PATH.'/includes/accessibility.php'; ?>
