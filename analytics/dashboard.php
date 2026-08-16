<?php
/**
 * The old, thinner analytics page.
 *
 * There were two: this one — four figures and a single bar chart, shown to the
 * Research Coordinator and the Director — and analytics_dashboard.php, which had
 * the charts, the tables and the written summary but was only reachable by the
 * Research Adviser and the Head of Academic Programs. Two audiences, two
 * different answers to the same question.
 *
 * Its figures were folded into the fuller page, which now serves all four. This
 * file stays as a signpost so older links and bookmarks still arrive somewhere.
 */
require_once __DIR__ . '/../config/core.php';
require_role(['admin', 'super_admin', 'faculty', 'head_academic']);

header('Location: ' . BASE_URL . '/analytics/analytics_dashboard.php');
exit;
