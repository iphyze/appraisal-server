<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    // Must be authenticated
    $userData            = authenticateUser();
    $loggedInUserId      = (int) $userData['id'];
    $loggedInUserEmail   = $userData['email'];
    $loggedInUserRole    = $userData['role'];
    $loggedInCompanyId   = (int) $userData['company_id'];

    // Only super_admin or admin may create new users
    if (!in_array($loggedInUserRole, ['super_admin', 'admin'])) {
        throw new Exception("Unauthorized: Only admins can create user accounts", 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // Required fields
    $requiredFields = ['first_name', 'last_name', 'password', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    // Clean inputs
    $firstName  = trim($data['first_name']);
    $lastName   = trim($data['last_name']);
    $email      = isset($data['email']) && trim((string) $data['email']) !== '' ? strtolower(trim((string) $data['email'])) : null;
    $password   = trim($data['password']);
    $roleName   = trim($data['role']);

    // Optional fields
    $username    = isset($data['username'])    ? trim($data['username'])    : null;
    $staffScope  = isset($data['staff_scope']) ? trim($data['staff_scope']): null;
    $department  = isset($data['department'])  ? trim($data['department'])  : null;
    $jobTitle    = isset($data['job_title'])   ? trim($data['job_title'])   : null;
    $staffType   = isset($data['staff_type'])  ? trim($data['staff_type'])  : null;
    $location    = isset($data['location'])    ? trim($data['location'])    : null;
    $staffId     = isset($data['staff_id'])    ? trim($data['staff_id'])    : null;
    $uniqueRef   = isset($data['unique_ref'])  ? trim($data['unique_ref'])  : null;
    $dateOfJoining = isset($data['date_of_joining']) ? trim($data['date_of_joining']) : null;

    /**
     * company_id:
     *   - super_admin may pass any company_id in the body
     *   - admin is always locked to their own company
     */
    if ($loggedInUserRole === 'super_admin' && isset($data['company_id'])) {
        $companyId = (int) $data['company_id'];
    } else {
        $companyId = $loggedInCompanyId;
    }

    // Validate
    if ($email !== null && !v::email()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }
    if (!v::stringType()->length(6, null)->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }

    // Validate role
    $allowedRoles = ['admin', 'supervisor', 'staff'];
    // super_admin can also create another super_admin
    if ($loggedInUserRole === 'super_admin') {
        $allowedRoles[] = 'super_admin';
    }
    if (!in_array($roleName, $allowedRoles)) {
        throw new Exception("Invalid role. Allowed: " . implode(', ', $allowedRoles), 400);
    }

    // Validate staff_scope when role is admin
    if ($roleName === 'admin') {
        $allowedScopes = ['All', 'Local', 'Expatriate'];
        if (!$staffScope || !in_array($staffScope, $allowedScopes)) {
            throw new Exception("Field 'staff_scope' is required for admin role. Allowed: All, Local, Expatriate", 400);
        }
    } else {
        $staffScope = null; // not applicable for non-admin roles
    }

    // Every non-super-admin account may be appraised, therefore staff type is
    // required for reporting and administrative Local/Expatriate scope.
    if ($roleName !== 'super_admin' && !$staffType) {
        throw new Exception("Field 'staff_type' is required for appraisal-eligible users.", 400);
    }
    if ($staffType && !in_array($staffType, ['Local', 'Expatriate'], true)) {
        throw new Exception("Invalid staff_type. Allowed: Local, Expatriate", 400);
    }

    // Validate company exists
    $companyStmt = $conn->prepare("SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1");
    $companyStmt->bind_param("i", $companyId);
    $companyStmt->execute();
    if ($companyStmt->get_result()->num_rows === 0) {
        throw new Exception("Company not found or inactive", 404);
    }
    $companyStmt->close();

    // Resolve role_id
    $roleStmt = $conn->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
    $roleStmt->bind_param("s", $roleName);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();

    if (!$roleRow) {
        throw new Exception("Role '{$roleName}' not found in system", 500);
    }
    $roleId = (int) $roleRow['id'];

    // Duplicate checking applies only when a real email address exists.
    // Missing addresses remain NULL and will not trigger outbound email delivery.
    if ($email !== null) {
        $dupStmt = $conn->prepare("
            SELECT id FROM users
            WHERE email = ? AND company_id = ?
            LIMIT 1
        ");
        if (!$dupStmt) throw new Exception("Database error: " . $conn->error, 500);
        $dupStmt->bind_param("si", $email, $companyId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("A user with this email already exists in this company", 400);
        }
        $dupStmt->close();
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $mustChangePassword = $roleName !== 'super_admin' ? 1 : 0;

    /**
     * Insert user
     */
    $insertStmt = $conn->prepare("
        INSERT INTO users (
            company_id, role_id, staff_id, first_name, last_name,
            username, email, password_hash, must_change_password,
            staff_scope, department, job_title, staff_type,
            location, unique_ref, date_of_joining,
            created_by, updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$insertStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $insertStmt->bind_param(
        "iissssssisssssssii",
        $companyId,
        $roleId,
        $staffId,
        $firstName,
        $lastName,
        $username,
        $email,
        $hashedPassword,
        $mustChangePassword,
        $staffScope,
        $department,
        $jobTitle,
        $staffType,
        $location,
        $uniqueRef,
        $dateOfJoining,
        $loggedInUserId,
        $loggedInUserId
    );

    if (!$insertStmt->execute()) {
        throw new Exception("Failed to create user: " . $insertStmt->error, 500);
    }

    $newUserId = $insertStmt->insert_id;
    $insertStmt->close();

    // Log action
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt) {
        $action      = "create_user";
        $createdFor = $email ?: trim($firstName . ' ' . $lastName);
        $description = "{$loggedInUserEmail} created a new {$roleName} account for {$createdFor}";
        $targetTable = "users";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $newUserId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "User account created successfully",
        "data"    => [
            "id"             => $newUserId,
            "first_name"     => $firstName,
            "last_name"      => $lastName,
            "email"          => $email,
            "username"       => $username,
            "role"           => $roleName,
            "staff_scope"    => $staffScope,
            "department"     => $department,
            "job_title"      => $jobTitle,
            "staff_type"     => $staffType,
            "must_change_password" => $mustChangePassword,
            "company_id"     => $companyId,
            "created_by"     => $loggedInUserId,
        ]
    ]);

} catch (Exception $e) {
    error_log("Register Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}