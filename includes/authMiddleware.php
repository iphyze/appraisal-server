<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/AuthSecurity.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Convert role names into a consistent comparable key.
 * Examples:
 * "Super Admin" => "super_admin"
 * "super_admin" => "super_admin"
 */
function authRoleKey($role): string
{
    return strtolower(str_replace(' ', '_', trim((string) $role)));
}

/**
 * Read authentication token.
 *
 * Primary authentication method:
 * - HttpOnly cookie issued after login.
 *
 * Backward compatibility / API testing:
 * - Bearer token from Authorization header.
 * - REDIRECT_HTTP_AUTHORIZATION for Apache/cPanel environments.
 */
function bearerOrCookieToken(): string
{
    if (!empty($_COOKIE['lambert_session'])) {
        return trim((string) $_COOKIE['lambert_session']);
    }

    $header = (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    );

    if (preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        return trim($match[1]);
    }

    return '';
}

/**
 * Authenticate the current logged-in user.
 *
 * Important:
 * - Token identifies the session/user.
 * - Role, company and account state are fetched fresh from the database.
 * - token_version invalidates old sessions after password reset/change.
 */
function authenticateUser(): array
{
    global $conn;

    $token = bearerOrCookieToken();

    if ($token === '') {
        throw new Exception('Unauthorized: Please log in to continue.', 401);
    }

    try {
        $claims = JWT::decode(
            $token,
            new Key(securityJwtSecret(), 'HS256')
        );
    } catch (Throwable $e) {
        clearAuthCookies();
        throw new Exception('Your session has expired. Please sign in again.', 401);
    }

    $userId = (int) ($claims->id ?? 0);

    if ($userId <= 0) {
        clearAuthCookies();
        throw new Exception('Unauthorized: Invalid session.', 401);
    }

    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            u.staff_scope,
            u.department,
            u.job_title,
            u.staff_type,
            u.company_id,
            u.is_active,
            u.must_change_password,
            u.password_changed_at,
            COALESCE(u.token_version, 0) AS token_version,
            r.name AS role,
            c.code AS company_code,
            c.name AS company_name
        FROM users u
        INNER JOIN roles r
            ON r.id = u.role_id
        LEFT JOIN companies c
            ON c.id = u.company_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Database error.', 500);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int) $user['is_active'] !== 1) {
        clearAuthCookies();
        throw new Exception('Unauthorized: This account is unavailable.', 401);
    }

    $claimTokenVersion = (int) ($claims->token_version ?? 0);
    $databaseTokenVersion = (int) ($user['token_version'] ?? 0);

    if ($claimTokenVersion !== $databaseTokenVersion) {
        clearAuthCookies();
        throw new Exception('Your session is no longer valid. Please sign in again.', 401);
    }

    $user['id'] = (int) $user['id'];
    $user['company_id'] = isset($user['company_id']) ? (int) $user['company_id'] : null;
    $user['is_active'] = (int) $user['is_active'];
    $user['must_change_password'] = (int) ($user['must_change_password'] ?? 0);
    $user['token_version'] = $databaseTokenVersion;
    $user['role_key'] = authRoleKey($user['role'] ?? '');

    return $user;
}

/**
 * Authenticate user and restrict route access by role.
 */
function requireRoles(array $roles): array
{
    $user = authenticateUser();

    $allowed = array_map('authRoleKey', $roles);

    if (!in_array($user['role_key'], $allowed, true)) {
        throw new Exception(
            'Unauthorized: You do not have permission to access this resource.',
            403
        );
    }

    return $user;
}

/**
 * Resolve the company scope for the current user.
 *
 * Rules:
 * - Super Admin may view all companies when view_company_id is absent or 0.
 * - Super Admin may provide ?view_company_id=123 to restrict the route.
 * - Every other role is strictly limited to their own company.
 */
function resolveCompanyScope(array $userData): ?int
{
    $role = authRoleKey($userData['role'] ?? '');

    if ($role !== 'super_admin') {
        $companyId = (int) ($userData['company_id'] ?? 0);

        if ($companyId <= 0) {
            throw new Exception('Unauthorized: No company has been assigned to this account.', 403);
        }

        return $companyId;
    }

    $viewCompanyId = isset($_GET['view_company_id'])
        ? (int) $_GET['view_company_id']
        : 0;

    if ($viewCompanyId <= 0) {
        return null;
    }

    return $viewCompanyId;
}

/**
 * Generate a safe reusable company-filter SQL fragment.
 *
 * Example:
 * $scope = resolveCompanyScope($userData);
 * $clause = buildCompanyWhereClause($scope, 'u');
 *
 * $sql = "SELECT * FROM users u WHERE 1=1" . $clause['sql'];
 */
function buildCompanyWhereClause(?int $companyScope, string $alias = ''): array
{
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias provided.');
    }

    $column = $alias !== '' ? "{$alias}.company_id" : 'company_id';

    if ($companyScope === null) {
        return [
            'sql'   => '',
            'type'  => '',
            'value' => null,
        ];
    }

    return [
        'sql'   => " AND {$column} = ?",
        'type'  => 'i',
        'value' => $companyScope,
    ];
}

/**
 * Performance capability rules.
 * Access roles control portal permissions, while appraisal capability is broader:
 * - staff, supervisor and admin users may be appraised;
 * - admin and supervisor users may conduct appraisals;
 * - super_admin manages the portal but is not an appraisal subject.
 */
function isAppraiseeRole($role): bool
{
    return authRoleKey($role) !== 'super_admin';
}

function appraiseeRoleWhere(string $roleAlias = 'r'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $roleAlias)) {
        throw new InvalidArgumentException('Invalid role alias provided.');
    }

    return "LOWER(REPLACE(TRIM({$roleAlias}.name), ' ', '_')) <> 'super_admin'";
}

function appraiserRoleWhere(string $roleAlias = 'r'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $roleAlias)) {
        throw new InvalidArgumentException('Invalid role alias provided.');
    }

    return "LOWER(REPLACE(TRIM({$roleAlias}.name), ' ', '_')) IN ('admin', 'supervisor')";
}

