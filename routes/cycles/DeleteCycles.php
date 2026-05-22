<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    // Only super_admin or admin can delete cycles
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    // Sanitise IDs
    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        fn($id) => $id > 0
    )));

    if (empty($targetIds)) {
        throw new Exception("No valid cycle IDs provided.", 400);
    }

    // Fetch all target cycles
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $types        = str_repeat('i', count($targetIds));

    $checkStmt = $conn->prepare("
        SELECT id, company_id, year, title, is_active
        FROM appraisal_cycles
        WHERE id IN ({$placeholders})
    ");
    $checkStmt->bind_param($types, ...$targetIds);
    $checkStmt->execute();
    $foundCycles = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (count($foundCycles) === 0) {
        throw new Exception("No matching appraisal cycles found.", 404);
    }

    // ── Per-cycle authorization checks ────────────────────────────────────────
    foreach ($foundCycles as $cycle) {
        // Admin can only delete cycles in their own company
        if (
            $loggedInUserRole !== 'super_admin' &&
            (int) $cycle['company_id'] !== $loggedInCompanyId
        ) {
            throw new Exception(
                "Unauthorized: Cycle ID {$cycle['id']} does not belong to your company.",
                403
            );
        }

        // Nobody can delete an active cycle — must deactivate it first
        if ((int) $cycle['is_active'] === 1) {
            throw new Exception(
                "Cannot delete an active cycle (ID: {$cycle['id']}, Year: {$cycle['year']}). " .
                "Please deactivate it first.",
                400
            );
        }
    }

    $validIds = array_column($foundCycles, 'id');

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $delTypes        = str_repeat('i', count($validIds));

        $deleteStmt = $conn->prepare("
            DELETE FROM appraisal_cycles WHERE id IN ({$delPlaceholders})
        ");
        if (!$deleteStmt) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $deleteStmt->bind_param($delTypes, ...$validIds);
        if (!$deleteStmt->execute()) {
            throw new Exception("Delete failed: " . $deleteStmt->error, 500);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // Log one entry per deleted cycle
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$logStmt) {
            throw new Exception("Failed to prepare audit log.", 500);
        }

        foreach ($foundCycles as $cycle) {
            $action      = "delete_cycle";
            $targetTable = "appraisal_cycles";
            $targetId    = (int) $cycle['id'];
            $description = "{$loggedInUserEmail} deleted appraisal cycle: {$cycle['title']} ({$cycle['year']})";
            $logStmt->bind_param(
                "iissis",
                $loggedInCompanyId, $loggedInUserId,
                $action, $targetTable, $targetId,
                $description
            );
            $logStmt->execute();
        }
        $logStmt->close();

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "{$deletedCount} appraisal cycle(s) deleted successfully.",
            "data"    => [
                "deleted_count" => $deletedCount,
                "deleted_ids"   => $validIds,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("DeleteCycles Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
