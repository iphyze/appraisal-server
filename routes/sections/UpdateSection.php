<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $sectionId = (int) $data['id'];

    // Fetch existing
    $checkStmt = $conn->prepare("
        SELECT * FROM appraisal_sections WHERE id = ? LIMIT 1
    ");
    $checkStmt->bind_param("i", $sectionId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) throw new Exception("Section not found.", 404);

    // Company check
    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $existing['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: You can only update sections within your company.", 403);
    }

    $updateFields = [];
    $params       = [];
    $types        = "";

    if (isset($data['label']) && trim($data['label']) !== '') {
        $updateFields[] = "label = ?";
        $params[]       = trim($data['label']);
        $types         .= "s";
    }

    if (isset($data['description'])) {
        $updateFields[] = "description = ?";
        $params[]       = trim($data['description']) ?: null;
        $types         .= "s";
    }

    if (isset($data['code'])) {
        $code = strtoupper(trim($data['code']));
        // Check duplicate code (exclude self)
        $dupStmt = $conn->prepare("
            SELECT id FROM appraisal_sections
            WHERE cycle_id = ? AND company_id = ? AND code = ? AND id != ?
            LIMIT 1
        ");
        $dupStmt->bind_param("iisi", $existing['cycle_id'], $existing['company_id'], $code, $sectionId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Section code '{$code}' already exists for this cycle.", 400);
        }
        $dupStmt->close();
        $updateFields[] = "code = ?";
        $params[]       = $code;
        $types         .= "s";
    }

    if (isset($data['weight'])) {
        $newWeight = (float) $data['weight'];
        if ($newWeight <= 0 || $newWeight > 100) {
            throw new Exception("Weight must be between 0.01 and 100.", 400);
        }

        // Recalculate total excluding self
        $weightStmt = $conn->prepare("
            SELECT COALESCE(SUM(weight), 0) AS total_weight
            FROM appraisal_sections
            WHERE cycle_id = ? AND company_id = ? AND is_active = 1 AND id != ?
        ");
        $weightStmt->bind_param("iii", $existing['cycle_id'], $existing['company_id'], $sectionId);
        $weightStmt->execute();
        $otherTotal = (float) $weightStmt->get_result()->fetch_assoc()['total_weight'];
        $weightStmt->close();

        $newTotal = $otherTotal + $newWeight;
        if ($newTotal > 100) {
            $remaining = 100 - $otherTotal;
            throw new Exception(
                "This weight would bring the total to {$newTotal}%. " .
                "Maximum is 100%. Available: {$remaining}%.",
                400
            );
        }

        $updateFields[] = "weight = ?";
        $params[]       = $newWeight;
        $types         .= "d";
    }

    if (isset($data['sort_order'])) {
        $updateFields[] = "sort_order = ?";
        $params[]       = (int) $data['sort_order'];
        $types         .= "i";
    }

    if (isset($data['is_active'])) {
        $updateFields[] = "is_active = ?";
        $params[]       = (int) $data['is_active'];
        $types         .= "i";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    $sql      = "UPDATE appraisal_sections SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $sectionId;
    $types   .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);
    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) throw new Exception("Update failed: " . $updateStmt->error, 500);
    $updateStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "update_section";
        $targetTable = "appraisal_sections";
        $description = "{$loggedInUserEmail} updated section ID: {$sectionId}";
        $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $sectionId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Fetch updated
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
    $fetchStmt->bind_param("i", $sectionId);
    $fetchStmt->execute();
    $updated = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Section updated successfully",
        "data"    => $updated
    ]);

} catch (Exception $e) {
    error_log("UpdateSection Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
