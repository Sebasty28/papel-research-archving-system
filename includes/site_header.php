<?php
/**
 * Universal site header: brand, role-aware nav links, notification bell,
 * avatar dropdown / login trigger, and (when logged out) the login modal.
 *
 * Requires config/core.php to already be loaded by the including page.
 * Include right after <body>.
 */

$u = current_user();

// ---- Role-aware nav links (label, href, match = basename(s) that mark it active) ----
$nav_links = [];
if (!$u) {
    $nav_links = [
        ['label' => 'About PAPEL',      'href' => BASE_URL.'/pages/about_us.php',         'match' => ['about_us.php']],
        ['label' => 'Help Center',      'href' => BASE_URL.'/pages/help_center.php',      'match' => ['help_center.php']],
        ['label' => 'Contact Support',  'href' => BASE_URL.'/pages/contact_support.php',  'match' => ['contact_support.php']],
    ];
} else {
    switch ($u['user_role']) {
        case 'student':
            $nav_links = [
                ['label' => 'My Dashboard', 'href' => BASE_URL.'/app/student/student_dashboard.php', 'match' => ['student_dashboard.php']],
                ['label' => 'PUPSIS',     'href' => 'https://sis8.pup.edu.ph/', 'match' => [], 'external' => true],
            ];
            break;
        case 'faculty':
            $nav_links = [
                ['label' => role_home_label('faculty'), 'href' => BASE_URL.'/app/faculty/faculty_review_dashboard.php', 'match' => ['faculty_review_dashboard.php']],
                ['label' => 'My Students',   'href' => BASE_URL.'/app/faculty/faculty_manage_students.php',  'match' => ['faculty_manage_students.php']],
                ['label' => 'PUPSIS',        'href' => 'https://sis8.pup.edu.ph/faculty/', 'match' => [], 'external' => true],
            ];
            break;
        case 'head_academic':
            $nav_links = [
                ['label' => role_home_label('head_academic'), 'href' => BASE_URL.'/app/faculty/head_review_dashboard.php', 'match' => ['head_review_dashboard.php']],
            ];
            break;
        case 'admin':
            if (($u['admin_level'] ?? 1) == 2) {
                // Level 2 is the Head of Academic Programs, who shares that desk.
                $nav_links = [
                    ['label' => role_home_label('admin'), 'href' => BASE_URL.'/app/faculty/head_review_dashboard.php', 'match' => ['head_review_dashboard.php', 'admin_l2_dashboard.php']],
                ];
            } else {
                $nav_links = [
                    ['label' => role_home_label('admin'), 'href' => BASE_URL.'/app/admin/admin_review_dashboard.php', 'match' => ['admin_review_dashboard.php']],
                    ['label' => 'Manage Faculty',      'href' => BASE_URL.'/app/admin/admin_manage_faculty.php',  'match' => ['admin_manage_faculty.php']],
                ];
            }
            break;
        case 'super_admin':
            $nav_links = [
                ['label' => role_home_label('super_admin'), 'href' => BASE_URL.'/app/admin/super_admin_review_dashboard.php', 'match' => ['super_admin_review_dashboard.php']],
                ['label' => 'Manage Admins',       'href' => BASE_URL.'/app/admin/super_admin_manage_admins.php',   'match' => ['super_admin_manage_admins.php']],
                ['label' => 'Storage Folder',      'href' => BASE_URL.'/app/admin/gdrive_settings.php',             'match' => ['gdrive_settings.php']],
            ];
            break;
        case 'librarian':
            $nav_links = [
                ['label' => 'Manage Guests', 'href' => BASE_URL.'/app/guest/admin_manage_guests.php', 'match' => ['admin_manage_guests.php']],
            ];
            break;
        default:
            // guest role and anything unrecognized fall back to the public nav
            $nav_links = [
                ['label' => 'About PAPEL',      'href' => BASE_URL.'/pages/about_us.php',         'match' => ['about_us.php']],
                ['label' => 'Help Center',      'href' => BASE_URL.'/pages/help_center.php',      'match' => ['help_center.php']],
                ['label' => 'Contact Support',  'href' => BASE_URL.'/pages/contact_support.php',  'match' => ['contact_support.php']],
            ];
    }
}
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');

// ---- Notifications (logged-in users only) ----
$unread_count = 0;
$recent_notifs = [];
if ($u) {
    $conn = db();
    $uc_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0");
    $uc_stmt->bind_param('i', $u['user_id']);
    $uc_stmt->execute();
    $unread_count = (int)($uc_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $uc_stmt->close();

    $n_stmt = $conn->prepare("SELECT notification_id, message, is_read, created_at, paper_id, notification_type
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
    $n_stmt->bind_param('i', $u['user_id']);
    $n_stmt->execute();
    $recent_notifs = $n_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $n_stmt->close();
}
?>
<header class="site-header" id="siteHeader">
    <div class="wrap-wide header-inner">
        <?php /* The wordmark always goes to the public repository, for every
                 role — the role dashboard is reached via the nav links and the
                 avatar menu instead. */ ?>
        <a href="<?= e(BASE_URL.'/archive/index.php') ?>" class="brand"><?= e(APP_NAME) ?></a>
        <nav class="main-nav">
            <?php foreach ($nav_links as $link): ?>
                <?php $is_active = !empty($link['match']) && in_array($current_script, $link['match'], true); ?>
                <a href="<?= e($link['href']) ?>" class="<?= $is_active ? 'active' : '' ?>"<?= !empty($link['external']) ? ' target="_blank" rel="noopener"' : '' ?>><?= e($link['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($u): ?>
                <?php
                // Logged-in users still get the public/info pages, just tucked
                // into a "Resources" dropdown so the role-specific links above
                // aren't crowded out.
                $info_links = [
                    ['label' => 'About PAPEL',     'href' => BASE_URL.'/pages/about_us.php',        'match' => ['about_us.php']],
                    ['label' => 'Help Center',     'href' => BASE_URL.'/pages/help_center.php',     'match' => ['help_center.php']],
                    ['label' => 'Contact Support', 'href' => BASE_URL.'/pages/contact_support.php', 'match' => ['contact_support.php']],
                ];
                $info_active = false;
                foreach ($info_links as $il) {
                    if (in_array($current_script, $il['match'], true)) { $info_active = true; break; }
                }
                ?>
                <div class="nav-more">
                    <button class="nav-more-btn<?= $info_active ? ' active' : '' ?>" id="navMoreBtn" type="button" aria-haspopup="true" aria-expanded="false">
                        Resources <span class="material-symbols-outlined mi-18">expand_more</span>
                    </button>
                    <div class="nav-more-dropdown" id="navMoreDropdown">
                        <?php foreach ($info_links as $il): ?>
                            <a href="<?= e($il['href']) ?>" class="<?= in_array($current_script, $il['match'], true) ? 'active' : '' ?>"><?= e($il['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
        <div class="header-right">
            <?php if ($u): ?>
                <?php $display_name = (string)($u['full_name'] ?? $u['username']); $initial = strtoupper(substr($display_name, 0, 1)); ?>
                <button class="nav-icon-btn" id="notifToggle" type="button" title="Notifications" aria-label="Notifications">
                    <span class="material-symbols-outlined mi-20">notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="notif-badge"><?= $unread_count > 9 ? '9+' : $unread_count ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-dropdown-header">
                        <span>Notifications</span>
                        <span class="notif-header-tools">
                            <button class="notif-mark-all" id="notifMarkAllBtn" type="button">
                                <span class="material-symbols-outlined mi-18">done_all</span> Mark all as read
                            </button>
                            <button class="notif-close" id="notifCloseBtn" type="button" aria-label="Close">
                                <span class="material-symbols-outlined mi-18">close</span>
                            </button>
                        </span>
                    </div>

                    <?php /* Unread is the view people actually want; All is there
                             so nothing disappears once it has been read. */ ?>
                    <div class="notif-tabs" role="tablist">
                        <button class="notif-tab is-on" type="button" data-filter="all" role="tab">All</button>
                        <button class="notif-tab" type="button" data-filter="unread" role="tab">
                            Unread<?= $unread_count > 0 ? ' (' . (int)$unread_count . ')' : '' ?>
                        </button>
                    </div>

                    <div class="notif-list" id="notifList">
                        <?php if ($recent_notifs): ?>
                            <?php foreach ($recent_notifs as $n): ?>
                                <?php /* A link, not a button: it goes to the paper the
                                         notification is about, and middle-click works. */ ?>
                                <a class="notif-item<?= $n['is_read'] ? '' : ' unread' ?>"
                                   href="<?= e(notification_link(isset($n['paper_id']) ? (int)$n['paper_id'] : null, $u['user_role'])) ?>"
                                   data-notif-id="<?= (int)$n['notification_id'] ?>">
                                    <span class="notif-dot" aria-hidden="true"></span>
                                    <span class="notif-body">
                                        <span class="notif-text"><?= e($n['message']) ?></span>
                                        <small><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></small>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-empty">No notifications yet</div>
                        <?php endif; ?>
                        <div class="notif-empty" id="notifNoUnread" hidden>Nothing unread</div>
                    </div>

                    <a class="notif-view-all" href="<?= e(BASE_URL.'/notifications/notification_center.php') ?>">See More Notifications</a>
                </div>
                <button class="avatar-group" id="userAvatarBtn" type="button" title="<?= e($display_name) ?>">
                    <span class="user-avatar-btn"><?= e($initial) ?></span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>
                <div class="user-dropdown" id="userDropdown">
                    <div class="dd-head">
                        <div class="dd-identity">
                            <span class="dd-name"><?= e($display_name) ?></span>
                            <span class="dd-role"><?= e(role_label($u)) ?></span>
                        </div>
                        <button type="button" class="dd-close" id="userDropdownClose" aria-label="Close">
                            <span class="material-symbols-outlined mi-18">close</span>
                        </button>
                    </div>
                    <div class="dd-actions">
                        <a href="<?= e(BASE_URL.'/pages/settings.php') ?>">
                            <span class="material-symbols-outlined mi-20">settings</span> Settings
                        </a>
                        <a href="<?= e(BASE_URL.'/app/auth/logout.php') ?>">
                            <span class="material-symbols-outlined mi-20">logout</span> Logout
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <button class="btn-login-nav" id="openModalBtn" type="button">
                    Login <span class="material-symbols-outlined mi-18">login</span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if (!$u): ?>
<!-- ===== Login Modal ===== -->
<div class="login-backdrop" id="modalBackdrop"></div>

<div class="login-panel" id="loginPanel">
    <div class="panel-topbar">
        <div class="panel-topbar-brand">
            <a href="<?= e(BASE_URL.'/archive/index.php') ?>"><span><?= e(APP_NAME) ?></span></a>
        </div>
        <button class="panel-ctrl-btn" id="expandBtn" type="button" title="Full screen" aria-label="Full screen">
            <span class="material-symbols-outlined" id="expandIcon">fullscreen</span>
        </button>
        <button class="panel-ctrl-btn" id="closeModalBtn" type="button" title="Close" aria-label="Close">
            <span class="material-symbols-outlined">close</span>
        </button>
    </div>

    <div class="panel-body">
        <h2 class="panel-title">Welcome!</h2>
        <p class="panel-subtitle">Select your role to log in</p>

        <div class="role-selector">
            <div class="role-card" id="roleStudent" data-role="student">
                <span class="role-dot"></span>
                <div class="role-icon"><span class="material-symbols-outlined">assignment_ind</span></div>
                <span class="role-label">Student</span>
            </div>
            <div class="role-card" id="roleFaculty" data-role="faculty">
                <span class="role-dot"></span>
                <div class="role-icon"><span class="material-symbols-outlined mi-fill">school</span></div>
                <span class="role-label">Faculty / Admin</span>
            </div>
            <div class="role-card" id="roleGuest" data-role="guest">
                <span class="role-dot"></span>
                <div class="role-icon"><span class="material-symbols-outlined">account_circle</span></div>
                <span class="role-label">Guest</span>
            </div>
        </div>

        <?php if ($m = flash('error')): ?>
        <div class="panel-alert error"><?= e($m) ?></div>
        <?php endif; ?>
        <?php if ($m = flash('success')): ?>
        <div class="panel-alert success"><?= e($m) ?></div>
        <?php endif; ?>

        <form class="login-form" id="loginModalForm" method="post" action="<?= e(BASE_URL) ?>/app/auth/login.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="selected_role" id="selectedRoleInput" value="student">

            <div class="lf-group">
                <label class="lf-label" id="idFieldLabel" for="modalIdentifier">Username</label>
                <input class="lf-input" type="text" name="identifier" id="modalIdentifier" placeholder="Enter your ID" required>
            </div>

            <div class="lf-group lf-group-tight">
                <label class="lf-label" for="modalPassword">Password</label>
                <div class="lf-password-wrap">
                    <input class="lf-input" type="password" name="password" id="modalPassword" placeholder="Enter your password" required>
                    <button type="button" class="lf-password-toggle" id="togglePasswordBtn" aria-label="Show password" aria-pressed="false">
                        <span class="material-symbols-outlined mi-20" id="togglePasswordIcon">visibility</span>
                    </button>
                </div>
                <a class="lf-forgot" href="<?= e(BASE_URL) ?>/pages/contact_support.php?subject=Password%20Reset">Forgot password?</a>
            </div>

            <button type="submit" class="btn-panel-login">Sign In</button>
        </form>

        <p class="panel-footer-text">
            By continuing you agree to our
            <a href="<?= e(BASE_URL) ?>/pages/terms_and_conditions.php">Terms &amp; Conditions</a>
        </p>
    </div>
</div>
<?php endif; ?>
