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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style nonce="<?= e(csp_nonce()) ?>">
:root { --pup-maroon: #800000; --dark-maroon: #630000; --cream: #fff5f5; --ink: #330000; --border: #e6d4d4; --muted: #685d5d; }
* { box-sizing: border-box; }
html { min-height: 100%; background: #fff; }
body { margin: 0; min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; background: #fff; color: var(--ink); font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; -webkit-font-smoothing: antialiased; }
.site-top { height: 5px; flex: none; background: var(--dark-maroon); }
.site-header { flex: none; border-bottom: 1px solid var(--border); }
.header-inner { max-width: 1328px; min-height: 72px; margin: auto; padding: 0 28px; display: flex; align-items: center; }
.brand { display: inline-flex; align-items: center; gap: 13px; color: var(--pup-maroon); text-decoration: none; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 24px; font-weight: 800; line-height: 1; letter-spacing: .01em; }
.brand-mark { display: block; height: 38px; aspect-ratio: 106 / 128; background: var(--pup-maroon); -webkit-mask: url('<?= e(BASE_URL) ?>/assests/images/Logo-Papel-mask.png') center / contain no-repeat; mask: url('<?= e(BASE_URL) ?>/assests/images/Logo-Papel-mask.png') center / contain no-repeat; }
.brand-rule { width: 1px; height: 30px; background: #b17d7d; }
main { width: min(100%, 940px); margin: auto; padding: 64px 24px 72px; }
.heading { text-align: center; }
.eyebrow { margin: 0 0 11px; color: var(--pup-maroon); font-size: 11px; font-weight: 700; letter-spacing: .15em; text-transform: uppercase; }
h1 { margin: 0; color: var(--dark-maroon); font-family: 'Plus Jakarta Sans', sans-serif; font-size: clamp(30px, 4vw, 42px); font-weight: 800; line-height: 1.2; }
.intro { margin: 14px 0 40px; color: var(--muted); font-size: 15px; line-height: 1.55; }
.cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.desk-card { position: relative; width: 100%; min-height: 224px; padding: 28px; display: flex; flex-direction: column; align-items: flex-start; text-align: left; gap: 10px; overflow: hidden; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: inherit; font: inherit; box-shadow: 0 6px 18px rgba(51,0,0,.1); cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s; }
.desk-card::before { content: ''; position: absolute; inset: 0 0 auto; height: 4px; background: var(--pup-maroon); }
.desk-card:hover { border-color: var(--pup-maroon); box-shadow: 0 10px 26px rgba(51,0,0,.15); transform: translateY(-2px); }
.desk-card:focus-visible, .signout:focus-visible, .brand:focus-visible { outline: 3px solid #b17d7d; outline-offset: 3px; }
.kind { color: #806969; font-size: 11px; font-weight: 700; letter-spacing: .11em; text-transform: uppercase; }
.name { color: var(--dark-maroon); font-family: 'Plus Jakarta Sans', sans-serif; font-size: clamp(21px, 2.5vw, 25px); font-weight: 700; line-height: 1.3; overflow-wrap: anywhere; }
.open { margin-top: auto; display: inline-flex; align-items: center; gap: 12px; color: var(--pup-maroon); font-size: 14px; font-weight: 700; }
.open-arrow { display: grid; place-items: center; width: 30px; height: 30px; border: 1px solid var(--border); border-radius: 50%; background: var(--cream); font-size: 19px; line-height: 1; transition: background .15s, color .15s; }
.desk-card:hover .open-arrow { background: var(--pup-maroon); color: #fff; }
.error { margin: 0 0 20px; padding: 12px 16px; border: 1px solid #d9baba; border-radius: 6px; background: var(--cream); color: var(--dark-maroon); }
.signout { display: block; width: fit-content; margin: 34px auto 0; padding: 8px 12px; color: var(--pup-maroon); font-size: 14px; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
.site-footer { flex: none; padding: 16px 24px; border-top: 1px solid var(--border); color: #806969; font-size: 12px; text-align: center; }
@media (max-width: 600px) { .header-inner { min-height: 66px; padding: 0 20px; } .brand { font-size: 21px; } .brand-mark { height: 34px; } main { padding: 46px 20px 54px; } .intro { margin-bottom: 30px; } .cards { grid-template-columns: 1fr; gap: 14px; } .desk-card { min-height: 164px; padding: 22px; } .signout { margin-top: 26px; } }
@media (prefers-reduced-motion: reduce) { .desk-card, .open-arrow { transition: none; } }
</style>
</head>
<body>
<div class="site-top" aria-hidden="true"></div>
<header class="site-header"><div class="header-inner">
    <a class="brand" href="<?= e(BASE_URL) ?>/archive/index.php" aria-label="PAPEL Public Repository"><span class="brand-mark" aria-hidden="true"></span><span class="brand-rule" aria-hidden="true"></span><span>PAPEL</span></a>
</div></header>
<main>
    <div class="heading">
        <p class="eyebrow">Your PAPEL workspace</p>
        <h1>Choose your desk</h1>
        <p class="intro">You hold two positions. Select the desk you want to use now.</p>
    </div>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" class="cards">
        <?= csrf_field() ?>
        <?php foreach ($posts as $index => $position): ?>
            <button class="desk-card" type="submit" name="position" value="<?= e($position) ?>">
                <span class="kind"><?= $index === 0 ? 'Primary position' : 'Another position' ?></span>
                <span class="name"><?= e($position) ?></span>
                <span class="open">Open desk <span class="open-arrow" aria-hidden="true">&rarr;</span></span>
            </button>
        <?php endforeach; ?>
    </form>
    <a class="signout" href="<?= e(BASE_URL) ?>/app/auth/logout.php">Sign out</a>
</main>
<footer class="site-footer">PAPEL &middot; PUP Bi&ntilde;an Campus</footer>
</body>
</html>
