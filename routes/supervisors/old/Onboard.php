<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    /**
     * Onboarding can be triggered by:
     *   a) The supervisor themselves (logging in and accepting the cycle)
     *   b) An admin/super_admin onboarding them manually
     */
    $userData         = authenticateUser();
    $loggedInUserId   = (int) $userData['id'];
    $loggedInRole     = $userData['role'];
    $loggedInCompanyId= (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    /**
     * supervisor_id resolution:
     *   - If supervisor is calling → always their own ID
     *   - If admin/super_admin is calling → must pass supervisor_id in body
     */
    if ($loggedInRole === 'supervisor') {
        $supervisorId = $loggedInUserId;
    } elseif (in_array($loggedInRole, ['super_admin', 'admin'])) {
        if (!isset($data['supervisor_id']) || !is_numeric($data['supervisor_id'])) {
            throw new Exception("Field 'supervisor_id' is required.", 400);
        }
        $supervisorId = (int) $data['supervisor_id'];
    } else {
        throw new Exception("Unauthorized: Only supervisors or admins can trigger onboarding.", 403);
    }

    // Validate supervisor exists and belongs to correct company
    $supStmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.company_id, r.name AS role
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND r.name = 'supervisor'
        LIMIT 1
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

    // Resolve the active cycle for the supervisor's company
    $cycleStmt = $conn->prepare("
        SELECT id, year, title FROM appraisal_cycles
        WHERE company_id = ? AND is_active = 1
        LIMIT 1
    ");
    $cycleStmt->bind_param("i", $supervisor['company_id']);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) {
        throw new Exception("No active appraisal cycle found for this company. Please contact an administrator.", 400);
    }

    // Check if supervisor has any subordinates assigned in this cycle
    $subStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM supervisor_assignments
        WHERE supervisor_id = ? AND cycle_id = ?
    ");
    $subStmt->bind_param("ii", $supervisorId, $cycle['id']);
    $subStmt->execute();
    $subCount = (int) $subStmt->get_result()->fetch_assoc()['cnt'];
    $subStmt->close();

    if ($subCount === 0) {
        throw new Exception(
            "No staff members are assigned to you for the {$cycle['year']} appraisal cycle. " .
            "Please contact an administrator.",
            400
        );
    }

    // Check if already onboarded
    $checkStmt = $conn->prepare("
        SELECT id, onboarded_at FROM supervisor_onboarding
        WHERE supervisor_id = ? AND cycle_id = ? LIMIT 1
    ");
    $checkStmt->bind_param("ii", $supervisorId, $cycle['id']);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $now = date('Y-m-d H:i:s');

    if ($existing) {
        // Already onboarded — update the timestamp (re-onboarding)
        $updateStmt = $conn->prepare("
            UPDATE supervisor_onboarding SET onboarded_at = ? WHERE id = ?
        ");
        $updateStmt->bind_param("si", $now, $existing['id']);
        $updateStmt->execute();
        $updateStmt->close();
        $message = "Re-onboarded successfully for the {$cycle['year']} appraisal cycle.";
    } else {
        // First time onboarding for this cycle
        $insertStmt = $conn->prepare("
            INSERT INTO supervisor_onboarding (cycle_id, supervisor_id, onboarded_at)
            VALUES (?, ?, ?)
        ");
        $insertStmt->bind_param("iis", $cycle['id'], $supervisorId, $now);
        $insertStmt->execute();
        $insertStmt->close();
        $message = "Onboarded successfully for the {$cycle['year']} appraisal cycle.";
    }

    // Also update onboarded_at on the users table for quick access
    $userUpdateStmt = $conn->prepare("UPDATE users SET onboarded_at = ? WHERE id = ?");
    $userUpdateStmt->bind_param("si", $now, $supervisorId);
    $userUpdateStmt->execute();
    $userUpdateStmt->close();

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "supervisor_onboard";
        $targetTable = "supervisor_onboarding";
        $fullname    = $supervisor['first_name'] . " " . $supervisor['last_name'];
        $description = "{$fullname} onboarded for the {$cycle['year']} appraisal cycle";
        $logStmt->bind_param(
            "iissis",
            $supervisor['company_id'], $loggedInUserId,
            $action, $targetTable, $supervisorId,
            $description
        );
        $logStmt->execute();
        $logStmt->close();
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => $message,
        "data"    => [
            "supervisor_id"   => $supervisorId,
            "supervisor_name" => $supervisor['first_name'] . " " . $supervisor['last_name'],
            "cycle_id"        => $cycle['id'],
            "cycle_year"      => $cycle['year'],
            "cycle_title"     => $cycle['title'],
            "onboarded_at"    => $now,
            "subordinate_count" => $subCount,
        ]
    ]);

} catch (Exception $e) {
    error_log("Onboard Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
