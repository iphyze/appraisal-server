<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);
    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    $targetIds = array_values(array_unique(array_filter(array_map('intval', $data['ids']), fn($id) => $id > 0)));
    if (empty($targetIds)) throw new Exception("No valid department IDs provided.", 400);

    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $stmt = $conn->prepare("SELECT id, company_id, name FROM departments WHERE id IN ({$placeholders})");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    $stmt->bind_param(str_repeat('i', count($targetIds)), ...$targetIds);
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($departments) === 0) throw new Exception("No matching departments found.", 404);

    foreach ($departments as $department) {
        if ($loggedInUserRole !== 'super_admin' && (int) $department['company_id'] !== $loggedInCompanyId) {
            throw new Exception("Unauthorized: Department ID {$department['id']} does not belong to your company.", 403);
        }

        $usageStmt = $conn->prepare("
            SELECT
                (SELECT COUNT(*) FROM users u WHERE u.company_id = ? AND u.department = ?) AS staff_count,
                (SELECT COUNT(*) FROM kpi_questions kq WHERE kq.company_id = ? AND kq.department = ?) AS question_count
        ");
        if (!$usageStmt) throw new Exception("Database error: " . $conn->error, 500);
        $usageStmt->bind_param('isis', $department['company_id'], $department['name'], $department['company_id'], $department['name']);
        $usageStmt->execute();
        $usage = $usageStmt->get_result()->fetch_assoc();
        $usageStmt->close();

        $staffCount = (int) $usage['staff_count'];
        $questionCount = (int) $usage['question_count'];

        if ($staffCount > 0 || $questionCount > 0) {
            throw new Exception(
                "Cannot delete department '{$department['name']}' because it is linked to {$staffCount} staff record(s) and {$questionCount} KPI question(s). Deactivate it instead.",
                400
            );
        }
    }

    $validIds = array_column($departments, 'id');
    $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));

    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare("DELETE FROM departments WHERE id IN ({$deletePlaceholders})");
        if (!$deleteStmt) throw new Exception("Database error: " . $conn->error, 500);
        $deleteStmt->bind_param(str_repeat('i', count($validIds)), ...$validIds);
        if (!$deleteStmt->execute()) throw new Exception("Delete failed: " . $deleteStmt->error, 500);
        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            foreach ($departments as $department) {
                $action = 'delete_department';
                $targetTable = 'departments';
                $targetId = (int) $department['id'];
                $description = "{$loggedInUserEmail} deleted department: {$department['name']}";
                $logStmt->bind_param('iissis', $department['company_id'], $loggedInUserId, $action, $targetTable, $targetId, $description);
                $logStmt->execute();
            }
            $logStmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => "{$deletedCount} department(s) deleted successfully.",
        'data' => ['deleted_count' => $deletedCount, 'deleted_ids' => $validIds],
    ]);

} catch (Exception $e) {
    error_log("DeleteDepartments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
