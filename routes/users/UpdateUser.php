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

    // Auth — super_admin, admin only
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInRoleKey   = $userData['role_key'] ?? authRoleKey($loggedInUserRole);
    $loggedInCompanyId = (int) $userData['company_id'];

    // ── Parse body ────────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $targetId = (int) $data['id'];
    if ($targetId <= 0) {
        throw new Exception("Invalid user ID.", 400);
    }

    // ── Fetch existing user ───────────────────────────────────────────────────
    $checkStmt = $conn->prepare("
        SELECT u.id, u.email, u.staff_type, u.role_id, u.company_id,
               r.name AS role
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
    ");
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }
    $checkStmt->bind_param("i", $targetId);
    $checkStmt->execute();
    $existingUser = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existingUser) {
        throw new Exception("User with ID {$targetId} not found.", 404);
    }

    // ── Authorization checks ──────────────────────────────────────────────────

    // Admin can only update users within their own company
    if ($loggedInRoleKey !== 'super_admin' &&
        (int) $existingUser['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: You can only update users within your company.", 403);
    }

    $targetRoleKey = authRoleKey($existingUser['role'] ?? '');

    // Staff scope limits ordinary staff records only. This does not expand
    // administrator mutation permissions; the explicit role check below remains.
    if ($loggedInRoleKey === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if (
            $adminScope !== 'All' &&
            $targetRoleKey === 'staff' &&
            $existingUser['staff_type'] !== null &&
            $existingUser['staff_type'] !== $adminScope
        ) {
            throw new Exception("Unauthorized: You do not have permission to update this user.", 403);
        }
    }

    // Admin cannot update another admin or super_admin
    if (
        $loggedInRoleKey === 'admin' &&
        in_array($targetRoleKey, ['admin', 'super_admin'], true)
    ) {
        throw new Exception("Unauthorized: You cannot update an admin account.", 403);
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

    // email is optional. Store absent email as NULL; notification and mail
    // queries already exclude records without a deliverable address.
    if (array_key_exists('email', $data)) {
        $email = trim((string) ($data['email'] ?? '')) !== ''
            ? strtolower(trim((string) $data['email']))
            : null;

        if ($email !== null && !v::email()->validate($email)) {
            throw new Exception("Invalid email format.", 400);
        }

        if ($email !== null) {
            $dupStmt = $conn->prepare("
                SELECT id FROM users
                WHERE email = ? AND company_id = ? AND id != ?
                LIMIT 1
            ");
            if (!$dupStmt) throw new Exception("Database error: " . $conn->error, 500);
            $dupStmt->bind_param("sii", $email, $existingUser['company_id'], $targetId);
            $dupStmt->execute();
            if ($dupStmt->get_result()->num_rows > 0) {
                throw new Exception("Email already in use by another user in this company.", 400);
            }
            $dupStmt->close();
        }

        $updateFields[] = "email = ?";
        $params[]       = $email;
        $types         .= "s";
    }

    // department
    if (isset($data['department'])) {
        $updateFields[] = "department = ?";
        $params[]       = trim($data['department']) ?: null;
        $types         .= "s";
    }

    // job_title
    if (isset($data['job_title'])) {
        $updateFields[] = "job_title = ?";
        $params[]       = trim($data['job_title']) ?: null;
        $types         .= "s";
    }

    // location
    if (isset($data['location'])) {
        $updateFields[] = "location = ?";
        $params[]       = trim($data['location']) ?: null;
        $types         .= "s";
    }

    // staff_id
    if (isset($data['staff_id'])) {
        $updateFields[] = "staff_id = ?";
        $params[]       = trim($data['staff_id']) ?: null;
        $types         .= "s";
    }

    // unique_ref
    if (isset($data['unique_ref'])) {
        $updateFields[] = "unique_ref = ?";
        $params[]       = trim($data['unique_ref']) ?: null;
        $types         .= "s";
    }

    // date_of_joining
    if (isset($data['date_of_joining'])) {
        $updateFields[] = "date_of_joining = ?";
        $params[]       = trim($data['date_of_joining']) ?: null;
        $types         .= "s";
    }

    // staff_type — super admins and admins may update eligible users.
    // Company, staff-scope and protected-role checks have already been applied
    // above. Ignore an unchanged value so ordinary profile edits do not trigger
    // a permission branch merely because the frontend submitted the full form.
    if (array_key_exists('staff_type', $data)) {
        $requestedStaffType = trim((string) ($data['staff_type'] ?? ''));
        $allowedTypes = ['Local', 'Expatriate'];

        if (!in_array($requestedStaffType, $allowedTypes, true)) {
            throw new Exception("Invalid staff_type. Allowed: Local, Expatriate.", 400);
        }

        $currentStaffType = trim((string) ($existingUser['staff_type'] ?? ''));
        if ($requestedStaffType !== $currentStaffType) {
            $updateFields[] = "staff_type = ?";
            $params[]       = $requestedStaffType;
            $types         .= "s";
        }
    }

    // staff_scope — only super_admin can change an admin's scope
    if (isset($data['staff_scope'])) {
        if ($loggedInRoleKey !== 'super_admin') {
            throw new Exception("Unauthorized: Only super admins can change staff scope.", 403);
        }
        $allowedScopes = ['All', 'Local', 'Expatriate'];
        if (!in_array($data['staff_scope'], $allowedScopes)) {
            throw new Exception("Invalid staff_scope. Allowed: All, Local, Expatriate.", 400);
        }
        $updateFields[] = "staff_scope = ?";
        $params[]       = $data['staff_scope'];
        $types         .= "s";
    }

    // role permissions:
    // - super_admin may assign any system role;
    // - admin may switch eligible company users between supervisor and staff;
    // - admin/admin-level accounts remain protected by the target-role check above.
    if (isset($data['role'])) {
        $requestedRole   = authRoleKey($data['role']);

        if ($loggedInRoleKey === 'super_admin') {
            $allowedRoles = ['super_admin', 'admin', 'supervisor', 'staff'];
        } else {
            $allowedRoles = ['supervisor', 'staff'];
        }

        if (!in_array($requestedRole, $allowedRoles, true)) {
            $message = $loggedInRoleKey === 'admin'
                ? "Unauthorized: Admins can only assign Supervisor or Staff roles."
                : "Invalid role. Allowed: " . implode(', ', $allowedRoles);
            throw new Exception($message, $loggedInRoleKey === 'admin' ? 403 : 400);
        }

        $roleStmt = $conn->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
        if (!$roleStmt) {
            throw new Exception("Database error: " . $conn->error, 500);
        }
        $roleStmt->bind_param("s", $requestedRole);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        if (!$roleRow) {
            throw new Exception("Role not found in system.", 500);
        }

        $updateFields[] = "role_id = ?";
        $params[]       = (int) $roleRow['id'];
        $types         .= "i";

        // Non-admin roles must not retain a stale administrator scope value.
        if (!in_array($requestedRole, ['admin', 'super_admin'], true)) {
            $updateFields[] = "staff_scope = NULL";
        }
    }

    // is_active — activate or deactivate
    if (isset($data['is_active'])) {
        if (!in_array($loggedInRoleKey, ['super_admin', 'admin'], true)) {
            throw new Exception("Unauthorized: You cannot change account status.", 403);
        }
        // Prevent deactivating yourself
        if ($targetId === $loggedInUserId && (int) $data['is_active'] === 0) {
            throw new Exception("You cannot deactivate your own account.", 400);
        }
        $updateFields[] = "is_active = ?";
        $params[]       = (int) $data['is_active'];
        $types         .= "i";
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
    $params[] = $targetId;
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

    // ── Log action ────────────────────────────────────────────────────────────
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "update_user";
        $targetTable = "users";
        $description = "{$loggedInUserEmail} updated user account (ID: {$targetId})";
        $logStmt->bind_param(
            "iissis",
            $loggedInCompanyId, $loggedInUserId,
            $action, $targetTable, $targetId,
            $description
        );
        $logStmt->execute();
        $logStmt->close();
    }

    // ── Return updated record ─────────────────────────────────────────────────
    $fetchStmt = $conn->prepare("
        SELECT
            u.id, u.staff_id, u.first_name, u.last_name, u.email,
            u.username, u.department, u.job_title, u.staff_type,
            u.staff_scope, u.location, u.unique_ref, u.date_of_joining,
            u.is_active, u.updated_at,
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
    $fetchStmt->bind_param("i", $targetId);
    $fetchStmt->execute();
    $updatedUser = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "User updated successfully",
        "data"    => $updatedUser
    ]);

} catch (Exception $e) {
    error_log("UpdateUser Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}