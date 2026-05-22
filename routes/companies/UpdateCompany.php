<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData = requireRoles(['super_admin']);
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $companyId = (int) $data['id'];

    if ($companyId <= 0) {
        throw new Exception("Invalid company ID.", 400);
    }

    $checkStmt = $conn->prepare("
        SELECT id, code, name, logo_url, is_active
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $checkStmt->bind_param("i", $companyId);
    $checkStmt->execute();

    $existingCompany = $checkStmt->get_result()->fetch_assoc();

    $checkStmt->close();

    if (!$existingCompany) {
        throw new Exception("Company not found.", 404);
    }

    $updateFields = [];
    $params = [];
    $types = "";

    if (isset($data['code']) && trim($data['code']) !== '') {
        $code = strtoupper(trim($data['code']));

        if (!preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
            throw new Exception("Invalid code. Use 2-20 uppercase letters, numbers or underscores only.", 400);
        }

        $dupStmt = $conn->prepare("
            SELECT id 
            FROM companies 
            WHERE code = ? AND id != ? 
            LIMIT 1
        ");

        if (!$dupStmt) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $dupStmt->bind_param("si", $code, $companyId);
        $dupStmt->execute();

        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("A company with code '{$code}' already exists.", 400);
        }

        $dupStmt->close();

        $updateFields[] = "code = ?";
        $params[] = $code;
        $types .= "s";
    }

    if (isset($data['name']) && trim($data['name']) !== '') {
        $name = trim($data['name']);

        $dupStmt = $conn->prepare("
            SELECT id 
            FROM companies 
            WHERE name = ? AND id != ? 
            LIMIT 1
        ");

        if (!$dupStmt) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $dupStmt->bind_param("si", $name, $companyId);
        $dupStmt->execute();

        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("A company with name '{$name}' already exists.", 400);
        }

        $dupStmt->close();

        $updateFields[] = "name = ?";
        $params[] = $name;
        $types .= "s";
    }

    if (array_key_exists('logo_url', $data)) {
        $logoUrl = trim((string) $data['logo_url']);
        $logoUrl = $logoUrl === '' ? null : $logoUrl;

        $updateFields[] = "logo_url = ?";
        $params[] = $logoUrl;
        $types .= "s";
    }

    if (isset($data['is_active'])) {
        $isActive = (int) $data['is_active'];

        if (!in_array($isActive, [0, 1], true)) {
            throw new Exception("Invalid is_active value. Use 1 for active or 0 for inactive.", 400);
        }

        $updateFields[] = "is_active = ?";
        $params[] = $isActive;
        $types .= "i";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    $sql = "
        UPDATE companies 
        SET " . implode(", ", $updateFields) . "
        WHERE id = ?
    ";

    $params[] = $companyId;
    $types .= "i";

    $updateStmt = $conn->prepare($sql);

    if (!$updateStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $updateStmt->bind_param($types, ...$params);

    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }

    $updateStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($logStmt) {
        $action = "update_company";
        $targetTable = "companies";
        $description = "{$loggedInUserEmail} updated company ID: {$companyId}";

        $logStmt->bind_param(
            "iissis",
            $companyId,
            $loggedInUserId,
            $action,
            $targetTable,
            $companyId,
            $description
        );

        $logStmt->execute();
        $logStmt->close();
    }

    $fetchStmt = $conn->prepare("
        SELECT 
            id,
            code,
            name,
            logo_url,
            is_active,
            created_at,
            updated_at
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    $fetchStmt->bind_param("i", $companyId);
    $fetchStmt->execute();

    $updatedCompany = $fetchStmt->get_result()->fetch_assoc();

    $fetchStmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Company updated successfully",
        "data" => $updatedCompany
    ]);

} catch (Exception $e) {
    error_log("UpdateCompany Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}