<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    requireRoles(['super_admin']);

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $id = (int) $_GET['id'];

    if ($id <= 0) {
        throw new Exception("Invalid company ID.", 400);
    }

    $stmt = $conn->prepare("
        SELECT 
            id,
            code,
            name,
            logo_url,
            is_active,
            created_at,
            updated_at
        FROM companies
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $company = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$company) {
        throw new Exception("Company not found.", 404);
    }

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Company fetched successfully",
        "data" => $company
    ]);

} catch (Exception $e) {
    error_log("GetSingleCompany Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}