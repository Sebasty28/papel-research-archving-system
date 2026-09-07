<?php
/**
 * The Browse card, defined once for every console that shows it.
 *
 * It used to be written out three times over and said something different in
 * each: on the public repository it was a single link to your dashboard, on the
 * student dashboard it was Public Repository / Upload Paper / View Drafts, and
 * on the four review desks there was no Browse card at all. Someone moving
 * between their workspace and the repository met a different set of links each
 * way, which is the opposite of what a fixed sidebar is for.
 *
 * The list is the union of what those cards already offered, made role-aware.
 * Nothing new was invented: every destination here was already reachable from
 * one of the old cards or from the navbar.
 *
 * Upload Paper and View Drafts used to live here too, but Browse is about
 * *going somewhere else* — the repository, a desk. Those two are actions on
 * the student's own work, not destinations, so they moved into their own
 * "What you can do" card below, the same one the review desks already use for
 * exactly this distinction.
 */

/**
 * Where this person can go, in the order the card lists them.
 *
 * Each entry carries the scripts it should light up on. `tab` narrows that
 * further where one script serves two views — the student dashboard is both
 * "My Dashboard" and "View Drafts" depending on the query.
 */
function browse_card_links(?array $u): array
{
    $links = [[
        'label' => 'Public Repository',
        'href'  => BASE_URL . '/archive/index.php?browse=1',
        'match' => ['index.php'],
        'tab'   => null,
    ]];

    $role = (string)($u['user_role'] ?? '');
    /* A guest is signed in but the repository is their whole workspace, so
       there is nowhere else to offer them. */
    if (!$u || $role === 'guest') {
        return $links;
    }

    $home = role_home($role);
    $links[] = [
        'label' => role_home_label($role),
        'href'  => $home,
        'match' => [basename((string)parse_url($home, PHP_URL_PATH))],
        // The dashboard proper, as against its drafts view.
        'tab'   => $role === 'student' ? '' : null,
    ];

    return $links;
}

/**
 * Quick actions for the role — everything that acts on the reader's own
 * work or desk rather than taking them somewhere else. Empty for a role with
 * nothing of the sort (a guest, the Director, the Head of Academic
 * Programs): nobody there uploads a paper, has drafts to check on, or runs
 * a desk of their own.
 *
 * A Research Adviser and a Research Coordinator get the same quick actions
 * here as their own review desk's "What you can do" card offers (minus the
 * link back to the repository itself, redundant on the page that already
 * is one) — reading a paper on the public repository shouldn't mean losing
 * the one-click reach to Upload Paper, Analytics or Notifications that desk
 * gives them.
 */
function quick_action_links(?array $u): array
{
    $role = (string)($u['user_role'] ?? '');

    if ($role === 'student') {
        return [
            [
                'label' => 'Upload Paper',
                'href'  => BASE_URL . '/app/student/student_upload_ai.php',
                'match' => ['student_upload_ai.php', 'student_upload.php'],
                'tab'   => null,
            ],
            [
                'label' => 'View Drafts',
                'href'  => BASE_URL . '/app/student/student_dashboard.php?tab=drafts',
                'match' => ['student_dashboard.php'],
                'tab'   => 'drafts',
            ],
        ];
    }

    $isCoordinator = $role === 'admin' && ($u['admin_level'] ?? 1) == 1;
    if (!($role === 'faculty' || $isCoordinator)) {
        return [];
    }

    $links = [[
        'label' => 'Upload Paper',
        'href'  => BASE_URL . '/app/student/student_upload_ai.php',
        'match' => ['student_upload_ai.php', 'student_upload.php'],
        'tab'   => null,
    ]];
    $links[] = $role === 'faculty'
        ? ['label' => 'My Students', 'href' => BASE_URL . '/app/faculty/faculty_manage_students.php',
           'match' => ['faculty_manage_students.php'], 'tab' => null]
        : ['label' => 'Manage Faculty', 'href' => BASE_URL . '/app/admin/admin_manage_faculty.php',
           'match' => ['admin_manage_faculty.php'], 'tab' => null];
    $links[] = [
        'label' => 'Analytics',
        'href'  => BASE_URL . '/analytics/analytics_dashboard.php',
        'match' => ['analytics_dashboard.php'],
        'tab'   => null,
    ];
    $links[] = [
        'label' => 'Notifications',
        'href'  => BASE_URL . '/notifications/notification_center.php',
        'match' => ['notification_center.php'],
        'tab'   => null,
    ];

    return $links;
}

/**
 * A collapsible sidebar card of plain-text links, active-highlighted against
 * the page actually open. Shared by the Browse card and the student's "What
 * you can do" card so the two keep behaving identically rather than drifting.
 */
function sidebar_link_card_html(string $title, string $cardId, array $links): string
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $tab    = (string)($_GET['tab'] ?? '');
    $id     = e($cardId);

    $rows = '';
    foreach ($links as $link) {
        $on = in_array($script, $link['match'], true)
            && ($link['tab'] === null || $link['tab'] === $tab);
        $rows .= '<a href="' . e($link['href']) . '" class="sidebar-link'
               . ($on ? ' active' : '') . '">' . e($link['label']) . "</a>\n";
    }

    return '<div class="sidebar-card" id="' . $id . '">'
         . '<div class="sidebar-card-header is-toggle">'
         . '<button class="card-title-btn js-card-toggle" type="button" data-card="' . $id . '">' . e($title) . '</button>'
         . '<span class="card-header-tools">'
         . '<button class="card-tool card-chevron js-card-toggle" type="button" data-card="' . $id . '" aria-label="Collapse ' . e($title) . '">'
         . '<span class="material-symbols-outlined">expand_more</span></button>'
         . '</span></div>'
         . '<div class="sidebar-card-body">' . $rows . '</div>'
         . '</div>';
}

/**
 * The card itself. `$cardId` differs per page only because the collapse toggle
 * keys its remembered state on it.
 */
function browse_card_html(?array $u, string $cardId = 'browseCard'): string
{
    return sidebar_link_card_html('Browse', $cardId, browse_card_links($u));
}

/**
 * "What you can do" — the same title and placement the review desks already
 * use for a reviewer's own quick actions, offered here to students, Research
 * Advisers and Research Coordinators alike. Empty string for any other
 * role, so a page can call this unconditionally without checking who is
 * signed in first.
 */
function quick_action_card_html(?array $u, string $cardId = 'quickCard'): string
{
    $links = quick_action_links($u);
    return $links ? sidebar_link_card_html('What you can do', $cardId, $links) : '';
}
