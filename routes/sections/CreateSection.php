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

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    // Required fields
    foreach (['cycle_id', 'code', 'label', 'type', 'weight'] as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $cycleId     = (int) $data['cycle_id'];
    $code        = strtoupper(trim($data['code']));
    $label       = trim($data['label']);
    $description = isset($data['description']) ? trim($data['description']) : null;
    $type        = trim($data['type']);
    $weight      = (float) $data['weight'];
    $sortOrder   = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
    $isActive    = isset($data['is_active'])  ? (int) $data['is_active']  : 1;

    // super_admin can create for any company
    $companyId = ($loggedInUserRole === 'super_admin' && isset($data['company_id']))
        ? (int) $data['company_id']
        : $loggedInCompanyId;

    // Validate type
    if (!in_array($type, ['kpi', 'general'])) {
        throw new Exception("Invalid type. Allowed: kpi, general.", 400);
    }

    // Validate weight
    if ($weight <= 0 || $weight > 100) {
        throw new Exception("Weight must be between 0.01 and 100.", 400);
    }

    // Validate cycle belongs to company
    $cycleStmt = $conn->prepare("
        SELECT id FROM appraisal_cycles WHERE id = ? AND company_id = ? LIMIT 1
    ");
    $cycleStmt->bind_param("ii", $cycleId, $companyId);
    $cycleStmt->execute();
    if ($cycleStmt->get_result()->num_rows === 0) {
        throw new Exception("Cycle not found for this company.", 404);
    }
    $cycleStmt->close();

    // Only one KPI section allowed per cycle
    if ($type === 'kpi') {
        $kpiCheckStmt = $conn->prepare("
            SELECT id FROM appraisal_sections
            WHERE cycle_id = ? AND company_id = ? AND type = 'kpi'
            LIMIT 1
        ");
        $kpiCheckStmt->bind_param("ii", $cycleId, $companyId);
        $kpiCheckStmt->execute();
        if ($kpiCheckStmt->get_result()->num_rows > 0) {
            throw new Exception("A KPI section already exists for this cycle. Only one KPI section is allowed per cycle.", 400);
        }
        $kpiCheckStmt->close();
    }

    // Check duplicate code for this cycle
    $dupStmt = $conn->prepare("
        SELECT id FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ? AND code = ?
        LIMIT 1
    ");
    $dupStmt->bind_param("iis", $cycleId, $companyId, $code);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        throw new Exception("Section code '{$code}' already exists for this cycle.", 400);
    }
    $dupStmt->close();

    /**
     * Validate total weight won't exceed 100%
     * Check current total for this cycle and ensure adding this weight keeps it ≤ 100
     */
    $weightStmt = $conn->prepare("
        SELECT COALESCE(SUM(weight), 0) AS total_weight
        FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ? AND is_active = 1
    ");
    $weightStmt->bind_param("ii", $cycleId, $companyId);
    $weightStmt->execute();
    $currentTotal = (float) $weightStmt->get_result()->fetch_assoc()['total_weight'];
    $weightStmt->close();

    $newTotal = $currentTotal + $weight;
    if ($newTotal > 100) {
        $remaining = 100 - $currentTotal;
        throw new Exception(
            "Adding this section would bring the total weight to {$newTotal}%. " .
            "Maximum is 100%. You have {$remaining}% remaining for this cycle.",
            400
        );
    }

    // Insert
    $insertStmt = $conn->prepare("
        INSERT INTO appraisal_sections
          (company_id, cycle_id, code, label, description, type, weight, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt->bind_param(
        "iissssdiii",
        $companyId, $cycleId, $code, $label, $description,
        $type, $weight, $sortOrder, $isActive, $loggedInUserId
    );
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create section: " . $insertStmt->error, 500);
    }

    $newId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_section";
        $targetTable = "appraisal_sections";
        $description = "{$loggedInUserEmail} created section {$code}: {$label} (weight: {$weight}%)";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $newId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch created record
    $fetchStmt = $conn->prepare("
        SELECT s.*, ac.year AS cycle_year, ac.title AS cycle_title,
               c.code AS company_code, c.name AS company_name,
               (SELECT COALESCE(SUM(s2.weight),0) FROM appraisal_sections s2
                WHERE s2.cycle_id = s.cycle_id AND s2.company_id = s.company_id AND s2.is_active = 1
               ) AS total_weight_used
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = s.company_id
        WHERE s.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $newId);
    $fetchStmt->execute();
    $newSection = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Section created successfully",
        "data"    => $newSection
    ]);

} catch (Exception $e) {
    error_log("CreateSection Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
