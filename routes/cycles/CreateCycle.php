<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
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

    $year      = isset($data['year']) ? (int) $data['year'] : 0;
    $title     = trim((string) ($data['title'] ?? ''));
    $startDate = trim((string) ($data['start_date'] ?? '')) ?: null;
    $endDate   = trim((string) ($data['end_date'] ?? '')) ?: null;
    $isActive  = isset($data['is_active']) ? (int) $data['is_active'] : 0;

    if ($title === '') {
        throw new Exception("Field 'title' is required.", 400);
    }

    $currentYear = (int) date('Y');
    if ($year < 2020 || $year > $currentYear + 5) {
        throw new Exception('Invalid year. Must be between 2020 and ' . ($currentYear + 5) . '.', 400);
    }

    if ($startDate !== null && !strtotime($startDate)) {
        throw new Exception('Invalid start_date format. Use YYYY-MM-DD.', 400);
    }
    if ($endDate !== null && !strtotime($endDate)) {
        throw new Exception('Invalid end_date format. Use YYYY-MM-DD.', 400);
    }
    if ($startDate !== null && $endDate !== null && strtotime($startDate) > strtotime($endDate)) {
        throw new Exception('start_date cannot be after end_date.', 400);
    }

    $isActive = $isActive === 1 ? 1 : 0;

    if ($loggedInUserRole === 'super_admin' && isset($data['company_id'])) {
        $companyId = (int) $data['company_id'];
    } else {
        $companyId = $loggedInCompanyId;
    }

    if ($companyId <= 0) {
        throw new Exception('Please select a valid company.', 400);
    }

    $companyStmt = $conn->prepare('SELECT id FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
    if (!$companyStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $companyStmt->bind_param('i', $companyId);
    $companyStmt->execute();
    $companyExists = $companyStmt->get_result()->num_rows > 0;
    $companyStmt->close();

    if (!$companyExists) {
        throw new Exception('Company not found or inactive.', 404);
    }

    /*
     * Multiple appraisal cycles are allowed in the same company and year.
     * Only an exact duplicate cycle (same title and same date window) is blocked.
     */
    $duplicateStmt = $conn->prepare("
        SELECT id
        FROM appraisal_cycles
        WHERE company_id = ?
          AND year = ?
          AND LOWER(TRIM(title)) = LOWER(TRIM(?))
          AND COALESCE(start_date, '') = COALESCE(?, '')
          AND COALESCE(end_date, '') = COALESCE(?, '')
        LIMIT 1
    ");
    if (!$duplicateStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $duplicateStmt->bind_param('iisss', $companyId, $year, $title, $startDate, $endDate);
    $duplicateStmt->execute();
    $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
    $duplicateStmt->close();

    if ($duplicateExists) {
        throw new Exception('An identical appraisal cycle already exists for this company and period.', 409);
    }

    $conn->begin_transaction();

    try {
        if ($isActive === 1) {
            $deactivateStmt = $conn->prepare('
                UPDATE appraisal_cycles
                SET is_active = 0
                WHERE company_id = ?
            ');
            if (!$deactivateStmt) {
                throw new Exception('Database error: ' . $conn->error, 500);
            }
            $deactivateStmt->bind_param('i', $companyId);
            $deactivateStmt->execute();
            $deactivateStmt->close();
        }

        $insertStmt = $conn->prepare('
            INSERT INTO appraisal_cycles
                (company_id, year, title, start_date, end_date, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        if (!$insertStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $insertStmt->bind_param(
            'iisssii',
            $companyId,
            $year,
            $title,
            $startDate,
            $endDate,
            $isActive,
            $loggedInUserId
        );

        if (!$insertStmt->execute()) {
            throw new Exception('Failed to create cycle: ' . $insertStmt->error, 500);
        }

        $newCycleId = (int) $insertStmt->insert_id;
        $insertStmt->close();

        $logStmt = $conn->prepare('
            INSERT INTO audit_log
                (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if ($logStmt) {
            $action = 'create_cycle';
            $targetTable = 'appraisal_cycles';
            $description = sprintf(
                '%s created appraisal cycle: %s (%d), %s to %s',
                $loggedInUserEmail,
                $title,
                $year,
                $startDate ?: 'no start date',
                $endDate ?: 'no end date'
            );
            $logStmt->bind_param(
                'iissis',
                $companyId,
                $loggedInUserId,
                $action,
                $targetTable,
                $newCycleId,
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
    $fetchStmt->bind_param('i', $newCycleId);
    $fetchStmt->execute();
    $newCycle = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(201);
    echo json_encode([
        'status'  => 'Success',
        'message' => 'Appraisal cycle created successfully.',
        'data'    => $newCycle,
    ]);
} catch (Throwable $e) {
    error_log('CreateCycle Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status'  => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
