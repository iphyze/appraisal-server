<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';

header('Content-Type: application/json; charset=UTF-8');

function aavFetchAdmin(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare("\n        SELECT\n            u.id, u.company_id, u.first_name, u.last_name, u.fullname,\n            u.email, u.department, u.job_title, u.is_active,\n            c.name AS company_name, c.code AS company_code,\n            r.name AS role_name\n        FROM users u\n        INNER JOIN roles r ON r.id = u.role_id\n        INNER JOIN companies c ON c.id = u.company_id\n        WHERE u.id = ?\n          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'\n        LIMIT 1\n    ");

    if (!$stmt) {
        throw new Exception('Unable to load administrator.', 500);
    }

    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function aavEnsureSuperAdminCompanyContext(array $userData, int $companyId): void
{
    $companyScope = resolveCompanyScope($userData);

    if ($companyScope !== null && $companyScope !== $companyId) {
        throw new Exception('Unauthorized: This administrator is outside the selected company context.', 403);
    }
}

function aavLoadSelectedTargets(mysqli $conn, int $viewerAdminId, int $companyId): array
{
    $stmt = $conn->prepare("\n        SELECT target_admin_id\n        FROM admin_appraisal_visibility_targets\n        WHERE viewer_admin_id = ?\n          AND company_id = ?\n        ORDER BY target_admin_id ASC\n    ");

    if (!$stmt) {
        throw new Exception(
            'Admin appraisal visibility is not initialised. Run the admin appraisal visibility migration.',
            500
        );
    }

    $stmt->bind_param('ii', $viewerAdminId, $companyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];

    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['target_admin_id'];
    }

    $stmt->close();

    return $ids;
}

function aavLoadEligibleTargets(mysqli $conn, int $viewerAdminId, int $companyId): array
{
    $stmt = $conn->prepare("\n        SELECT\n            u.id, u.first_name, u.last_name, u.fullname, u.email,\n            u.department, u.job_title, u.is_active\n        FROM users u\n        INNER JOIN roles r ON r.id = u.role_id\n        WHERE u.company_id = ?\n          AND u.id <> ?\n          AND u.is_active = 1\n          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'\n        ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC\n    ");

    if (!$stmt) {
        throw new Exception('Unable to load eligible administrators.', 500);
    }

    $stmt->bind_param('ii', $companyId, $viewerAdminId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $name = trim((string) ($row['fullname'] ?? ''));
        if ($name === '') {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        }
        $row['display_name'] = $name !== '' ? $name : ($row['email'] ?? 'Admin');
    }
    unset($row);

    return $rows;
}

function aavAuditVisibilityChange(
    mysqli $conn,
    int $companyId,
    int $superAdminId,
    int $viewerAdminId,
    string $description
): void {
    $action = 'update_admin_appraisal_visibility';
    $targetTable = 'admin_appraisal_visibility_policies';

    $stmt = $conn->prepare("\n        INSERT INTO audit_log\n            (company_id, user_id, action, target_table, target_id, description)\n        VALUES (?, ?, ?, ?, ?, ?)\n    ");

    if (!$stmt) {
        throw new Exception('Unable to prepare audit log.', 500);
    }

    $stmt->bind_param(
        'iissis',
        $companyId,
        $superAdminId,
        $action,
        $targetTable,
        $viewerAdminId,
        $description
    );

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to record audit log: ' . $error, 500);
    }

    $stmt->close();
}

try {
    $userData = requireRoles(['super_admin']);
    $superAdminId = (int) ($userData['id'] ?? 0);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $adminId = (int) ($_GET['admin_id'] ?? 0);

        if ($adminId <= 0) {
            throw new Exception("Missing required parameter: 'admin_id'.", 400);
        }

        $admin = aavFetchAdmin($conn, $adminId);
        if (!$admin) {
            throw new Exception('Administrator not found.', 404);
        }

        $companyId = (int) $admin['company_id'];
        aavEnsureSuperAdminCompanyContext($userData, $companyId);

        $mode = adminAppraisalVisibilityMode($conn, $adminId, $companyId);
        $selectedIds = aavLoadSelectedTargets($conn, $adminId, $companyId);
        $eligibleTargets = aavLoadEligibleTargets($conn, $adminId, $companyId);

        $admin['id'] = (int) $admin['id'];
        $admin['company_id'] = $companyId;
        $admin['is_active'] = (int) $admin['is_active'];
        $admin['display_name'] = trim((string) ($admin['fullname'] ?? '')) ?: trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Admin appraisal visibility fetched successfully.',
            'data' => [
                'admin' => $admin,
                'visibility_mode' => $mode,
                'selected_admin_ids' => $selectedIds,
                'eligible_admins' => $eligibleTargets,
            ],
        ]);
        exit;
    }

    if (!in_array($method, ['PUT', 'PATCH'], true)) {
        throw new Exception('Bad Request: Only GET, PUT and PATCH methods are allowed.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected JSON object.', 400);
    }

    $adminId = (int) ($data['admin_id'] ?? 0);
    $mode = strtolower(trim((string) ($data['visibility_mode'] ?? '')));
    $targetIds = $data['target_admin_ids'] ?? [];

    if ($adminId <= 0) {
        throw new Exception("Field 'admin_id' is required.", 400);
    }

    if (!in_array($mode, ['none', 'all', 'selected'], true)) {
        throw new Exception("Field 'visibility_mode' must be one of: none, all, selected.", 400);
    }

    if (!is_array($targetIds)) {
        throw new Exception("Field 'target_admin_ids' must be an array.", 400);
    }

    $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds), function ($id) use ($adminId) {
        return $id > 0 && $id !== $adminId;
    })));

    if ($mode === 'selected' && empty($targetIds)) {
        throw new Exception('Select at least one administrator when visibility mode is selected.', 400);
    }

    if ($mode !== 'selected') {
        $targetIds = [];
    }

    $admin = aavFetchAdmin($conn, $adminId);
    if (!$admin) {
        throw new Exception('Administrator not found.', 404);
    }

    $companyId = (int) $admin['company_id'];
    aavEnsureSuperAdminCompanyContext($userData, $companyId);

    if (!empty($targetIds)) {
        $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
        $types = 'i' . str_repeat('i', count($targetIds));
        $params = array_merge([$companyId], $targetIds);

        $targetStmt = $conn->prepare("\n            SELECT u.id\n            FROM users u\n            INNER JOIN roles r ON r.id = u.role_id\n            WHERE u.company_id = ?\n              AND u.is_active = 1\n              AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'\n              AND u.id IN ({$placeholders})\n        ");

        if (!$targetStmt) {
            throw new Exception('Unable to validate selected administrators.', 500);
        }

        $targetStmt->bind_param($types, ...$params);
        $targetStmt->execute();
        $validRows = $targetStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $targetStmt->close();

        $validIds = array_map('intval', array_column($validRows, 'id'));
        sort($validIds);
        $requestedIds = $targetIds;
        sort($requestedIds);

        if ($validIds !== $requestedIds) {
            throw new Exception('One or more selected administrators are invalid, are not Admins, or belong to another company.', 400);
        }
    }

    $oldMode = adminAppraisalVisibilityMode($conn, $adminId, $companyId);
    $oldTargets = aavLoadSelectedTargets($conn, $adminId, $companyId);

    $conn->begin_transaction();

    try {
        $policyStmt = $conn->prepare("\n            INSERT INTO admin_appraisal_visibility_policies\n                (viewer_admin_id, company_id, visibility_mode, updated_by)\n            VALUES (?, ?, ?, ?)\n            ON DUPLICATE KEY UPDATE\n                company_id = VALUES(company_id),\n                visibility_mode = VALUES(visibility_mode),\n                updated_by = VALUES(updated_by),\n                updated_at = CURRENT_TIMESTAMP\n        ");

        if (!$policyStmt) {
            throw new Exception(
                'Admin appraisal visibility is not initialised. Run the admin appraisal visibility migration.',
                500
            );
        }

        $policyStmt->bind_param('iisi', $adminId, $companyId, $mode, $superAdminId);
        if (!$policyStmt->execute()) {
            $error = $policyStmt->error ?: $conn->error;
            $policyStmt->close();
            throw new Exception('Unable to save admin appraisal visibility: ' . $error, 500);
        }
        $policyStmt->close();

        $deleteStmt = $conn->prepare("\n            DELETE FROM admin_appraisal_visibility_targets\n            WHERE viewer_admin_id = ?\n              AND company_id = ?\n        ");
        if (!$deleteStmt) {
            throw new Exception('Unable to reset selected administrator visibility.', 500);
        }
        $deleteStmt->bind_param('ii', $adminId, $companyId);
        $deleteStmt->execute();
        $deleteStmt->close();

        if ($mode === 'selected') {
            $insertStmt = $conn->prepare("\n                INSERT INTO admin_appraisal_visibility_targets\n                    (viewer_admin_id, target_admin_id, company_id, granted_by)\n                VALUES (?, ?, ?, ?)\n            ");

            if (!$insertStmt) {
                throw new Exception('Unable to save selected administrator visibility.', 500);
            }

            foreach ($targetIds as $targetId) {
                $insertStmt->bind_param('iiii', $adminId, $targetId, $companyId, $superAdminId);
                if (!$insertStmt->execute()) {
                    $error = $insertStmt->error ?: $conn->error;
                    $insertStmt->close();
                    throw new Exception('Unable to save selected administrator visibility: ' . $error, 500);
                }
            }
            $insertStmt->close();
        }

        $adminName = trim((string) ($admin['fullname'] ?? '')) ?: trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
        $description = sprintf(
            'Changed admin appraisal visibility for %s from %s (%d selected) to %s (%d selected).',
            $adminName !== '' ? $adminName : ('Admin #' . $adminId),
            $oldMode,
            count($oldTargets),
            $mode,
            count($targetIds)
        );

        aavAuditVisibilityChange($conn, $companyId, $superAdminId, $adminId, $description);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Admin appraisal visibility updated successfully.',
        'data' => [
            'admin_id' => $adminId,
            'visibility_mode' => $mode,
            'selected_admin_ids' => $targetIds,
        ],
    ]);
    exit;
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
    exit;
}
