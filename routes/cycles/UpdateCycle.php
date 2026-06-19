<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Bad Request: Only PUT method is allowed.', 405);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) ($userData['id'] ?? 0);
    $loggedInUserEmail = (string) ($userData['email'] ?? '');
    $loggedInUserRole  = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int) ($userData['company_id'] ?? 0);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected JSON object.', 400);
    }

    $cycleId = isset($data['id']) ? (int) $data['id'] : 0;
    if ($cycleId <= 0) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $checkStmt = $conn->prepare('
        SELECT id, company_id, year, title, start_date, end_date, is_active
        FROM appraisal_cycles
        WHERE id = ?
        LIMIT 1
    ');
    if (!$checkStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $checkStmt->bind_param('i', $cycleId);
    $checkStmt->execute();
    $existingCycle = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existingCycle) {
        throw new Exception('Appraisal cycle not found.', 404);
    }

    $targetCompanyId = (int) $existingCycle['company_id'];

    if ($loggedInUserRole !== 'super_admin' && $targetCompanyId !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: You can only update cycles within your company.', 403);
    }

    $finalTitle = array_key_exists('title', $data)
        ? trim((string) $data['title'])
        : trim((string) $existingCycle['title']);
    $finalYear = array_key_exists('year', $data)
        ? (int) $data['year']
        : (int) $existingCycle['year'];
    $finalStartDate = array_key_exists('start_date', $data)
        ? (trim((string) $data['start_date']) ?: null)
        : ($existingCycle['start_date'] ?: null);
    $finalEndDate = array_key_exists('end_date', $data)
        ? (trim((string) $data['end_date']) ?: null)
        : ($existingCycle['end_date'] ?: null);
    $finalActive = array_key_exists('is_active', $data)
        ? ((int) $data['is_active'] === 1 ? 1 : 0)
        : (int) $existingCycle['is_active'];

    if ($finalTitle === '') {
        throw new Exception('Cycle title is required.', 400);
    }

    $currentYear = (int) date('Y');
    if ($finalYear < 2020 || $finalYear > $currentYear + 5) {
        throw new Exception('Invalid year. Must be between 2020 and ' . ($currentYear + 5) . '.', 400);
    }

    if ($finalStartDate !== null && !strtotime($finalStartDate)) {
        throw new Exception('Invalid start_date format. Use YYYY-MM-DD.', 400);
    }
    if ($finalEndDate !== null && !strtotime($finalEndDate)) {
        throw new Exception('Invalid end_date format. Use YYYY-MM-DD.', 400);
    }
    if (
        $finalStartDate !== null
        && $finalEndDate !== null
        && strtotime($finalStartDate) > strtotime($finalEndDate)
    ) {
        throw new Exception('start_date cannot be after end_date.', 400);
    }

    /*
     * Multiple appraisal cycles may exist in the same year.
     * Block only another exact cycle with the same title and date window.
     */
    $duplicateStmt = $conn->prepare("
        SELECT id
        FROM appraisal_cycles
        WHERE company_id = ?
          AND year = ?
          AND LOWER(TRIM(title)) = LOWER(TRIM(?))
          AND COALESCE(start_date, '') = COALESCE(?, '')
          AND COALESCE(end_date, '') = COALESCE(?, '')
          AND id <> ?
        LIMIT 1
    ");
    if (!$duplicateStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $duplicateStmt->bind_param(
        'iisssi',
        $targetCompanyId,
        $finalYear,
        $finalTitle,
        $finalStartDate,
        $finalEndDate,
        $cycleId
    );
    $duplicateStmt->execute();
    $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
    $duplicateStmt->close();

    if ($duplicateExists) {
        throw new Exception('An identical appraisal cycle already exists for this company and period.', 409);
    }

    $conn->begin_transaction();

    try {
        if ($finalActive === 1) {
            $deactivateStmt = $conn->prepare('
                UPDATE appraisal_cycles
                SET is_active = 0
                WHERE company_id = ? AND id <> ?
            ');
            if (!$deactivateStmt) {
                throw new Exception('Database error: ' . $conn->error, 500);
            }
            $deactivateStmt->bind_param('ii', $targetCompanyId, $cycleId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $updateStmt = $conn->prepare('
            UPDATE appraisal_cycles
            SET title = ?,
                year = ?,
                start_date = ?,
                end_date = ?,
                is_active = ?
            WHERE id = ?
        ');
        if (!$updateStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $updateStmt->bind_param(
            'sissii',
            $finalTitle,
            $finalYear,
            $finalStartDate,
            $finalEndDate,
            $finalActive,
            $cycleId
        );

        if (!$updateStmt->execute()) {
            throw new Exception('Update failed: ' . $updateStmt->error, 500);
        }
        $updateStmt->close();

        $logStmt = $conn->prepare('
            INSERT INTO audit_log
                (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if ($logStmt) {
            $action = 'update_cycle';
            $targetTable = 'appraisal_cycles';
            $description = sprintf(
                '%s updated appraisal cycle ID %d: %s (%d), %s to %s, status %s',
                $loggedInUserEmail,
                $cycleId,
                $finalTitle,
                $finalYear,
                $finalStartDate ?: 'no start date',
                $finalEndDate ?: 'no end date',
                $finalActive === 1 ? 'Active' : 'Inactive'
            );
            $logStmt->bind_param(
                'iissis',
                $targetCompanyId,
                $loggedInUserId,
                $action,
                $targetTable,
                $cycleId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }

    $fetchStmt = $conn->prepare('
        SELECT
            ac.id,
            ac.year,
            ac.title,
            ac.start_date,
            ac.end_date,
            ac.is_active,
            ac.created_at,
            ac.updated_at,
            c.id AS company_id,
            c.code AS company_code,
            c.name AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = ?
        LIMIT 1
    ');
    if (!$fetchStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $fetchStmt->bind_param('i', $cycleId);
    $fetchStmt->execute();
    $updatedCycle = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        'status'  => 'Success',
        'message' => 'Appraisal cycle updated successfully.',
        'data'    => $updatedCycle,
    ]);
} catch (Throwable $e) {
    error_log('UpdateCycle Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status'  => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
