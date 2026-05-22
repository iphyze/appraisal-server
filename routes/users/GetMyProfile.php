<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);
    $userData = authenticateUser();
    $userId = (int)$userData['id'];
    $stmt = $conn->prepare("SELECT u.id, u.company_id, c.code AS company_code, c.name AS company_name, u.role_id, r.name AS role, u.staff_id, u.first_name, u.last_name, u.fullname, u.username, u.email, u.staff_scope, u.department, u.job_title, u.staff_type, u.location, u.date_of_joining, u.unique_ref, u.is_active, u.must_change_password, u.last_login_at, u.created_at, u.updated_at FROM users u INNER JOIN roles r ON r.id = u.role_id INNER JOIN companies c ON c.id = u.company_id WHERE u.id = ? LIMIT 1");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('Profile not found.', 404);
    echo json_encode(['status' => 'Success', 'message' => 'Profile fetched successfully', 'data' => $row]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
