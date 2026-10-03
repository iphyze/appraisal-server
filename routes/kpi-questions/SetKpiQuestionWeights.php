<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function nullableScopeId($value) {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (int) $value;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed', 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);

    if (!isset($data['section_id']) || !is_numeric($data['section_id'])) {
        throw new Exception("Field 'section_id' is required.", 400);
    }
    if (!isset($data['department']) || trim((string)$data['department']) === '') {
        throw new Exception("Field 'department' is required.", 400);
    }

    $sectionId    = (int) $data['section_id'];
    $department   = trim((string) $data['department']);
    $supervisorId = nullableScopeId($data['supervisor_id'] ?? null);
    $staffUserId  = nullableScopeId($data['staff_user_id'] ?? null);
    $mode          = strtolower(trim((string)($data['mode'] ?? 'equal')));
    $mode          = $mode === 'custom' ? 'custom' : 'equal';

    $sectionStmt = $conn->prepare("\n        SELECT s.id, s.company_id, s.type, s.cycle_id, ac.year AS cycle_year\n        FROM appraisal_sections s\n        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id\n        WHERE s.id = ?\n        LIMIT 1\n    ");
    if (!$sectionStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $sectionStmt->bind_param('i', $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception('KPI section not found.', 404);
    if ($section['type'] !== 'kpi') throw new Exception('Question weights can only be configured for KPI sections.', 400);

    $companyId = (int) $section['company_id'];
    if ($loggedInUserRole !== 'super_admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: You can only configure KPI weights within your company.', 403);
    }

    if ($staffUserId) {
        $scopeSql = 'staff_user_id = ?';
        $scopeParams = [$staffUserId];
        $scopeTypes = 'i';
    } elseif ($supervisorId) {
        $scopeSql = 'supervisor_id = ? AND staff_user_id IS NULL';
        $scopeParams = [$supervisorId];
        $scopeTypes = 'i';
    } else {
        $scopeSql = 'supervisor_id IS NULL AND staff_user_id IS NULL';
        $scopeParams = [];
        $scopeTypes = '';
    }

    $groupSql = "\n        SELECT id, question_text, weight_percent, sort_order, is_active\n        FROM kpi_questions\n        WHERE company_id = ?\n          AND section_id = ?\n          AND department = ?\n          AND {$scopeSql}\n        ORDER BY sort_order ASC, id ASC\n    ";
    $groupStmt = $conn->prepare($groupSql);
    if (!$groupStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $groupParams = array_merge([$companyId, $sectionId, $department], $scopeParams);
    $groupTypes = 'iis' . $scopeTypes;
    $groupStmt->bind_param($groupTypes, ...$groupParams);
    $groupStmt->execute();
    $groupQuestions = $groupStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $groupStmt->close();

    if (!$groupQuestions) throw new Exception('No KPI questions were found for this question set.', 404);

    $activeIds = array_values(array_map(
        fn($row) => (int)$row['id'],
        array_filter($groupQuestions, fn($row) => (int)$row['is_active'] === 1)
    ));

    if ($mode === 'custom' && empty($activeIds)) {
        throw new Exception('At least one active KPI question is required for custom weighting.', 400);
    }

    $weights = [];
    if ($mode === 'custom') {
        $weightRows = $data['weights'] ?? null;
        if (!is_array($weightRows) || empty($weightRows)) {
            throw new Exception("Field 'weights' must contain every active KPI question when custom weighting is enabled.", 400);
        }

        foreach ($weightRows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])) {
                throw new Exception('Each KPI weight row must contain a valid question ID.', 400);
            }
            $questionId = (int)$row['id'];
            if (!in_array($questionId, $activeIds, true)) {
                throw new Exception("KPI question ID {$questionId} is not an active question in this question set.", 400);
            }
            if (!isset($row['weight_percent']) || !is_numeric($row['weight_percent'])) {
                throw new Exception("A numeric weight is required for KPI question ID {$questionId}.", 400);
            }
            $weight = round((float)$row['weight_percent'], 2);
            if ($weight <= 0 || $weight > 100) {
                throw new Exception('Each KPI question weight must be greater than 0 and no more than 100.', 400);
            }
            $weights[$questionId] = $weight;
        }

        $providedIds = array_map('intval', array_keys($weights));
        sort($providedIds);
        $expectedIds = $activeIds;
        sort($expectedIds);
        if ($providedIds !== $expectedIds) {
            throw new Exception('Every active KPI question in this question set must have exactly one weight.', 400);
        }

        $total = round(array_sum($weights), 2);
        if (abs($total - 100.0) > 0.01) {
            throw new Exception("Custom KPI question weights must total exactly 100%. Current total: {$total}%.", 400);
        }
    }

    $conn->begin_transaction();

    // Clear the whole set first. This prevents stale weights on inactive questions.
    $clearSql = "\n        UPDATE kpi_questions\n        SET weight_percent = NULL, updated_by = ?\n        WHERE company_id = ?\n          AND section_id = ?\n          AND department = ?\n          AND {$scopeSql}\n    ";
    $clearStmt = $conn->prepare($clearSql);
    if (!$clearStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $clearParams = array_merge([$loggedInUserId, $companyId, $sectionId, $department], $scopeParams);
    $clearTypes = 'iiis' . $scopeTypes;
    $clearStmt->bind_param($clearTypes, ...$clearParams);
    if (!$clearStmt->execute()) throw new Exception('Unable to clear existing KPI question weights.', 500);
    $clearStmt->close();

    if ($mode === 'custom') {
        $updateStmt = $conn->prepare("\n            UPDATE kpi_questions\n            SET weight_percent = ?, updated_by = ?\n            WHERE id = ? AND company_id = ?\n        ");
        if (!$updateStmt) throw new Exception('Database error: ' . $conn->error, 500);

        foreach ($weights as $questionId => $weight) {
            $updateStmt->bind_param('diii', $weight, $loggedInUserId, $questionId, $companyId);
            if (!$updateStmt->execute()) throw new Exception('Unable to save KPI question weights.', 500);
        }
        $updateStmt->close();
    }

    $scopeLabel = $staffUserId ? "staff {$staffUserId}" : ($supervisorId ? "supervisor {$supervisorId}" : 'department default');
    $logStmt = $conn->prepare("\n        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)\n        VALUES (?, ?, ?, ?, ?, ?)\n    ");
    if ($logStmt) {
        $action = $mode === 'custom' ? 'set_kpi_question_weights' : 'clear_kpi_question_weights';
        $targetTable = 'kpi_questions';
        $targetId = $sectionId;
        $description = "{$loggedInUserEmail} " . ($mode === 'custom' ? 'configured' : 'cleared') . " KPI question weights for {$department} ({$scopeLabel}), cycle {$section['cycle_year']}.";
        $logStmt->bind_param('iissis', $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    $conn->commit();

    $fetchStmt = $conn->prepare($groupSql);
    $fetchStmt->bind_param($groupTypes, ...$groupParams);
    $fetchStmt->execute();
    $updatedQuestions = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => $mode === 'custom'
            ? 'KPI question weights saved successfully.'
            : 'KPI question weights cleared. Equal weighting remains the default.',
        'data' => [
            'mode' => $mode,
            'section_id' => $sectionId,
            'department' => $department,
            'supervisor_id' => $supervisorId,
            'staff_user_id' => $staffUserId,
            'questions' => $updatedQuestions,
        ],
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    error_log('SetKpiQuestionWeights Error: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
