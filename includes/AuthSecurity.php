<?php

use Firebase\JWT\JWT;

/**
 * Authentication/security helpers for the Lambert appraisal API.
 * The browser receives only an HttpOnly session cookie; JavaScript never gets
 * the signed JWT value. Mutating requests use a CSRF double-submit token.
 */

function securityEnv(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') return $_ENV[$key];
    $value = getenv($key);
    return ($value !== false && $value !== '') ? $value : $default;
}

function securityLoadEnv(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    if (class_exists('Dotenv\\Dotenv')) {
        try {
            Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        } catch (Throwable $e) {
            error_log('Environment loading warning: ' . $e->getMessage());
        }
    }
}

function securityIsProduction(): bool
{
    return strtolower((string)securityEnv('APP_ENV', 'development')) === 'production';
}

function securityIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function securityJwtSecret(): string
{
    securityLoadEnv();
    $secret = (string)securityEnv('JWT_SECRET', '');
    if (strlen($secret) < 32) {
        throw new Exception('Server authentication is not configured.', 500);
    }
    return $secret;
}

function securityCookieOptions(int $expires, bool $httpOnly = true): array
{
    $secure = securityIsHttps() || securityIsProduction();
    return [
        'expires' => $expires,
        'path' => (string)securityEnv('COOKIE_PATH', '/'),
        'domain' => (string)securityEnv('COOKIE_DOMAIN', ''),
        'secure' => $secure,
        'httponly' => $httpOnly,
        'samesite' => (string)securityEnv('COOKIE_SAMESITE', 'Lax'),
    ];
}

function securityExpirySeconds(): int
{
    return max(900, min((int)securityEnv('JWT_EXPIRES_IN', 8 * 60 * 60), 5 * 24 * 60 * 60));
}

function issueAuthCookie(string $jwt, int $expires): void
{
    setcookie('lambert_session', $jwt, securityCookieOptions($expires, true));
}

function issueCsrfToken(): string
{
    $token = bin2hex(random_bytes(32));
    setcookie('lambert_csrf', $token, securityCookieOptions(time() + securityExpirySeconds(), false));
    return $token;
}

function clearAuthCookies(): void
{
    setcookie('lambert_session', '', securityCookieOptions(time() - 3600, true));
    setcookie('lambert_csrf', '', securityCookieOptions(time() - 3600, false));
}

function makeSessionJwt(array $user): string
{
    $issued = time();
    $payload = [
        'id' => (int)$user['id'],
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'company_id' => (int)$user['company_id'],
        'token_version' => (int)($user['token_version'] ?? 0),
        'iat' => $issued,
        'exp' => $issued + securityExpirySeconds(),
    ];
    return JWT::encode($payload, securityJwtSecret(), 'HS256');
}

function startSecureSession(array $user): string
{
    $expires = time() + securityExpirySeconds();
    issueAuthCookie(makeSessionJwt($user), $expires);
    return issueCsrfToken();
}

function requireCsrfForMutation(): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return;

    $cookie = (string)($_COOKIE['lambert_csrf'] ?? '');
    $header = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($cookie === '' || $header === '' || !hash_equals($cookie, $header)) {
        throw new Exception('Invalid security token. Refresh the page and try again.', 419);
    }
}
