<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception("Invalid request format. Expected JSON object.", 400);

    if (!isset($data['name']) || trim($data['name']) === '') {
        throw new Exception("Field 'name' is required.", 400);
    }

    $name = trim($data['name']);
    $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;
    $companyId = ($loggedInUserRole === 'super_admin' && isset($data['company_id']) && $data['company_id'] !== '')
        ? (int) $data['company_id']
        : $loggedInCompanyId;

    if (mb_strlen($name) > 150) {
        throw new Exception("Department name must not exceed 150 characters.", 400);
    }
    if (!in_array($isActive, [0, 1], true)) {
        throw new Exception("Invalid is_active value. Use 1 or 0.", 400);
    }

    $companyStmt = $conn->prepare("SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1");
    $companyStmt->bind_param('i', $companyId);
    $companyStmt->execute();
    if ($companyStmt->get_result()->num_rows === 0) {
        throw new Exception("Company not found or inactive.", 404);
    }
    $companyStmt->close();

    $dupStmt = $conn->prepare("SELECT id FROM departments WHERE company_id = ? AND name = ? LIMIT 1");
    $dupStmt->bind_param('is', $companyId, $name);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        throw new Exception("Department '{$name}' already exists for this company.", 400);
    }
    $dupStmt->close();

    $insertStmt = $conn->prepare("INSERT INTO departments (company_id, name, is_active) VALUES (?, ?, ?)");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);
    $insertStmt->bind_param('isi', $companyId, $name, $isActive);
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create department: " . $insertStmt->error, 500);
    }
    $newId = $insertStmt->insert_id;
    $insertStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action = 'create_department';
        $targetTable = 'departments';
        $description = "{$loggedInUserEmail} created department: {$name}";
        $logStmt->bind_param('iissis', $companyId, $loggedInUserId, $action, $targetTable, $newId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    $fetchStmt = $conn->prepare("
        SELECT d.id, d.company_id, d.name, d.is_active, d.created_at, d.updated_at,
               c.code AS company_code, c.name AS company_name
        FROM departments d
        INNER JOIN companies c ON c.id = d.company_id
        WHERE d.id = ?
        LIMIT 1
    ");
    $fetchStmt->bind_param('i', $newId);
    $fetchStmt->execute();
    $department = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Department created successfully',
        'data' => $department,
    ]);

} catch (Exception $e) {
    error_log("CreateDepartment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
