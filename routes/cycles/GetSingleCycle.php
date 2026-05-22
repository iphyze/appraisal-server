<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    // All authenticated roles can view a cycle
    $userData = authenticateUser();

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];
    if ($id <= 0) {
        throw new Exception("Invalid cycle ID.", 400);
    }

    $stmt = $conn->prepare("
        SELECT
            ac.id,
            ac.year,
            ac.title,
            ac.start_date,
            ac.end_date,
            ac.is_active,
            ac.created_at,
            ac.updated_at,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $cycle = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$cycle) {
        throw new Exception("Appraisal cycle not found.", 404);
    }

    // Apply the active portal-company context for both scoped users and Super Admin.
    $companyScope = resolveCompanyScope($userData);
    if ($companyScope !== null && (int) $cycle['company_id'] !== (int) $companyScope) {
        throw new Exception("Unauthorized: This appraisal cycle is outside the selected company scope.", 403);
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal cycle fetched successfully",
        "data"    => $cycle
    ]);

} catch (Exception $e) {
    error_log("GetSingleCycle Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
