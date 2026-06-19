<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception("Invalid request format. Expected JSON object.", 400);
    if (!isset($data['id']) || !is_numeric($data['id'])) throw new Exception("Field 'id' is required.", 400);

    $departmentId = (int) $data['id'];
    if ($departmentId <= 0) throw new Exception("Invalid department ID.", 400);

    $checkStmt = $conn->prepare("SELECT id, company_id, name, is_active FROM departments WHERE id = ? LIMIT 1");
    $checkStmt->bind_param('i', $departmentId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) throw new Exception("Department not found.", 404);
    if ($loggedInUserRole !== 'super_admin' && (int) $existing['company_id'] !== $loggedInCompanyId) {
        throw new Exception("Unauthorized: You can only update departments within your company.", 403);
    }

    $newName = null;
    $newActive = null;

    if (isset($data['name']) && trim($data['name']) !== '') {
        $newName = preg_replace('/\s+/u', ' ', trim((string) $data['name']));
        if (mb_strlen($newName) > 150) {
            throw new Exception("Department name must not exceed 150 characters.", 400);
        }

        $dupStmt = $conn->prepare("SELECT id FROM departments WHERE company_id = ? AND LOWER(name) = LOWER(?) AND id != ? LIMIT 1");
        $dupStmt->bind_param('isi', $existing['company_id'], $newName, $departmentId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Department '{$newName}' already exists for this company.", 400);
        }
        $dupStmt->close();
    }

    if (isset($data['is_active'])) {
        $newActive = (int) $data['is_active'];
        if (!in_array($newActive, [0, 1], true)) {
            throw new Exception("Invalid is_active value. Use 1 or 0.", 400);
        }
    }

    if ($newName === null && $newActive === null) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    $conn->begin_transaction();
    try {
        $fields = [];
        $params = [];
        $types = '';

        if ($newName !== null) {
            $fields[] = 'name = ?';
            $params[] = $newName;
            $types .= 's';
        }
        if ($newActive !== null) {
            $fields[] = 'is_active = ?';
            $params[] = $newActive;
            $types .= 'i';
        }

        $sql = "UPDATE departments SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $departmentId;
        $types .= 'i';

        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);
        $updateStmt->bind_param($types, ...$params);
        if (!$updateStmt->execute()) throw new Exception("Update failed: " . $updateStmt->error, 500);
        $updateStmt->close();

        if ($newName !== null && $newName !== $existing['name']) {
            $userUpdate = $conn->prepare("UPDATE users SET department = ? WHERE company_id = ? AND department = ?");
            if ($userUpdate) {
                $userUpdate->bind_param('sis', $newName, $existing['company_id'], $existing['name']);
                $userUpdate->execute();
                $userUpdate->close();
            }

            $questionUpdate = $conn->prepare("UPDATE kpi_questions SET department = ? WHERE company_id = ? AND department = ?");
            if ($questionUpdate) {
                $questionUpdate->bind_param('sis', $newName, $existing['company_id'], $existing['name']);
                $questionUpdate->execute();
                $questionUpdate->close();
            }
        }

        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $action = 'update_department';
            $targetTable = 'departments';
            $description = "{$loggedInUserEmail} updated department ID: {$departmentId}";
            $logStmt->bind_param('iissis', $existing['company_id'], $loggedInUserId, $action, $targetTable, $departmentId, $description);
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    $fetchStmt = $conn->prepare("
        SELECT d.id, d.company_id, d.name, d.is_active, d.created_at, d.updated_at,
               c.code AS company_code, c.name AS company_name
        FROM departments d
        INNER JOIN companies c ON c.id = d.company_id
        WHERE d.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param('i', $departmentId);
    $fetchStmt->execute();
    $department = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Department updated successfully',
        'data' => $department,
    ]);

} catch (Exception $e) {
    error_log("UpdateDepartment Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
