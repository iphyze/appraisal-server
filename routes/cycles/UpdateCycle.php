<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    // Only super_admin or admin can update cycles
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $cycleId = (int) $data['id'];
    if ($cycleId <= 0) {
        throw new Exception("Invalid cycle ID.", 400);
    }

    // Fetch existing cycle
    $checkStmt = $conn->prepare("
        SELECT id, company_id, year, title, is_active
        FROM appraisal_cycles WHERE id = ? LIMIT 1
    ");
    $checkStmt->bind_param("i", $cycleId);
    $checkStmt->execute();
    $existingCycle = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existingCycle) {
        throw new Exception("Appraisal cycle not found.", 404);
    }

    // Admin can only update cycles within their own company
    if (
        $loggedInUserRole !== 'super_admin' &&
        (int) $existingCycle['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: You can only update cycles within your company.", 403);
    }

    // ── Build dynamic update ──────────────────────────────────────────────────
    $updateFields = [];
    $params       = [];
    $types        = "";

    if (isset($data['title']) && trim($data['title']) !== '') {
        $updateFields[] = "title = ?";
        $params[]       = trim($data['title']);
        $types         .= "s";
    }

    if (isset($data['year'])) {
        $year        = (int) $data['year'];
        $currentYear = (int) date('Y');
        if ($year < 2020 || $year > $currentYear + 5) {
            throw new Exception("Invalid year. Must be between 2020 and " . ($currentYear + 5) . ".", 400);
        }

        // Check duplicate year for this company (exclude self)
        $dupStmt = $conn->prepare("
            SELECT id FROM appraisal_cycles
            WHERE company_id = ? AND year = ? AND id != ?
            LIMIT 1
        ");
        $dupStmt->bind_param("iii", $existingCycle['company_id'], $year, $cycleId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("An appraisal cycle for year {$year} already exists for this company.", 400);
        }
        $dupStmt->close();

        $updateFields[] = "year = ?";
        $params[]       = $year;
        $types         .= "i";
    }

    if (isset($data['start_date'])) {
        if ($data['start_date'] && !strtotime($data['start_date'])) {
            throw new Exception("Invalid start_date format. Use YYYY-MM-DD.", 400);
        }
        $updateFields[] = "start_date = ?";
        $params[]       = $data['start_date'] ?: null;
        $types         .= "s";
    }

    if (isset($data['end_date'])) {
        if ($data['end_date'] && !strtotime($data['end_date'])) {
            throw new Exception("Invalid end_date format. Use YYYY-MM-DD.", 400);
        }
        $updateFields[] = "end_date = ?";
        $params[]       = $data['end_date'] ?: null;
        $types         .= "s";
    }

    /**
     * is_active toggle
     * Setting a cycle to active automatically deactivates all other cycles
     * for the same company — only one active cycle per company at a time
     */
    if (isset($data['is_active'])) {
        $newActive = (int) $data['is_active'];

        if ($newActive === 1) {
            $deactivateStmt = $conn->prepare("
                UPDATE appraisal_cycles
                SET is_active = 0
                WHERE company_id = ? AND id != ?
            ");
            $deactivateStmt->bind_param("ii", $existingCycle['company_id'], $cycleId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $updateFields[] = "is_active = ?";
        $params[]       = $newActive;
        $types         .= "i";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // Execute update
    $sql = "UPDATE appraisal_cycles SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $cycleId;
    $types   .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) {
        throw new Exception("Update failed: " . $updateStmt->error, 500);
    }
    $updateStmt->close();

    // Log action
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "update_cycle";
        $targetTable = "appraisal_cycles";
        $description = "{$loggedInUserEmail} updated appraisal cycle ID: {$cycleId}";
        $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $cycleId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    // Return updated cycle
    $fetchStmt = $conn->prepare("
        SELECT ac.id, ac.year, ac.title, ac.start_date, ac.end_date,
               ac.is_active, ac.created_at, ac.updated_at,
               c.id AS company_id, c.code AS company_code, c.name AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = ?
        LIMIT 1
    ");
    $fetchStmt->bind_param("i", $cycleId);
    $fetchStmt->execute();
    $updatedCycle = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal cycle updated successfully",
        "data"    => $updatedCycle
    ]);

} catch (Exception $e) {
    error_log("UpdateCycle Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
