<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

function pvdash_metric_keys(): array
{
    return [
        'pv_kwh',
        'feed_in_kwh',
        'batt_in_kwh',
        'batt_out_kwh',
        'consumption_kwh',
        'grid_import_kwh',
    ];
}

function pvdash_default_metric_colors(): array
{
    return [
        'pv_kwh' => ['min' => '#8fb8ff', 'max' => '#39d98a'],
        'feed_in_kwh' => ['min' => '#91c9ff', 'max' => '#22d3ee'],
        'batt_in_kwh' => ['min' => '#ffe0a3', 'max' => '#f59e0b'],
        'batt_out_kwh' => ['min' => '#ffc2cf', 'max' => '#fb7185'],
        'consumption_kwh' => ['min' => '#b7caff', 'max' => '#5a8cff'],
        'grid_import_kwh' => ['min' => '#ffd0a1', 'max' => '#f4a84a'],
    ];
}

function pvdash_metric_colors(): array
{
    $defaults = pvdash_default_metric_colors();
    $configured = pvdash_config('metric_colors', []);
    if (!is_array($configured)) {
        return $defaults;
    }

    foreach ($defaults as $metric => $colors) {
        if (!isset($configured[$metric]) || !is_array($configured[$metric])) {
            continue;
        }
        foreach (['min', 'max'] as $kind) {
            $candidate = (string) ($configured[$metric][$kind] ?? '');
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $candidate) === 1) {
                $defaults[$metric][$kind] = strtolower($candidate);
            }
        }
    }
    return $defaults;
}

function pvdash_site_title(): string
{
    $title = trim((string) pvdash_config('site_title', 'PenguinPVDash'));
    return $title !== '' ? $title : 'PenguinPVDash';
}

function pvdash_theme(): string
{
    $theme = (string) pvdash_config('theme', 'standard');
    return in_array($theme, ['standard', 'dark', 'light'], true) ? $theme : 'standard';
}

function pvdash_table_density(): string
{
    $density = (string) pvdash_config('table_density', 'comfortable');
    return in_array($density, ['comfortable', 'compact'], true) ? $density : 'comfortable';
}

function pvdash_accent_color(): string
{
    $color = (string) pvdash_config('accent_color', '#4e8cff');
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? strtolower($color) : '#4e8cff';
}

function pvdash_html_attributes(): string
{
    return 'data-theme="' . htmlspecialchars(pvdash_theme(), ENT_QUOTES, 'UTF-8') . '"';
}

function pvdash_body_attributes(): string
{
    $metricColors = pvdash_metric_colors();
    $variables = ['--accent:' . pvdash_accent_color()];
    foreach ($metricColors as $metric => $colors) {
        $cssMetric = str_replace('_kwh', '', $metric);
        $cssMetric = str_replace('_', '-', $cssMetric);
        $variables[] = '--metric-' . $cssMetric . '-min:' . $colors['min'];
        $variables[] = '--metric-' . $cssMetric . '-max:' . $colors['max'];
    }

    return 'data-theme="' . htmlspecialchars(pvdash_theme(), ENT_QUOTES, 'UTF-8') . '" '
        . 'data-density="' . htmlspecialchars(pvdash_table_density(), ENT_QUOTES, 'UTF-8') . '" '
        . 'style="' . htmlspecialchars(implode(';', $variables), ENT_QUOTES, 'UTF-8') . '"';
}

function pvdash_custom_logo_path(): ?string
{
    $file = basename((string) pvdash_config('custom_logo_file', ''));
    if ($file === '' || preg_match('/^branding-logo\.(png|jpe?g|webp)$/i', $file) !== 1) {
        return null;
    }
    $path = dirname((string) pvdash_config('sqlite_path')) . DIRECTORY_SEPARATOR . $file;
    return is_file($path) ? $path : null;
}

function pvdash_logo_url(string $root = ''): string
{
    $custom = pvdash_custom_logo_path();
    if ($custom !== null) {
        return $root . 'brand_asset.php?v=' . rawurlencode((string) (filemtime($custom) ?: time()));
    }
    return $root . 'assets/penguin-pv-icon.png';
}

function pvdash_language_url(string $language): string
{
    $language = in_array($language, ['de', 'en'], true) ? $language : 'en';
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? './');
    $path = strtok($requestUri, '?');
    if ($path === false || $path === '') {
        $path = './';
    }
    $query = $_GET;
    $query['lang'] = $language;
    return $path . '?' . http_build_query($query);
}

function pvdash_render_language_switch(): void
{
    echo '<span class="language-switch" aria-label="' . th('nav_language') . '">';
    foreach (['de' => 'DE', 'en' => 'EN'] as $language => $label) {
        $class = 'language-link' . (APP_LANG === $language ? ' is-active' : '');
        echo '<a class="' . $class . '" href="' . htmlspecialchars(pvdash_language_url($language), ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
    }
    echo '</span>';
}

function pvdash_render_navigation(string $active, string $root = ''): void
{
    $links = [
        'dashboard' => [$root . './', 'nav_dashboard'],
        'data' => [$root . 'admin/', 'nav_data_admin'],
        'stats' => [$root . 'stats.php', 'nav_stats'],
        'settings' => [$root . 'admin/settings.php', 'nav_settings'],
    ];

    echo '<nav class="top-actions" aria-label="' . th('nav_main') . '">';
    if (pvdash_is_admin()) {
        foreach ($links as $key => [$href, $label]) {
            $class = 'button' . ($active === $key ? ' button-primary is-active' : '');
            echo '<a class="' . $class . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . th($label) . '</a>';
        }
        echo '<a class="button" href="' . htmlspecialchars($root . 'logout.php', ENT_QUOTES, 'UTF-8') . '">' . th('nav_logout') . '</a>';
    } else {
        $dashboardClass = 'button' . ($active === 'dashboard' ? ' button-primary is-active' : '');
        echo '<a class="' . $dashboardClass . '" href="' . htmlspecialchars($root . './', ENT_QUOTES, 'UTF-8') . '">' . th('nav_dashboard') . '</a>';
        if (pvdash_can_view_stats()) {
            $statsClass = 'button' . ($active === 'stats' ? ' button-primary is-active' : '');
            echo '<a class="' . $statsClass . '" href="' . htmlspecialchars($root . 'stats.php', ENT_QUOTES, 'UTF-8') . '">' . th('nav_stats') . '</a>';
        }
        echo '<a class="button" href="' . htmlspecialchars($root . 'login.php?admin=1&next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? './'), ENT_QUOTES, 'UTF-8') . '">' . th('nav_admin_login') . '</a>';
        if (pvdash_session_role() === 'guest') {
            echo '<a class="button" href="' . htmlspecialchars($root . 'logout.php', ENT_QUOTES, 'UTF-8') . '">' . th('nav_logout') . '</a>';
        }
    }
    pvdash_render_language_switch();
    echo '</nav>';
}

function pvdash_render_brand_heading(string $heading, string $root = '', bool $showRole = true): void
{
    echo '<div class="brand-title">';
    echo '<img class="brand-icon" src="' . htmlspecialchars(pvdash_logo_url($root), ENT_QUOTES, 'UTF-8') . '" alt="" width="42" height="42">';
    echo '<h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($showRole) {
        $isAdmin = pvdash_is_admin();
        echo '<span class="status-pill ' . ($isAdmin ? 'status-manual' : 'status-auto') . '">' . th($isAdmin ? 'role_admin' : 'role_guest') . '</span>';
    }
    echo '</div>';
}
