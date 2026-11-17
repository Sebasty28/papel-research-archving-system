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
<?php require_once ROOT_PATH . '/includes/favicon.php'; ?>
<style nonce="<?= e(csp_nonce()) ?>">
* { box-sizing: border-box; }
html { min-height: 100%; background: #fff; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 32px 20px; background: #fff; color: #382727; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
main { width: 100%; max-width: 760px; min-width: 0; }
.brand { display: block; width: fit-content; margin: 0 auto 42px; color: #790b0b; font-size: 30px; font-weight: 800; letter-spacing: .035em; text-decoration: none; }
h1 { margin: 0; color: #4f0909; font-size: clamp(26px, 5vw, 36px); line-height: 1.2; text-align: center; }
.intro { margin: 13px 0 34px; color: #655b5b; line-height: 1.55; text-align: center; }
.cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.desk-card { width: 100%; min-height: 190px; padding: 24px; display: flex; flex-direction: column; align-items: flex-start; text-align: left; gap: 12px; border: 1px solid #e7d4d4; border-radius: 16px; background: #fff; color: inherit; box-shadow: 0 8px 28px rgba(70, 20, 20, .07); cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s; }
.desk-card:hover { border-color: #790b0b; box-shadow: 0 12px 32px rgba(70, 20, 20, .13); transform: translateY(-2px); }
.desk-card:focus-visible, .signout:focus-visible { outline: 3px solid #b87676; outline-offset: 3px; }
.kind { color: #8a6b6b; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
.name { color: #630909; font-size: 22px; font-weight: 700; line-height: 1.3; }
.open { margin-top: auto; color: #790b0b; font-size: 14px; font-weight: 700; }
.error { margin: 0 0 20px; padding: 12px 16px; border: 1px solid #d9baba; border-radius: 9px; background: #fff3f3; color: #690909; }
.signout { display: block; width: fit-content; margin: 30px auto 0; color: #6b5959; font-size: 14px; text-decoration: underline; text-underline-offset: 3px; }
@media (max-width: 600px) { body { align-items: start; padding-top: max(44px, 10vh); } .brand { margin-bottom: 36px; } .cards { grid-template-columns: 1fr; } .desk-card { min-height: 145px; } }
@media (prefers-reduced-motion: reduce) { .desk-card { transition: none; } }
</style>
</head>
<body>
<main>
    <div class="brand" aria-label="PAPEL">PAPEL</div>
    <h1>Choose your desk</h1>
    <p class="intro">You hold two positions. Select the desk you want to use now.</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="cards">
        <?= csrf_field() ?>
        <?php foreach ($posts as $index => $position): ?>
            <button class="desk-card" type="submit" name="position" value="<?= e($position) ?>">
                <span class="kind"><?= $index === 0 ? 'Primary position' : 'Another position' ?></span>
                <span class="name"><?= e($position) ?></span>
                <span class="open">Open desk &rarr;</span>
            </button>
        <?php endforeach; ?>
    </form>
    <a class="signout" href="<?= e(BASE_URL) ?>/app/auth/logout.php">Sign out</a>
</main>
</body>
</html>
