<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $includeInactive = isset($_GET['include_inactive']) && (int)$_GET['include_inactive'] === 1;

    // Public endpoint by default — used by the login company selector.
    // When include_inactive=1 is passed, only super_admin can see all companies.
    if ($includeInactive) {
        requireRoles(['super_admin']);
        $whereSql = '';
    } else {
        $whereSql = 'WHERE is_active = 1';
    }

    $stmt = $conn->prepare("
        SELECT id, code, name, logo_url, is_active, created_at, updated_at
        FROM companies
        {$whereSql}
        ORDER BY name ASC
    ");

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $stmt->execute();
    $result    = $stmt->get_result();
    $companies = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Companies fetched successfully",
        "data"    => $companies
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}
