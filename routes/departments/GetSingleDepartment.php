<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData          = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Department ID is required.", 400);
    }

    $departmentId = (int) $_GET['id'];
    if ($departmentId <= 0) throw new Exception("Invalid department ID.", 400);

    $stmt = $conn->prepare("
        SELECT d.id, d.company_id, d.name, d.is_active, d.created_at, d.updated_at,
               c.code AS company_code, c.name AS company_name,
               (SELECT COUNT(*) FROM users u WHERE u.company_id = d.company_id AND u.department = d.name) AS staff_count,
               (SELECT COUNT(*) FROM kpi_questions kq WHERE kq.company_id = d.company_id AND kq.department = d.name) AS question_count
        FROM departments d
        INNER JOIN companies c ON c.id = d.company_id
        WHERE d.id = ?
        LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    $stmt->bind_param('i', $departmentId);
    $stmt->execute();
    $department = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$department) throw new Exception("Department not found.", 404);

    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $department['company_id'] !== (int) $companyScope) {
        throw new Exception("Unauthorized: This department is outside the selected company scope.", 403);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Department fetched successfully',
        'data' => $department,
    ]);

} catch (Exception $e) {
    error_log("GetSingleDepartment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
