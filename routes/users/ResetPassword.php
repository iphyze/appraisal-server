<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) throw new Exception('Bad Request: Only PUT/PATCH method is allowed', 405);
    $userData = requireRoles(['super_admin']);
    $loggedInUserId = (int)$userData['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);
    $targetUserId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    if ($targetUserId <= 0) throw new Exception("Field 'user_id' is required.", 400);
    if ($targetUserId === $loggedInUserId) throw new Exception('For security, use Change Password to update your own password.', 400);

    $targetStmt = $conn->prepare("SELECT u.id, u.email, u.first_name, u.last_name, c.name AS company_name FROM users u INNER JOIN companies c ON c.id = u.company_id WHERE u.id = ? LIMIT 1");
    if (!$targetStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $targetStmt->bind_param('i', $targetUserId);
    $targetStmt->execute();
    $target = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();
    if (!$target) throw new Exception('User not found.', 404);

    $defaultPassword = 'Lambert@' . date('Y');
    $hash = password_hash($defaultPassword, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NULL, token_version = COALESCE(token_version, 0) + 1, updated_by = ? WHERE id = ?");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('sii', $hash, $loggedInUserId, $targetUserId);
    if (!$stmt->execute()) throw new Exception('Password reset failed: ' . $stmt->error, 500);
    $stmt->close();

    $logStmt = $conn->prepare("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) SELECT company_id, ?, 'reset_password', 'users', id, CONCAT('Password reset for ', email) FROM users WHERE id = ? LIMIT 1");
    if ($logStmt) { $logStmt->bind_param('ii', $loggedInUserId, $targetUserId); $logStmt->execute(); $logStmt->close(); }

    echo json_encode(['status' => 'Success', 'message' => 'Password reset successfully', 'data' => ['user_id' => $targetUserId, 'default_password' => $defaultPassword, 'must_change_password' => 1]]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
