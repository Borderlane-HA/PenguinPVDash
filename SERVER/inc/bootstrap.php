<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('PVDASHSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$allowedLanguages = ['de', 'en'];
$language = (string) pvdash_config('language', 'de');
if (isset($_GET['lang']) && in_array($_GET['lang'], $allowedLanguages, true)) {
    $language = $_GET['lang'];
    setcookie('pvdash_lang', $language, [
        'expires' => time() + 31536000,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
} elseif (!empty($_COOKIE['pvdash_lang']) && in_array($_COOKIE['pvdash_lang'], $allowedLanguages, true)) {
    $language = (string) $_COOKIE['pvdash_lang'];
}
if (!in_array($language, $allowedLanguages, true)) {
    $language = 'en';
}
if (!defined('APP_LANG')) {
    define('APP_LANG', $language);
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
