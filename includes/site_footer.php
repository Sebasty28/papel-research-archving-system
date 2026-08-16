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

        /* A notification is about a paper, so clicking one goes there. Marking
           it read is sent first and the browser follows the link either way —
           a failed bookkeeping call should not strand someone on the page they
           just tried to leave. */
        notifDropdown.querySelectorAll('.notif-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var id = item.getAttribute('data-notif-id');
                if (!item.classList.contains('unread')) return;
                item.classList.remove('unread');
                try {
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(handlerUrl,
                            new Blob(['action=mark_read&notification_id=' + encodeURIComponent(id)],
                                     { type: 'application/x-www-form-urlencoded' }));
                    } else {
                        fetch(handlerUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=mark_read&notification_id=' + encodeURIComponent(id),
                            keepalive: true
                        });
                    }
                } catch (err) { /* the link still opens */ }
            });
        });

        /* All / Unread. The rows are already here, so this is a filter rather
           than another request. */
        var notifList = document.getElementById('notifList');
        var noUnread  = document.getElementById('notifNoUnread');
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
                    body: 'action=mark_all_read'
                }).then(function () {
                    notifDropdown.querySelectorAll('.notif-item.unread').forEach(function (i) { i.classList.remove('unread'); });
                    var badge = notifToggle.querySelector('.notif-badge');
                    if (badge) badge.remove();
                    var unreadTab = notifDropdown.querySelector('.notif-tab[data-filter="unread"]');
                    if (unreadTab) unreadTab.textContent = 'Unread';
                });
            });
        }
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

    function openLoginModal(role) {
        modalBackdrop.classList.add('open');
        loginPanel.classList.add('open');
        document.body.style.overflow = 'hidden';
        selectRole(role || 'student');
    }
    function closeLoginModal() {
        modalBackdrop.classList.remove('open');
        loginPanel.classList.remove('open');
        loginPanel.classList.remove('expanded');
        updateExpandIcon();
        document.body.style.overflow = '';
    }
    function toggleExpand() {
        loginPanel.classList.toggle('expanded');
        updateExpandIcon();
    }
    function updateExpandIcon() {
        if (expandIcon) expandIcon.textContent = loginPanel.classList.contains('expanded') ? 'fullscreen_exit' : 'fullscreen';
    }
    var roleLabels = { student: 'Student ID', faculty: 'Faculty ID', guest: 'Guest ID' };
    function selectRole(role) {
        document.querySelectorAll('.role-card').forEach(function (c) {
            c.classList.toggle('active', c.getAttribute('data-role') === role);
        });
        selectedRoleInput.value = role;
        idFieldLabel.textContent = roleLabels[role] || 'ID';
        var isGuest = role === 'guest';
        birthdateGroup.style.display = isGuest ? 'none' : '';
        birthdateGroup.querySelectorAll('select').forEach(function (s) {
            if (isGuest) s.removeAttribute('required'); else s.setAttribute('required', '');
        });
    }

    document.getElementById('openModalBtn') && document.getElementById('openModalBtn').addEventListener('click', function () { openLoginModal('student'); });
    // Delegated so buttons that appear later (e.g. AJAX-swapped result lists) still work
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.js-open-modal');
        if (trigger) openLoginModal(trigger.getAttribute('data-role') || 'student');
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
<?php require ROOT_PATH.'/includes/theme_welcome.php';
require ROOT_PATH.'/includes/accessibility.php'; ?>
