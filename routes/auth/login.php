<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/AuthSecurity.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json; charset=UTF-8');

function loginResponse(string $status, string $message, array $data = [], int $httpCode = 200, ?string $code = null): void
{
    http_response_code($httpCode);

    $payload = [
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ];

    if ($code !== null) {
        $payload['code'] = $code;
    }

    echo json_encode($payload);
    exit;
}

function ensureLoginAttemptsTable(mysqli $conn): bool
{
    return (bool) $conn->query("CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        company_id INT NOT NULL DEFAULT 0,
        ip_address VARCHAR(64) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_attempt_window (email, company_id, ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function loginIp(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function isLoginRateLimited(mysqli $conn, string $email, int $companyId, string $ip): bool
{
    if (!ensureLoginAttemptsTable($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS attempts
        FROM auth_login_attempts
        WHERE email = ?
          AND company_id = ?
          AND ip_address = ?
          AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sis', $email, $companyId, $ip);
    $stmt->execute();
    $attempts = (int) ($stmt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $stmt->close();

    return $attempts >= 5;
}

function recordFailedLogin(mysqli $conn, string $email, int $companyId, string $ip): void
{
    if (!ensureLoginAttemptsTable($conn)) {
        return;
    }

    $stmt = $conn->prepare('
        INSERT INTO auth_login_attempts (email, company_id, ip_address)
        VALUES (?, ?, ?)
    ');

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('sis', $email, $companyId, $ip);
    $stmt->execute();
    $stmt->close();
}

function clearFailedLogin(mysqli $conn, string $email, int $companyId, string $ip): void
{
    if (!ensureLoginAttemptsTable($conn)) {
        return;
    }

    $stmt = $conn->prepare('
        DELETE FROM auth_login_attempts
        WHERE email = ?
          AND company_id = ?
          AND ip_address = ?
    ');

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('sis', $email, $companyId, $ip);
    $stmt->execute();
    $stmt->close();
}

function loadMatchingLoginAccounts(mysqli $conn, string $email, int $companyId = 0): array
{
    $sql = "
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.email,
            u.password_hash,
            u.must_change_password,
            u.password_changed_at,
            COALESCE(u.token_version, 0) AS token_version,
            u.username,
            u.staff_scope,
            u.department,
            u.job_title,
            u.staff_type,
            u.is_active,
            u.company_id,
            r.name AS role,
            c.code AS company_code,
            c.name AS company_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE LOWER(TRIM(u.email)) = ?
          AND u.is_active = 1
          AND c.is_active = 1
    ";

    if ($companyId > 0) {
        $sql .= ' AND u.company_id = ?';
    }

    $sql .= ' ORDER BY c.name ASC, u.id ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error.', 500);
    }

    if ($companyId > 0) {
        $stmt->bind_param('si', $email, $companyId);
    } else {
        $stmt->bind_param('s', $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $accounts = [];

    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }

    $stmt->close();

    return $accounts;
}

try {
    securityLoadEnv();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected JSON object.', 400);
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $companyId = isset($data['company_id']) ? (int) $data['company_id'] : 0;

    if (!v::email()->notEmpty()->validate($email)) {
        throw new Exception('Enter a valid email address.', 400);
    }

    if ($password === '') {
        throw new Exception('Password is required.', 400);
    }

    if ($companyId < 0) {
        throw new Exception('Invalid workspace selected.', 400);
    }

    $ip = loginIp();
    $attemptScope = $companyId > 0 ? $companyId : 0;

    if (isLoginRateLimited($conn, $email, $attemptScope, $ip)) {
        throw new Exception('Too many failed sign-in attempts. Please wait 15 minutes and try again.', 429);
    }

    /*
     * The first sign-in request deliberately does not require company_id.
     * Only active accounts whose password matches are considered. This means
     * an attacker cannot discover the user's workspaces without first knowing
     * valid credentials.
     */
    $candidateAccounts = loadMatchingLoginAccounts($conn, $email, $companyId);
    $validAccounts = [];

    foreach ($candidateAccounts as $candidate) {
        if (password_verify($password, (string) $candidate['password_hash'])) {
            $validAccounts[] = $candidate;
        }
    }

    if (count($validAccounts) === 0) {
        recordFailedLogin($conn, $email, $attemptScope, $ip);
        throw new Exception('Invalid email or password.', 401);
    }

    /*
     * Multiple active workspaces are only presented after credentials have
     * been verified. The frontend then resubmits the same credentials with the
     * chosen company_id and the API performs verification again before login.
     */
    if ($companyId === 0 && count($validAccounts) > 1) {
        $workspaces = array_map(static function (array $account): array {
            return [
                'id'   => (int) $account['company_id'],
                'code' => (string) ($account['company_code'] ?? ''),
                'name' => (string) ($account['company_name'] ?? ''),
            ];
        }, $validAccounts);

        loginResponse(
            'SelectionRequired',
            'Your email is linked to more than one organisation. Select a workspace to continue.',
            ['companies' => $workspaces],
            200,
            'COMPANY_SELECTION_REQUIRED'
        );
    }

    $user = $validAccounts[0];

    clearFailedLogin($conn, $email, 0, $ip);
    clearFailedLogin($conn, $email, (int) $user['company_id'], $ip);

    $csrfToken = startSecureSession($user);

    $loginStmt = $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    if ($loginStmt) {
        $userId = (int) $user['id'];
        $loginStmt->bind_param('i', $userId);
        $loginStmt->execute();
        $loginStmt->close();
    }

    $fullname = trim((string) $user['first_name'] . ' ' . (string) $user['last_name']);
    $logStmt = $conn->prepare('
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ');

    if ($logStmt) {
        $loggedCompanyId = (int) $user['company_id'];
        $loggedUserId = (int) $user['id'];
        $action = 'login';
        $targetTable = 'users';
        $targetId = $loggedUserId;
        $description = "{$fullname} logged in successfully via {$user['company_name']}";
        $logStmt->bind_param('iissis', $loggedCompanyId, $loggedUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    unset($user['password_hash'], $user['token_version'], $user['is_active']);
    $user['id'] = (int) $user['id'];
    $user['company_id'] = (int) $user['company_id'];
    $user['must_change_password'] = (int) ($user['must_change_password'] ?? 0);
    $user['csrf_token'] = $csrfToken;

    loginResponse('Success', 'Login successful.', $user);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;

    $message = $code === 500 && securityIsProduction()
        ? 'An internal server error occurred.'
        : $e->getMessage();

    loginResponse('Failed', $message, [], $code);
}
