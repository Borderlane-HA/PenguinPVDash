<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (trim((string) pvdash_config('admin_password', '')) === '') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>PenguinPVDash setup required</title><p>PenguinPVDash is not configured yet. Set an administrator password in <code>inc/config.local.php</code> or through the Docker environment.</p>';
    exit;
}

function pvdash_password_matches(string $configured, string $provided): bool
{
    if ($configured === '') {
        return false;
    }
    $passwordInfo = password_get_info($configured);
    if (($passwordInfo['algoName'] ?? 'unknown') !== 'unknown') {
        return password_verify($provided, $configured);
    }
    return hash_equals($configured, $provided);
}

function pvdash_session_role(): ?string
{
    $role = $_SESSION['pvdash_role'] ?? null;
    if (!in_array($role, ['admin', 'guest'], true)) {
        return null;
    }

    $configuredPassword = (string) pvdash_config(
        $role === 'admin' ? 'admin_password' : 'guest_password',
        ''
    );
    $expectedFingerprint = hash('sha256', $role . '|' . $configuredPassword);
    $storedFingerprint = (string) ($_SESSION['pvdash_auth_fingerprint'] ?? '');
    if ($storedFingerprint === '' || !hash_equals($expectedFingerprint, $storedFingerprint)) {
        unset($_SESSION['pvdash_role'], $_SESSION['pvdash_auth_fingerprint']);
        return null;
    }

    return $role;
}

function pvdash_role(): ?string
{
    $role = pvdash_session_role();
    if ($role !== null) {
        return $role;
    }
    return ((string) pvdash_config('guest_password', '')) === '' ? 'guest' : null;
}

function pvdash_login(string $password): ?string
{
    $admin = (string) pvdash_config('admin_password', '');
    $guest = (string) pvdash_config('guest_password', '');

    if (pvdash_password_matches($admin, $password)) {
        session_regenerate_id(true);
        $_SESSION['pvdash_role'] = 'admin';
        $_SESSION['pvdash_auth_fingerprint'] = hash('sha256', 'admin|' . $admin);
        return 'admin';
    }
    if ($guest !== '' && pvdash_password_matches($guest, $password)) {
        session_regenerate_id(true);
        $_SESSION['pvdash_role'] = 'guest';
        $_SESSION['pvdash_auth_fingerprint'] = hash('sha256', 'guest|' . $guest);
        return 'guest';
    }
    return null;
}

function pvdash_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}

function pvdash_is_admin(): bool
{
    return pvdash_role() === 'admin';
}

function pvdash_can_view_stats(): bool
{
    return pvdash_is_admin() || (pvdash_role() === 'guest' && (bool) pvdash_config('guest_can_view_stats', true));
}

function pvdash_can_view_compensation(): bool
{
    return pvdash_is_admin() || (pvdash_role() === 'guest' && (bool) pvdash_config('guest_can_view_compensation', false));
}

function pvdash_safe_next(string $next, string $fallback = './'): string
{
    if ($next === '' || str_contains($next, '://') || str_starts_with($next, '//') || str_contains($next, "\n")) {
        return $fallback;
    }
    return $next;
}

function pvdash_require_view(bool $json = false): void
{
    if (pvdash_role() !== null) {
        return;
    }
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'authentication required']);
        exit;
    }
    $next = rawurlencode($_SERVER['REQUEST_URI'] ?? './');
    header('Location: login.php?next=' . $next);
    exit;
}

function pvdash_require_admin(bool $json = false): void
{
    if (pvdash_is_admin()) {
        return;
    }
    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'admin access required']);
        exit;
    }
    $next = rawurlencode($_SERVER['REQUEST_URI'] ?? './');
    header('Location: ../login.php?admin=1&next=' . $next);
    exit;
}

function pvdash_require_stats(bool $json = false): void
{
    pvdash_require_view($json);
    if (pvdash_can_view_stats()) {
        return;
    }
    if ($json) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'statistics are disabled for guests']);
        exit;
    }
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>403</title><p>Statistics are disabled for guests.</p><p><a href="./">Back</a></p>';
    exit;
}

function pvdash_csrf_token(): string
{
    if (empty($_SESSION['pvdash_csrf'])) {
        $_SESSION['pvdash_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['pvdash_csrf'];
}

function pvdash_verify_csrf(?string $token): bool
{
    $expected = $_SESSION['pvdash_csrf'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals((string) $expected, $token);
}
