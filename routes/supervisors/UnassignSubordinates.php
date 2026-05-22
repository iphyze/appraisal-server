<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
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

    // Resolve cycle
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

    /**
     * Block unassignment if any of the staff have already been appraised
     * in this cycle by this supervisor — unassigning would orphan the appraisal
     */
    $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
    $appraisalCheckStmt = $conn->prepare("
        SELECT staff_user_id FROM appraisals
        WHERE supervisor_id = ?
          AND cycle_id      = ?
          AND staff_user_id IN ({$placeholders})
    ");
    $checkTypes  = "ii" . str_repeat('i', count($staffIds));
    $checkParams = array_merge([$supervisorId, $cycle['id']], $staffIds);
    $appraisalCheckStmt->bind_param($checkTypes, ...$checkParams);
    $appraisalCheckStmt->execute();
    $appraised = $appraisalCheckStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $appraisalCheckStmt->close();

    if (!empty($appraised)) {
        $appraisedIds = array_column($appraised, 'staff_user_id');
        throw new Exception(
            "Cannot unassign staff member(s) with ID(s) " . implode(', ', $appraisedIds) .
            " because they have already been appraised this cycle. " .
            "Delete the appraisal first if you need to reassign them.",
            400
        );
    }

    // Delete assignments
    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare("
            DELETE FROM supervisor_assignments
            WHERE supervisor_id = ? AND cycle_id = ? AND staff_id IN ({$placeholders})
        ");
        $deleteTypes  = "ii" . str_repeat('i', count($staffIds));
        $deleteParams = array_merge([$supervisorId, $cycle['id']], $staffIds);
        $deleteStmt->bind_param($deleteTypes, ...$deleteParams);
        $deleteStmt->execute();
        $removedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $action      = "unassign_subordinates";
            $targetTable = "supervisor_assignments";
            $supName     = $supervisor['first_name'] . " " . $supervisor['last_name'];
            $description = "{$loggedInUserEmail} removed {$removedCount} staff member(s) from {$supName} for cycle {$cycle['year']}";
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
            "message" => "{$removedCount} staff member(s) unassigned successfully",
            "data"    => [
                "supervisor_id"   => $supervisorId,
                "supervisor_name" => $supervisor['first_name'] . " " . $supervisor['last_name'],
                "cycle_id"        => $cycle['id'],
                "cycle_year"      => $cycle['year'],
                "removed_ids"     => $staffIds,
                "removed_count"   => $removedCount,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("UnassignSubordinates Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
