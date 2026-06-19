<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Prepare and execute a statement with a dynamic integer IN-list.
 */
function executeCycleDeleteStatement(mysqli $conn, string $sql, array $ids): int
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Unable to prepare cycle cleanup query: ' . $conn->error, 500);
    }

    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);

    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to complete cycle cleanup: ' . $error, 500);
    }

    $affected = (int) $stmt->affected_rows;
    $stmt->close();

    return $affected;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
        throw new Exception('Bad Request: Only DELETE method is allowed.', 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);
    $loggedInUserId = (int) ($userData['id'] ?? 0);
    $loggedInUserEmail = trim((string) ($userData['email'] ?? 'System user'));
    $loggedInUserRole = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int) ($userData['company_id'] ?? 0);

    $data = json_decode((string) file_get_contents('php://input'), true);

    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        static fn (int $id): bool => $id > 0
    )));

    if (!$targetIds) {
        throw new Exception('No valid cycle IDs were provided.', 400);
    }

    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $types = str_repeat('i', count($targetIds));

    $checkStmt = $conn->prepare("
        SELECT
            c.id,
            c.company_id,
            c.year,
            c.title,
            c.is_active,
            COUNT(a.id) AS appraisal_count
        FROM appraisal_cycles c
        LEFT JOIN appraisals a ON a.cycle_id = c.id
        WHERE c.id IN ({$placeholders})
        GROUP BY c.id, c.company_id, c.year, c.title, c.is_active
        ORDER BY c.year DESC, c.id DESC
    ");

    if (!$checkStmt) {
        throw new Exception('Unable to validate appraisal cycles: ' . $conn->error, 500);
    }

    $checkStmt->bind_param($types, ...$targetIds);

    if (!$checkStmt->execute()) {
        $error = $checkStmt->error ?: $conn->error;
        $checkStmt->close();
        throw new Exception('Unable to validate appraisal cycles: ' . $error, 500);
    }

    $foundCycles = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (!$foundCycles) {
        throw new Exception('No matching appraisal cycles were found.', 404);
    }

    $foundIds = array_map('intval', array_column($foundCycles, 'id'));
    $missingIds = array_values(array_diff($targetIds, $foundIds));

    if ($missingIds) {
        throw new Exception('One or more selected appraisal cycles no longer exist.', 404);
    }

    $blocked = [];

    foreach ($foundCycles as $cycle) {
        $cycleId = (int) $cycle['id'];
        $cycleCompanyId = (int) $cycle['company_id'];
        $label = trim((string) $cycle['title']) . ' (' . (int) $cycle['year'] . ')';

        if ($loggedInUserRole !== 'super_admin' && $cycleCompanyId !== $loggedInCompanyId) {
            throw new Exception("You do not have permission to delete {$label}.", 403);
        }

        if ((int) $cycle['is_active'] === 1) {
            $blocked[] = [
                'id' => $cycleId,
                'cycle' => $label,
                'reason' => 'The cycle is active. Deactivate it before deleting.',
            ];
            continue;
        }

        $appraisalCount = (int) ($cycle['appraisal_count'] ?? 0);

        if ($appraisalCount > 0) {
            $blocked[] = [
                'id' => $cycleId,
                'cycle' => $label,
                'reason' => "{$appraisalCount} appraisal" . ($appraisalCount === 1 ? ' has' : 's have') . ' already been recorded.',
            ];
        }
    }

    if ($blocked) {
        $first = $blocked[0];
        throw new Exception(
            "Cannot delete {$first['cycle']}. {$first['reason']}",
            409
        );
    }

    $validIds = $foundIds;
    $deletePlaceholders = implode(',', array_fill(0, count($validIds), '?'));

    $conn->begin_transaction();

    try {
        $cleanup = [];

        // Remove cycle-specific employee KPI assignments before their questions/sections.
        $cleanup['staff_kpi_assignments'] = executeCycleDeleteStatement(
            $conn,
            "DELETE ska
             FROM staff_kpi_assignments ska
             INNER JOIN appraisal_sections s ON s.id = ska.section_id
             WHERE s.cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        // Remove KPI questions because their section FK does not cascade.
        $cleanup['kpi_questions'] = executeCycleDeleteStatement(
            $conn,
            "DELETE kq
             FROM kpi_questions kq
             INNER JOIN appraisal_sections s ON s.id = kq.section_id
             WHERE s.cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        // General questions are also removed explicitly for predictable cleanup.
        $cleanup['general_questions'] = executeCycleDeleteStatement(
            $conn,
            "DELETE gq
             FROM general_questions gq
             INNER JOIN appraisal_sections s ON s.id = gq.section_id
             WHERE s.cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        $cleanup['supervisor_assignments'] = executeCycleDeleteStatement(
            $conn,
            "DELETE FROM supervisor_assignments WHERE cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        $cleanup['supervisor_onboarding'] = executeCycleDeleteStatement(
            $conn,
            "DELETE FROM supervisor_onboarding WHERE cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        $cleanup['appraisal_sections'] = executeCycleDeleteStatement(
            $conn,
            "DELETE FROM appraisal_sections WHERE cycle_id IN ({$deletePlaceholders})",
            $validIds
        );

        $deletedCount = executeCycleDeleteStatement(
            $conn,
            "DELETE FROM appraisal_cycles WHERE id IN ({$deletePlaceholders})",
            $validIds
        );

        if ($deletedCount !== count($validIds)) {
            throw new Exception('Not all selected appraisal cycles could be deleted.', 500);
        }

        $logStmt = $conn->prepare("
            INSERT INTO audit_log
                (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$logStmt) {
            throw new Exception('Unable to prepare the cycle deletion audit log.', 500);
        }

        foreach ($foundCycles as $cycle) {
            $auditCompanyId = (int) $cycle['company_id'];
            $action = 'delete_cycle';
            $targetTable = 'appraisal_cycles';
            $targetId = (int) $cycle['id'];
            $description = sprintf(
                '%s deleted inactive appraisal cycle %s (%d) before any appraisal was recorded.',
                $loggedInUserEmail !== '' ? $loggedInUserEmail : 'System user',
                (string) $cycle['title'],
                (int) $cycle['year']
            );

            $logStmt->bind_param(
                'iissis',
                $auditCompanyId,
                $loggedInUserId,
                $action,
                $targetTable,
                $targetId,
                $description
            );

            if (!$logStmt->execute()) {
                $error = $logStmt->error ?: $conn->error;
                $logStmt->close();
                throw new Exception('Unable to record the cycle deletion audit entry: ' . $error, 500);
            }
        }

        $logStmt->close();
        $conn->commit();

        echo json_encode([
            'status' => 'Success',
            'message' => $deletedCount === 1
                ? 'Appraisal cycle deleted successfully.'
                : "{$deletedCount} appraisal cycles deleted successfully.",
            'data' => [
                'deleted_count' => $deletedCount,
                'deleted_ids' => $validIds,
                'cleanup' => $cleanup,
            ],
        ]);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
} catch (Throwable $exception) {
    $status = (int) $exception->getCode();
    $status = $status >= 400 && $status <= 599 ? $status : 500;

    error_log('DeleteCycles Error: ' . $exception->getMessage());
    http_response_code($status);

    echo json_encode([
        'status' => 'Failed',
        'message' => $exception->getMessage(),
    ]);
}
