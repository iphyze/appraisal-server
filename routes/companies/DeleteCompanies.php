<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    $userData = requireRoles(['super_admin']);
    $loggedInUserId = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];

    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        fn($id) => $id > 0
    )));

    if (empty($targetIds)) {
        throw new Exception("No valid company IDs provided.", 400);
    }

    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $types = str_repeat('i', count($targetIds));

    $checkStmt = $conn->prepare("
        SELECT id, code, name, is_active
        FROM companies
        WHERE id IN ({$placeholders})
    ");

    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    $checkStmt->bind_param($types, ...$targetIds);
    $checkStmt->execute();
    $foundCompanies = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (count($foundCompanies) === 0) {
        throw new Exception("No matching companies found.", 404);
    }

    foreach ($foundCompanies as $company) {
        if ((int) $company['is_active'] === 1) {
            throw new Exception(
                "Cannot delete an active company (ID: {$company['id']}, Code: {$company['code']}). Please deactivate it first.",
                400
            );
        }
    }

    $validIds = array_column($foundCompanies, 'id');

    $conn->begin_transaction();

    try {
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $delTypes = str_repeat('i', count($validIds));

        $deleteStmt = $conn->prepare("
            DELETE FROM companies 
            WHERE id IN ({$delPlaceholders})
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

        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if ($logStmt) {
            foreach ($foundCompanies as $company) {
                $companyId = (int) $company['id'];
                $action = "delete_company";
                $targetTable = "companies";
                $description = "{$loggedInUserEmail} deleted company: {$company['name']} ({$company['code']})";

                $logStmt->bind_param(
                    "iissis",
                    $companyId,
                    $loggedInUserId,
                    $action,
                    $targetTable,
                    $companyId,
                    $description
                );

                $logStmt->execute();
            }

            $logStmt->close();
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status" => "Success",
            "message" => "{$deletedCount} companie(s) deleted successfully.",
            "data" => [
                "deleted_count" => $deletedCount,
                "deleted_ids" => $validIds
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("DeleteCompanies Error: " . $e->getMessage());

    http_response_code($e->getCode() ?: 500);

    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}