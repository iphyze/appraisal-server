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

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $isActive = isset($_GET['is_active']) ? (int) $_GET['is_active'] : null;

    $conditions = ["1=1"];
    $params = [];
    $types = "";

    if ($isActive !== null) {
        $conditions[] = "is_active = ?";
        $params[] = $isActive;
        $types .= "i";
    }

    if (!empty($search)) {
        $conditions[] = "(code LIKE ? OR name LIKE ?)";
        $like = "%" . $search . "%";
        $params[] = $like;
        $params[] = $like;
        $types .= "ss";
    }

    $sql = "
        SELECT 
            id,
            code,
            name,
            logo_url,
            is_active,
            created_at,
            updated_at
        FROM companies
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY name ASC
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();

    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    http_response_code(200);

    echo json_encode([
        "status" => "Success",
        "message" => "Companies fetched successfully",
        "data" => $data,
        "meta" => [
            "count" => count($data),
            "search" => $search ?: null,
            "is_active" => $isActive
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchCompanies Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}