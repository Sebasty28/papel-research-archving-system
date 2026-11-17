<?php
/** A short stop after sign-in for staff who hold two positions. */
require_once __DIR__ . '/../config/core.php';
require_login();
$u = current_user();
$posts = user_positions($u);
if (count($posts) < 2) {
    header('Location: ' . role_home((string)($u['user_role'] ?? '')));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $chosen = (string)($_POST['position'] ?? '');
    if (in_array($chosen, $posts, true)) {
        $_SESSION['active_position'] = $chosen;
        header('Location: ' . ($chosen === $posts[0]
            ? role_home((string)$u['user_role']) : position_home($chosen)));
        exit;
    }
    flash('error', 'Choose one of your positions.');
    header('Location: choose_desk.php');
    exit;
}

header('Cache-Control: no-store');
$error = flash('error');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose your desk · <?= e(APP_NAME) ?></title>
<?php require_once ROOT_PATH . '/includes/site_head.php'; ?>
<?php require_once ROOT_PATH . '/includes/page_theme.php'; ?>
<?php require_once ROOT_PATH . '/includes/gate_shell.php'; ?>
<style nonce="<?= e(csp_nonce()) ?>">
.desk-options { display: grid; gap: .625rem; }
.desk-option { width: 100%; min-height: 76px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .875rem 1rem; border: 1px solid var(--border); border-radius: var(--r-control, 4px); background: var(--white); color: var(--ink); font: inherit; text-align: left; cursor: pointer; transition: border-color .15s, background .15s; }
.desk-option:hover { border-color: var(--maroon); background: var(--cream); }
.desk-option:focus-visible { outline: 2px solid var(--maroon); outline-offset: 2px; }
.desk-details { display: grid; gap: .2rem; min-width: 0; }
.desk-kind { font-size: .75rem; color: var(--grey); }
.desk-name { font-family: var(--font-head); font-size: 1rem; font-weight: 500; color: var(--maroon); overflow-wrap: anywhere; }
.desk-option > .material-symbols-outlined { flex: none; font-size: 20px; color: var(--maroon); }
@media (max-width: 600px) { .desk-option { min-height: 72px; } }
@media (prefers-reduced-motion: reduce) { .desk-option { transition: none; } }
</style>
</head>
<body>
<div class="gate-bar">
    <span class="gate-head">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="gate-name"><?= e(APP_NAME) ?></span>
    </span>
    <a class="btn-sm-outline" href="<?= e(BASE_URL) ?>/app/auth/logout.php">
        <span class="material-symbols-outlined" aria-hidden="true">logout</span> Sign out
    </a>
</div>

<div class="gate-body">
    <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="page-card">
        <div class="page-card-header">
            <span class="material-symbols-outlined" aria-hidden="true">dashboard</span>
            <h2>Choose your desk</h2>
        </div>
        <div class="page-card-body">
            <p class="gate-lede">You hold two positions. Choose the desk you want to use now.</p>
            <form method="post" class="desk-options">
                <?= csrf_field() ?>
                <?php foreach ($posts as $index => $position): ?>
                    <button class="desk-option" type="submit" name="position" value="<?= e($position) ?>">
                        <span class="desk-details">
                            <span class="desk-kind"><?= $index === 0 ? 'Primary position' : 'Another position' ?></span>
                            <span class="desk-name"><?= e($position) ?></span>
                        </span>
                        <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span>
                    </button>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
</div>
<?php require_once ROOT_PATH . '/includes/loading_bar.php'; ?>
</body>
</html>
