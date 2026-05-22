<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Only super_admin can create companies
    $userData          = requireRoles(['super_admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['code', 'name'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $code     = strtoupper(trim($data['code']));
    $name     = trim($data['name']);
    $logoUrl  = isset($data['logo_url']) ? trim($data['logo_url']) : null;
    $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;

    // Validate code — alphanumeric only, max 20 chars
    if (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
        throw new Exception("Invalid code. Use 2-20 uppercase letters, numbers or underscores only.", 400);
    }

    // Check for duplicate code
    $dupCodeStmt = $conn->prepare("SELECT id FROM companies WHERE code = ? LIMIT 1");
    $dupCodeStmt->bind_param("s", $code);
    $dupCodeStmt->execute();
    if ($dupCodeStmt->get_result()->num_rows > 0) {
        throw new Exception("A company with code '{$code}' already exists.", 400);
    }
    $dupCodeStmt->close();

    // Check for duplicate name
    $dupNameStmt = $conn->prepare("SELECT id FROM companies WHERE name = ? LIMIT 1");
    $dupNameStmt->bind_param("s", $name);
    $dupNameStmt->execute();
    if ($dupNameStmt->get_result()->num_rows > 0) {
        throw new Exception("A company with name '{$name}' already exists.", 400);
    }
    $dupNameStmt->close();

    // Insert company
    $insertStmt = $conn->prepare("
        INSERT INTO companies (code, name, logo_url, is_active)
        VALUES (?, ?, ?, ?)
    ");
    if (!$insertStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $insertStmt->bind_param("sssi", $code, $name, $logoUrl, $isActive);
    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create company: " . $insertStmt->error, 500);
    }

    $newCompanyId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log action
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_company";
        $targetTable = "companies";
        $description = "{$loggedInUserEmail} created a new company: {$name} ({$code})";
        $logStmt->bind_param(
            "iissis",
            $newCompanyId, $loggedInUserId,
            $action, $targetTable, $newCompanyId,
            $description
        );
        $logStmt->execute();
        $logStmt->close();
    }

    // Return created company
    $fetchStmt = $conn->prepare("
        SELECT id, code, name, logo_url, is_active, created_at
        FROM companies WHERE id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $newCompanyId);
    $fetchStmt->execute();
    $newCompany = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Company created successfully",
        "data"    => $newCompany
    ]);

} catch (Exception $e) {
    error_log("CreateCompany Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}