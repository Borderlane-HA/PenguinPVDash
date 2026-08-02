<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/web_auth.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/ui.php';

$next = pvdash_safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? './'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
        $error = t('auth_session_expired');
    } else {
        $role = pvdash_login((string) ($_POST['password'] ?? ''));
        if ($role !== null) {
            header('Location: ' . $next);
            exit;
        }
        $error = t('auth_invalid_password');
    }
}

$guestProtected = ((string) pvdash_config('guest_password', '')) !== '';
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>" <?= pvdash_html_attributes() ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('auth_login_title') ?> – <?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body <?= pvdash_body_attributes() ?>>
<div class="auth-shell">
  <main class="auth-card">
    <img class="login-logo<?= pvdash_custom_logo_path() !== null ? ' login-logo-custom' : '' ?>" src="<?= htmlspecialchars(pvdash_logo_url(), ENT_QUOTES, 'UTF-8') ?>" alt="">
    <div class="auth-language"><?php pvdash_render_language_switch(); ?></div>
    <h1><?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="muted"><?= $guestProtected ? th('auth_login_help_protected') : th('auth_login_help_public') ?></p>
    <?php if ($error !== ''): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="on" class="stack-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(pvdash_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>">
      <label for="password"><?= th('auth_password') ?></label>
      <input id="password" name="password" type="password" required autofocus autocomplete="current-password">
      <button type="submit" class="button button-primary"><?= th('auth_login') ?></button>
    </form>
    <?php if (!$guestProtected): ?>
      <p class="auth-back"><a href="./"><?= th('auth_back_dashboard') ?></a></p>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
