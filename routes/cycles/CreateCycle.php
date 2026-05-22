<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Only super_admin or admin can create cycles
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['year', 'title'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $year      = (int) $data['year'];
    $title     = trim($data['title']);
    $startDate = isset($data['start_date']) ? trim($data['start_date']) : null;
    $endDate   = isset($data['end_date'])   ? trim($data['end_date'])   : null;
    $isActive  = isset($data['is_active'])  ? (int) $data['is_active']  : 0;

    // Validate year
    $currentYear = (int) date('Y');
    if ($year < 2020 || $year > $currentYear + 5) {
        throw new Exception("Invalid year. Must be between 2020 and " . ($currentYear + 5) . ".", 400);
    }

    // Validate dates if provided
    if ($startDate && !strtotime($startDate)) {
        throw new Exception("Invalid start_date format. Use YYYY-MM-DD.", 400);
    }
    if ($endDate && !strtotime($endDate)) {
        throw new Exception("Invalid end_date format. Use YYYY-MM-DD.", 400);
    }
    if ($startDate && $endDate && strtotime($startDate) >= strtotime($endDate)) {
        throw new Exception("start_date must be before end_date.", 400);
    }

    /**
     * company_id:
     *   super_admin can create for any company
     *   admin is locked to their own company
     */
    if ($loggedInUserRole === 'super_admin' && isset($data['company_id'])) {
        $companyId = (int) $data['company_id'];
    } else {
        $companyId = $loggedInCompanyId;
    }

    // Validate company exists
    $companyStmt = $conn->prepare("SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1");
    $companyStmt->bind_param("i", $companyId);
    $companyStmt->execute();
    if ($companyStmt->get_result()->num_rows === 0) {
        throw new Exception("Company not found or inactive.", 404);
    }
    $companyStmt->close();

    // Check for duplicate year within the same company
    $dupStmt = $conn->prepare("
        SELECT id FROM appraisal_cycles
        WHERE company_id = ? AND year = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("ii", $companyId, $year);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        throw new Exception("An appraisal cycle for year {$year} already exists for this company.", 400);
    }
    $dupStmt->close();

    /**
     * If this cycle is being set as active, deactivate all other cycles
     * for the same company first — only one active cycle per company at a time
     */
    if ($isActive === 1) {
        $deactivateStmt = $conn->prepare("
            UPDATE appraisal_cycles SET is_active = 0 WHERE company_id = ?
        ");
        $deactivateStmt->bind_param("i", $companyId);
        $deactivateStmt->execute();
        $deactivateStmt->close();
    }

    // Insert cycle
    $insertStmt = $conn->prepare("
        INSERT INTO appraisal_cycles (company_id, year, title, start_date, end_date, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $insertStmt->bind_param("iisssii", $companyId, $year, $title, $startDate, $endDate, $isActive, $loggedInUserId);
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create cycle: " . $insertStmt->error, 500);
    }

    $newCycleId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log action
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_cycle";
        $targetTable = "appraisal_cycles";
        $description = "{$loggedInUserEmail} created appraisal cycle: {$title} ({$year})";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $newCycleId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Return created cycle
    $fetchStmt = $conn->prepare("
        SELECT ac.id, ac.year, ac.title, ac.start_date, ac.end_date,
               ac.is_active, ac.created_at,
               c.id AS company_id, c.code AS company_code, c.name AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = ?
        LIMIT 1
    ");
    $fetchStmt->bind_param("i", $newCycleId);
    $fetchStmt->execute();
    $newCycle = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal cycle created successfully",
        "data"    => $newCycle
    ]);

} catch (Exception $e) {
    error_log("CreateCycle Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
