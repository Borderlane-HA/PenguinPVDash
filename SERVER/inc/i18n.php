<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function pvdash_load_translations(string $language): array
{
    $file = __DIR__ . '/../lang/' . $language . '.php';
    return is_file($file) ? (array) include $file : [];
}

$PVDASH_TRANSLATIONS = pvdash_load_translations(APP_LANG);
$PVDASH_TRANSLATIONS_EN = pvdash_load_translations('en');

function t(string $key, array $vars = []): string
{
    global $PVDASH_TRANSLATIONS, $PVDASH_TRANSLATIONS_EN;
    $text = $PVDASH_TRANSLATIONS[$key] ?? $PVDASH_TRANSLATIONS_EN[$key] ?? $key;
    foreach ($vars as $name => $value) {
        $text = str_replace('{' . $name . '}', (string) $value, $text);
    }
    return $text;
}

function th(string $key, array $vars = []): string
{
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}
