<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    // Any authenticated user can update their own profile
    $userData          = authenticateUser();
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    // ── Parse body ────────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Build dynamic update ──────────────────────────────────────────────────
    $updateFields = [];
    $params       = [];
    $types        = "";

    // first_name
    if (isset($data['first_name']) && trim($data['first_name']) !== '') {
        $updateFields[] = "first_name = ?";
        $params[]       = trim($data['first_name']);
        $types         .= "s";
    }

    // last_name
    if (isset($data['last_name']) && trim($data['last_name']) !== '') {
        $updateFields[] = "last_name = ?";
        $params[]       = trim($data['last_name']);
        $types         .= "s";
    }

    // username
    if (isset($data['username']) && trim($data['username']) !== '') {
        $updateFields[] = "username = ?";
        $params[]       = trim($data['username']);
        $types         .= "s";
    }


    // ── Password change ───────────────────────────────────────────────────────
    // Requires current_password + new_password + confirm_password
    if (isset($data['new_password'])) {
        if (!isset($data['current_password']) || trim($data['current_password']) === '') {
            throw new Exception("Current password is required to set a new password.", 400);
        }
        if (!isset($data['confirm_password']) || trim($data['confirm_password']) === '') {
            throw new Exception("Please confirm your new password.", 400);
        }
        if (trim($data['new_password']) !== trim($data['confirm_password'])) {
            throw new Exception("New password and confirmation do not match.", 400);
        }
        if (!v::stringType()->length(6, null)->validate($data['new_password'])) {
            throw new Exception("New password must be at least 6 characters long.", 400);
        }

        // Verify current password against DB
        $pwStmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $pwStmt->bind_param("i", $loggedInUserId);
        $pwStmt->execute();
        $pwRow = $pwStmt->get_result()->fetch_assoc();
        $pwStmt->close();

        if (!$pwRow || !password_verify(trim($data['current_password']), $pwRow['password_hash'])) {
            throw new Exception("Current password is incorrect.", 401);
        }

        $updateFields[] = "password_hash = ?";
        $params[]       = password_hash(trim($data['new_password']), PASSWORD_DEFAULT);
        $types         .= "s";
        $updateFields[] = "must_change_password = 0";
        $updateFields[] = "password_changed_at = NOW()";
    }

    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    // Always stamp updated_by
    $updateFields[] = "updated_by = ?";
    $params[]       = $loggedInUserId;
    $types         .= "i";

    // ── Execute update ────────────────────────────────────────────────────────
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $params[] = $loggedInUserId;
    $types   .= "i";

    $updateStmt = $conn->prepare($sql);
    if (!$updateStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $updateStmt->bind_param($types, ...$params);
    if (!$updateStmt->execute()) {
        throw new Exception("Profile update failed: " . $updateStmt->error, 500);
    }
    $updateStmt->close();

    // ── Log action ────────────────────────────────────────────────────────────
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "update_profile";
        $targetTable = "users";
        $description = "{$loggedInUserEmail} updated their own profile";

        // Flag if password was changed so it's visible in audit trail
        if (isset($data['new_password'])) {
            $description .= " (password changed)";
        }

        $logStmt->bind_param(
            "iissis",
            $loggedInCompanyId, $loggedInUserId,
            $action, $targetTable, $loggedInUserId,
            $description
        );
        $logStmt->execute();
        $logStmt->close();
    }

    // ── Return updated profile ────────────────────────────────────────────────
    $fetchStmt = $conn->prepare("
        SELECT
            u.id,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            u.department,
            u.job_title,
            u.staff_type,
            u.staff_scope,
            u.location,
            u.unique_ref,
            u.date_of_joining,
            u.is_active,
            u.last_login_at,
            u.must_change_password,
            u.password_changed_at,
            u.updated_at,
            r.name  AS role,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $fetchStmt->bind_param("i", $loggedInUserId);
    $fetchStmt->execute();
    $updatedProfile = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Profile updated successfully",
        "data"    => $updatedProfile
    ]);

} catch (Exception $e) {
    error_log("UpdateProfile Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}