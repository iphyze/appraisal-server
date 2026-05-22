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
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    foreach (['supervisor_id', 'staff_ids'] as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $supervisorId = (int) $data['supervisor_id'];
    $staffIds     = $data['staff_ids'];
    $cycleId      = isset($data['cycle_id']) ? (int) $data['cycle_id'] : null;

    if (!is_array($staffIds) || count($staffIds) === 0) {
        throw new Exception("'staff_ids' must be a non-empty array.", 400);
    }

    // Sanitise staff IDs
    $staffIds = array_values(array_unique(array_filter(
        array_map('intval', $staffIds),
        fn($id) => $id > 0
    )));

    if (empty($staffIds)) throw new Exception("No valid staff IDs provided.", 400);

    // Validate supervisor
    $supStmt = $conn->prepare("
        SELECT u.id, u.company_id, u.first_name, u.last_name, r.name AS role
        FROM users u INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor') LIMIT 1
    ");
    $supStmt->bind_param("i", $supervisorId);
    $supStmt->execute();
    $supervisor = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();

    if (!$supervisor) throw new Exception("Supervisor not found.", 404);

    if (
        $loggedInRole !== 'super_admin' &&
        (int) $supervisor['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Supervisor does not belong to your company.", 403);
    }

    // Resolve cycle — provided or active
    if ($cycleId) {
        $cycleStmt = $conn->prepare("SELECT id, year FROM appraisal_cycles WHERE id = ? LIMIT 1");
        $cycleStmt->bind_param("i", $cycleId);
    } else {
        $cycleStmt = $conn->prepare("
            SELECT id, year FROM appraisal_cycles
            WHERE company_id = ? AND is_active = 1 LIMIT 1
        ");
        $cycleStmt->bind_param("i", $supervisor['company_id']);
    }
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) throw new Exception("No active appraisal cycle found.", 400);

    // Validate all staff IDs belong to the same company
    $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
    $validStmt    = $conn->prepare("
        SELECT u.id
        FROM users u
        INNER JOIN roles appraisee_role ON appraisee_role.id = u.role_id
        WHERE u.id IN ({$placeholders})
          AND u.company_id = ?
          AND u.is_active = 1
          AND " . appraiseeRoleWhere('appraisee_role') . "
    ");
    $validTypes  = str_repeat('i', count($staffIds)) . "i";
    $validParams = array_merge($staffIds, [$supervisor['company_id']]);
    $validStmt->bind_param($validTypes, ...$validParams);
    $validStmt->execute();
    $validStaff  = $validStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $validStmt->close();

    $validIds   = array_column($validStaff, 'id');
    $invalidIds = array_diff($staffIds, $validIds);

    if (!empty($invalidIds)) {
        throw new Exception(
            "The following staff IDs were not found or do not belong to this company: " .
            implode(', ', $invalidIds),
            400
        );
    }

    // Insert assignments — ON DUPLICATE KEY means re-assigning is safe
    $conn->begin_transaction();
    try {
        $insertStmt = $conn->prepare("
            INSERT INTO supervisor_assignments (cycle_id, supervisor_id, staff_id, assigned_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by)
        ");

        $assigned = 0;
        foreach ($validIds as $staffId) {
            $insertStmt->bind_param("iiii", $cycle['id'], $supervisorId, $staffId, $loggedInUserId);
            $insertStmt->execute();
            $assigned++;
        }
        $insertStmt->close();

        // Log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $action      = "assign_subordinates";
            $targetTable = "supervisor_assignments";
            $supName     = $supervisor['first_name'] . " " . $supervisor['last_name'];
            $description = "{$loggedInUserEmail} assigned {$assigned} staff member(s) to {$supName} for cycle {$cycle['year']}";
            $logStmt->bind_param(
                "iissis",
                $loggedInCompanyId, $loggedInUserId,
                $action, $targetTable, $supervisorId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "{$assigned} staff member(s) assigned to supervisor successfully",
            "data"    => [
                "supervisor_id"   => $supervisorId,
                "supervisor_name" => $supervisor['first_name'] . " " . $supervisor['last_name'],
                "cycle_id"        => $cycle['id'],
                "cycle_year"      => $cycle['year'],
                "assigned_ids"    => $validIds,
                "assigned_count"  => $assigned,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("AssignSubordinates Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
