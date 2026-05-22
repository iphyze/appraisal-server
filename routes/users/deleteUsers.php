<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    // Auth — super_admin, admin only
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    // ── Parse body ────────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    // Sanitise — ensure all IDs are positive integers
    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        fn($id) => $id > 0
    )));

    if (empty($targetIds)) {
        throw new Exception("No valid user IDs provided.", 400);
    }

    // Prevent self-deletion
    if (in_array($loggedInUserId, $targetIds, true)) {
        throw new Exception("You cannot delete your own account.", 400);
    }

    // ── Validate each target user ─────────────────────────────────────────────
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $types        = str_repeat('i', count($targetIds));

    $checkStmt = $conn->prepare("
        SELECT u.id, u.email, u.company_id, u.staff_type, r.name AS role
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id IN ({$placeholders})
    ");
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }
    $checkStmt->bind_param($types, ...$targetIds);
    $checkStmt->execute();
    $foundUsers = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (count($foundUsers) === 0) {
        throw new Exception("No matching users found.", 404);
    }

    // ── Per-user authorization checks ─────────────────────────────────────────
    foreach ($foundUsers as $target) {
        // Admin can only delete users in their own company
        if (
            $loggedInUserRole !== 'super_admin' &&
            (int) $target['company_id'] !== $loggedInCompanyId
        ) {
            throw new Exception(
                "Unauthorized: User ID {$target['id']} does not belong to your company.",
                403
            );
        }

        $targetRoleKey = authRoleKey($target['role'] ?? '');

        // Staff scope limits ordinary staff records only. It must not prevent
        // an admin from seeing peer administrator/appraiser records.
        if (authRoleKey($loggedInUserRole) === 'admin') {
            $adminScope = $userData['staff_scope'] ?? 'All';
            if (
                $adminScope !== 'All' &&
                $targetRoleKey === 'staff' &&
                $target['staff_type'] !== null &&
                $target['staff_type'] !== $adminScope
            ) {
                throw new Exception(
                    "Unauthorized: You do not have permission to delete user ID {$target['id']}.",
                    403
                );
            }
        }

        // Admin cannot delete another admin or super_admin
        if (
            authRoleKey($loggedInUserRole) === 'admin' &&
            in_array($targetRoleKey, ['admin', 'super_admin'], true)
        ) {
            throw new Exception(
                "Unauthorized: You cannot delete an admin account (ID: {$target['id']}).",
                403
            );
        }

        // Nobody can delete a super_admin except another super_admin
        if (
            $targetRoleKey === 'super_admin' &&
            authRoleKey($loggedInUserRole) !== 'super_admin'
        ) {
            throw new Exception(
                "Unauthorized: Only a super admin can delete another super admin account.",
                403
            );
        }
    }

    // ── Resolve which IDs actually exist (guard against partial mismatches) ───
    $validIds = array_column($foundUsers, 'id');

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();

    try {
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $delTypes        = str_repeat('i', count($validIds));

        $deleteStmt = $conn->prepare("
            DELETE FROM users WHERE id IN ({$delPlaceholders})
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

        // ── Log action ────────────────────────────────────────────────────────
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$logStmt) {
            throw new Exception("Failed to prepare audit log: " . $conn->error, 500);
        }

        // Log one entry per deleted user for a clean audit trail
        foreach ($foundUsers as $deleted) {
            $action      = "delete_user";
            $targetTable = "users";
            $targetId    = (int) $deleted['id'];
            $description = "{$loggedInUserEmail} deleted user account: {$deleted['email']} (ID: {$deleted['id']})";

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
            "message" => "{$deletedCount} user account(s) deleted successfully.",
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
    error_log("DeleteUsers Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}