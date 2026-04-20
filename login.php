<?php
/**
 * Login page — only enforced when CASTLE_AUTH_ENABLED is true.
 */

require_once __DIR__ . '/bootstrap.php';

use Castle\Auth;
use Castle\Response;

if (!Auth::isEnabled()) {
    header('Location: index.php');
    exit;
}
if (Auth::isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Response::verifyCsrf()) {
        $error = 'Session expired. Please try again.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attempt($password)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Incorrect password.';
    }
}

$csrf = Response::csrfToken();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(CASTLE_BRAND_NAME) ?> — Sign in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&family=Inter:wght@300;400;500;600&display=swap">
<link rel="stylesheet" href="assets/css/theme.css">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>

<div class="castle-shell">
    <div class="card login-box card-hairline">
        <div style="text-align:center; margin-bottom:20px">
            <img src="assets/img/castle-crest.svg" alt="" style="width:72px;height:72px;margin-bottom:10px;">
            <h1 style="margin:0; background: linear-gradient(90deg, var(--castle-parchment), var(--castle-gold-hi)); -webkit-background-clip: text; background-clip: text; color: transparent;">
                <?= $h(CASTLE_BRAND_NAME) ?>
            </h1>
            <div class="tagline"><?= $h(CASTLE_BRAND_TAGLINE) ?></div>
        </div>

        <?php if ($error): ?>
            <div class="toast error" style="position:static;margin-bottom:14px;min-width:0"><?= $h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autofocus required autocomplete="current-password">
            <button type="submit" class="btn btn-gold" style="width:100%; margin-top:16px">Enter</button>
        </form>
    </div>
</div>

</body>
</html>
