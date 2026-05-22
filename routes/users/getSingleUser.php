<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    // Auth — super_admin, admin only
    $userData = requireRoles(['super_admin', 'admin']);

    if (!isset($_GET['id']) || trim($_GET['id']) === '') {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) trim($_GET['id']);

    if ($id <= 0) {
        throw new Exception("Invalid user id.", 400);
    }

    /**
     * Fetch single user data
     */
    $stmt = $conn->prepare("
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
            u.created_at,
            r.id AS role_id,
            r.name AS role,
            c.id AS company_id,
            c.code AS company_code,
            c.name AS company_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE u.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Failed to prepare query: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    if (!$user) {
        throw new Exception("User record not found", 404);
    }

    // Respect company context for both admins and a super administrator who selected a company.
    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $user['company_id'] !== (int) $companyScope) {
        throw new Exception("Unauthorized: You can only view users within the selected company context", 403);
    }

    if (authRoleKey($userData['role'] ?? '') === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        $targetRoleKey = authRoleKey($user['role'] ?? '');

        // Staff scope limits ordinary staff records only. Administrators and appraisers
        // must still be visible to other administrators in the same company.
        if (
            $adminScope !== 'All' &&
            $targetRoleKey === 'staff' &&
            $user['staff_type'] !== null &&
            $user['staff_type'] !== $adminScope
        ) {
            throw new Exception("Unauthorized: You do not have permission to view this user", 403);
        }
    }

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "User profile fetched successfully",
        "data" => $user
    ]);
} catch (Exception $e) {

    error_log("FetchSingleUser Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
