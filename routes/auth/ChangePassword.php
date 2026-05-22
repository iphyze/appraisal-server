<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AuthSecurity.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST or PUT method is allowed", 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected JSON object.', 400);
    }

    $currentPassword = trim((string)($data['current_password'] ?? ''));
    $newPassword = trim((string)($data['new_password'] ?? ''));
    $confirmPassword = trim((string)($data['confirm_password'] ?? ''));

    if ($currentPassword === '') throw new Exception('Current password is required.', 400);
    if ($newPassword === '') throw new Exception('New password is required.', 400);
    if ($confirmPassword === '') throw new Exception('Please confirm your new password.', 400);
    if ($newPassword !== $confirmPassword) throw new Exception('New password and confirmation do not match.', 400);
    if (!v::stringType()->length(8, null)->validate($newPassword)) {
        throw new Exception('New password must be at least 8 characters long.', 400);
    }
    if ($currentPassword === $newPassword) {
        throw new Exception('New password must be different from your current password.', 400);
    }

    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('i', $loggedInUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        throw new Exception('Current password is incorrect.', 400);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare("\n        UPDATE users\n        SET password_hash = ?, must_change_password = 0, password_changed_at = NOW(), token_version = COALESCE(token_version, 0) + 1, updated_by = ?\n        WHERE id = ?\n    ");
    if (!$updateStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $updateStmt->bind_param('sii', $hash, $loggedInUserId, $loggedInUserId);
    if (!$updateStmt->execute()) throw new Exception('Password update failed: ' . $updateStmt->error, 500);
    $updateStmt->close();

    $logStmt = $conn->prepare("\n        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)\n        VALUES (?, ?, ?, ?, ?, ?)\n    ");
    if ($logStmt) {
        $action = 'force_change_password';
        $targetTable = 'users';
        $description = 'User changed password before accessing the appraisal portal';
        $logStmt->bind_param('iissis', $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $loggedInUserId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    $fetchStmt = $conn->prepare("\n        SELECT\n            u.id, u.staff_id, u.first_name, u.last_name, u.email, u.username,\n            u.department, u.job_title, u.staff_type, u.staff_scope, u.location,\n            u.unique_ref, u.date_of_joining, u.is_active, u.last_login_at,\n            u.must_change_password, u.password_changed_at, u.updated_at, COALESCE(u.token_version, 0) AS token_version,\n            r.name AS role, c.id AS company_id, c.code AS company_code, c.name AS company_name\n        FROM users u\n        INNER JOIN roles r ON r.id = u.role_id\n        INNER JOIN companies c ON c.id = u.company_id\n        WHERE u.id = ?\n        LIMIT 1\n    ");
    if (!$fetchStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $fetchStmt->bind_param('i', $loggedInUserId);
    $fetchStmt->execute();
    $updatedProfile = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    if ($updatedProfile) {
        $updatedProfile['must_change_password'] = (int)($updatedProfile['must_change_password'] ?? 0);
        $updatedProfile['csrf_token'] = startSecureSession($updatedProfile);
        unset($updatedProfile['token_version']);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Password updated successfully. You can now continue.',
        'data' => $updatedProfile,
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
