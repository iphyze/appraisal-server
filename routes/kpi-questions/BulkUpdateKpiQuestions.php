<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

function nullableInt($value) {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (int) $value;
}

function nullableWeight($value, $rowNumber) {
    if ($value === null || $value === '') return null;
    if (!is_numeric($value)) throw new Exception("Weight on row {$rowNumber} must be a number.", 400);
    $weight = round((float) $value, 2);
    if ($weight <= 0 || $weight > 100) throw new Exception("Weight on row {$rowNumber} must be greater than 0 and no more than 100.", 400);
    return $weight;
}

function nullableIdsMatch($left, $right) {
    $leftValue = $left === null ? null : (int) $left;
    $rightValue = $right === null ? null : (int) $right;
    return $leftValue === $rightValue;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['section_id']) || !is_numeric($data['section_id'])) {
        throw new Exception("Field 'section_id' is required.", 400);
    }

    if (!isset($data['department']) || trim($data['department']) === '') {
        throw new Exception("Field 'department' is required.", 400);
    }

    if (!isset($data['questions']) || !is_array($data['questions']) || count($data['questions']) === 0) {
        throw new Exception("Field 'questions' must be a non-empty array.", 400);
    }

    $sectionId    = (int) $data['section_id'];
    $department   = trim($data['department']);
    $isActive     = isset($data['is_active']) ? ((int) $data['is_active'] === 1 ? 1 : 0) : 1;
    $supervisorId = nullableInt($data['supervisor_id'] ?? null);
    $staffUserId  = nullableInt($data['staff_user_id'] ?? null);
    $groupAnchorId = isset($data['group_anchor_id']) && is_numeric($data['group_anchor_id'])
        ? (int) $data['group_anchor_id']
        : null;

    $deleteIds = [];
    if (isset($data['delete_ids'])) {
        if (!is_array($data['delete_ids'])) {
            throw new Exception("Field 'delete_ids' must be an array when provided.", 400);
        }
        $deleteIds = array_values(array_unique(array_filter(
            array_map('intval', $data['delete_ids']),
            fn($id) => $id > 0
        )));
    }

    if (!empty($deleteIds) && !$groupAnchorId) {
        throw new Exception("Field 'group_anchor_id' is required when deleting questions from a KPI question set.", 400);
    }

    if (!empty($deleteIds) && $loggedInUserRole === 'supervisor') {
        throw new Exception("Only Admin or Super Admin can delete saved KPI questions.", 403);
    }

    $weightMode = null;
    if (isset($data['weight_mode']) && $data['weight_mode'] !== '') {
        $weightMode = strtolower(trim((string) $data['weight_mode']));
        if (!in_array($weightMode, ['equal', 'custom'], true)) {
            throw new Exception("Field 'weight_mode' must be either 'equal' or 'custom'.", 400);
        }
    }

    $sectionStmt = $conn->prepare("
        SELECT id, company_id, type, code, label, cycle_id
        FROM appraisal_sections
        WHERE id = ?
        LIMIT 1
    ");
    $sectionStmt->bind_param("i", $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found.", 404);
    if ($section['type'] !== 'kpi') throw new Exception("Selected section is not a KPI section.", 400);

    $companyId = (int) $section['company_id'];
    $cycleId   = (int) $section['cycle_id'];

    if ($loggedInUserRole !== 'super_admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception("Unauthorized: You can only update KPI questions within your company.", 403);
    }

    if ($loggedInUserRole === 'supervisor') {
        if (!$supervisorId && !$staffUserId) {
            $supervisorId = $loggedInUserId;
        }

        if ($supervisorId && $supervisorId !== $loggedInUserId) {
            throw new Exception("You can only save KPI questions scoped to yourself.", 403);
        }

        if ($staffUserId) {
            $subStmt = $conn->prepare("
                SELECT id
                FROM supervisor_assignments
                WHERE supervisor_id = ?
                  AND staff_id = ?
                  AND cycle_id = ?
                LIMIT 1
            ");
            $subStmt->bind_param("iii", $loggedInUserId, $staffUserId, $cycleId);
            $subStmt->execute();
            if ($subStmt->get_result()->num_rows === 0) {
                throw new Exception("You can only save KPI questions for staff assigned to you.", 403);
            }
            $subStmt->close();
        }
    }

    if ($supervisorId && $loggedInUserRole !== 'supervisor') {
        $appraiserWhere = appraiserRoleWhere('r', 'u');
        $supStmt = $conn->prepare("
            SELECT u.id
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = ?
              AND u.company_id = ?
              AND u.is_active = 1
              AND {$appraiserWhere}
            LIMIT 1
        ");
        if (!$supStmt) throw new Exception("Database error: " . $conn->error, 500);
        $supStmt->bind_param("ii", $supervisorId, $companyId);
        $supStmt->execute();
        if ($supStmt->get_result()->num_rows === 0) {
            throw new Exception("Selected supervisor is not an active appraisal supervisor for this company.", 404);
        }
        $supStmt->close();
    }

    $cleanQuestions = [];
    $submittedExistingIds = [];

    foreach ($data['questions'] as $index => $row) {
        if (!is_array($row)) throw new Exception("Question row " . ($index + 1) . " is invalid.", 400);

        $questionText = isset($row['question_text']) ? trim($row['question_text']) : '';
        if ($questionText === '') throw new Exception("Question text is required on row " . ($index + 1) . ".", 400);

        $rowId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
        if ($rowId) {
            if (isset($submittedExistingIds[$rowId])) {
                throw new Exception("KPI question ID {$rowId} was submitted more than once.", 400);
            }
            $submittedExistingIds[$rowId] = true;
        }

        $rowIsActive = isset($row['is_active'])
            ? ((int) $row['is_active'] === 1 ? 1 : 0)
            : $isActive;

        $weightPercent = nullableWeight($row['weight_percent'] ?? null, $index + 1);
        if ($weightMode === 'equal' || ($weightMode === 'custom' && $rowIsActive === 0)) {
            $weightPercent = null;
        }

        $cleanQuestions[] = [
            'id'             => $rowId,
            'question_text'  => $questionText,
            'weight_percent' => $weightPercent,
            'sort_order'     => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
            'is_active'      => $rowIsActive,
        ];
    }

    foreach ($deleteIds as $deleteId) {
        if (isset($submittedExistingIds[$deleteId])) {
            throw new Exception("KPI question ID {$deleteId} cannot be updated and deleted in the same request.", 400);
        }
    }

    if ($weightMode === 'custom') {
        $activeRows = array_values(array_filter($cleanQuestions, fn($row) => (int) $row['is_active'] === 1));
        if (empty($activeRows)) {
            throw new Exception("At least one active KPI question is required for custom weighting.", 400);
        }

        $weightTotal = 0.0;
        foreach ($activeRows as $index => $row) {
            if ($row['weight_percent'] === null) {
                throw new Exception("Every active KPI question must have a weight when custom weighting is enabled.", 400);
            }
            $weightTotal += (float) $row['weight_percent'];
        }

        $weightTotal = round($weightTotal, 2);
        if (abs($weightTotal - 100.0) > 0.01) {
            throw new Exception("Custom KPI question weights must total exactly 100%. Current total: {$weightTotal}%.", 400);
        }
    }

    $sourceGroup = null;
    $sourceGroupIds = [];

    if ($groupAnchorId) {
        $anchorStmt = $conn->prepare("
            SELECT id, company_id, section_id, department, supervisor_id, staff_user_id
            FROM kpi_questions
            WHERE id = ?
            LIMIT 1
        ");
        $anchorStmt->bind_param("i", $groupAnchorId);
        $anchorStmt->execute();
        $sourceGroup = $anchorStmt->get_result()->fetch_assoc();
        $anchorStmt->close();

        if (!$sourceGroup) {
            throw new Exception("The KPI question set no longer exists. Refresh the page and try again.", 409);
        }

        if ((int) $sourceGroup['company_id'] !== $companyId) {
            throw new Exception("A KPI question set cannot be moved to another company.", 400);
        }

        if ($loggedInUserRole !== 'super_admin' && (int) $sourceGroup['company_id'] !== $loggedInCompanyId) {
            throw new Exception("Unauthorized: This KPI question set is outside your company.", 403);
        }

        $sourceCompanyId = (int) $sourceGroup['company_id'];
        $sourceSectionId = (int) $sourceGroup['section_id'];
        $sourceDepartment = $sourceGroup['department'];

        $sourceStmt = $conn->prepare("
            SELECT id, supervisor_id, staff_user_id
            FROM kpi_questions
            WHERE company_id = ?
              AND section_id = ?
              AND department = ?
            ORDER BY id ASC
        ");
        $sourceStmt->bind_param("iis", $sourceCompanyId, $sourceSectionId, $sourceDepartment);
        $sourceStmt->execute();
        $sourceCandidates = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sourceStmt->close();

        foreach ($sourceCandidates as $candidate) {
            if (
                nullableIdsMatch($candidate['supervisor_id'], $sourceGroup['supervisor_id']) &&
                nullableIdsMatch($candidate['staff_user_id'], $sourceGroup['staff_user_id'])
            ) {
                $sourceGroupIds[] = (int) $candidate['id'];
            }
        }

        sort($sourceGroupIds);
        $submittedGroupIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($submittedExistingIds)),
            $deleteIds
        )));
        sort($submittedGroupIds);

        if ($sourceGroupIds !== $submittedGroupIds) {
            throw new Exception(
                "This KPI question set changed after it was opened. Refresh the page before saving so no questions are missed.",
                409
            );
        }
    }

    if (!empty($deleteIds)) {
        foreach ($deleteIds as $deleteId) {
            $usedStmt = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM appraisal_kpi_responses
                WHERE kpi_question_id = ?
            ");
            $usedStmt->bind_param("i", $deleteId);
            $usedStmt->execute();
            $responseCount = (int) $usedStmt->get_result()->fetch_assoc()['cnt'];
            $usedStmt->close();

            if ($responseCount > 0) {
                throw new Exception(
                    "Cannot delete KPI question ID {$deleteId} — it has {$responseCount} appraisal response(s). Deactivate it instead.",
                    409
                );
            }
        }
    }

    $conn->begin_transaction();

    $updateStmt = $conn->prepare("
        UPDATE kpi_questions
        SET section_id = ?, department = ?, supervisor_id = ?, staff_user_id = ?,
            question_text = ?, weight_percent = ?, sort_order = ?, is_active = ?, updated_by = ?
        WHERE id = ? AND company_id = ?
    ");
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt = $conn->prepare("
        INSERT INTO kpi_questions
            (company_id, section_id, department, supervisor_id, staff_user_id, question_text, weight_percent, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $affectedIds = [];
    foreach ($cleanQuestions as $row) {
        $questionText  = $row['question_text'];
        $weightPercent = $row['weight_percent'];
        $sortOrder     = $row['sort_order'];
        $rowIsActive   = $row['is_active'];

        if ($row['id']) {
            $id = $row['id'];

            $checkStmt = $conn->prepare("
                SELECT id, supervisor_id, staff_user_id
                FROM kpi_questions
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            $checkStmt->bind_param("ii", $id, $companyId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();

            if (!$existing) throw new Exception("KPI question ID {$id} was not found for this company.", 404);

            if ($loggedInUserRole === 'supervisor') {
                $existingSupervisorId = $existing['supervisor_id'] !== null ? (int) $existing['supervisor_id'] : null;
                $existingStaffId      = $existing['staff_user_id'] !== null ? (int) $existing['staff_user_id'] : null;

                if (!$existingSupervisorId && !$existingStaffId) {
                    throw new Exception("Supervisors cannot update departmental default KPI questions.", 403);
                }

                if ($existingSupervisorId && $existingSupervisorId !== $loggedInUserId) {
                    throw new Exception("You can only update KPI questions scoped to yourself.", 403);
                }
            }

            $updateStmt->bind_param(
                "isiisdiiiii",
                $sectionId,
                $department,
                $supervisorId,
                $staffUserId,
                $questionText,
                $weightPercent,
                $sortOrder,
                $rowIsActive,
                $loggedInUserId,
                $id,
                $companyId
            );
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update KPI question: " . $updateStmt->error, 500);
            }
            $affectedIds[] = $id;
        } else {
            $insertStmt->bind_param(
                "iisiisdiii",
                $companyId,
                $sectionId,
                $department,
                $supervisorId,
                $staffUserId,
                $questionText,
                $weightPercent,
                $sortOrder,
                $rowIsActive,
                $loggedInUserId
            );
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to create new KPI question: " . $insertStmt->error, 500);
            }
            $affectedIds[] = $insertStmt->insert_id;
        }
    }

    $updateStmt->close();
    $insertStmt->close();

    $deletedQuestions = [];
    if (!empty($deleteIds)) {
        $deletePlaceholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $deleteTypes = str_repeat('i', count($deleteIds));

        $deleteFetchStmt = $conn->prepare("
            SELECT id, department, question_text
            FROM kpi_questions
            WHERE company_id = ?
              AND id IN ({$deletePlaceholders})
        ");
        $deleteFetchTypes = 'i' . $deleteTypes;
        $deleteFetchParams = array_merge([$companyId], $deleteIds);
        $deleteFetchStmt->bind_param($deleteFetchTypes, ...$deleteFetchParams);
        $deleteFetchStmt->execute();
        $deletedQuestions = $deleteFetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $deleteFetchStmt->close();

        if (count($deletedQuestions) !== count($deleteIds)) {
            throw new Exception("One or more KPI questions selected for deletion could not be found.", 409);
        }

        $deleteStmt = $conn->prepare("
            DELETE FROM kpi_questions
            WHERE company_id = ?
              AND id IN ({$deletePlaceholders})
        ");
        $deleteStmt->bind_param($deleteFetchTypes, ...$deleteFetchParams);
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete KPI question: " . $deleteStmt->error, 500);
        }
        $deleteStmt->close();
    }

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($logStmt) {
        $action      = "bulk_update_kpi_questions";
        $targetTable = "kpi_questions";
        $targetId    = $affectedIds[0] ?? 0;
        $count       = count($affectedIds);
        $deletedCount = count($deletedQuestions);
        $description = "{$loggedInUserEmail} saved {$count} KPI question row(s) for {$department}" .
            ($deletedCount > 0 ? " and deleted {$deletedCount} row(s)." : ".");
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();

        foreach ($deletedQuestions as $deletedQuestion) {
            $deleteAction = "delete_kpi_question";
            $deleteTargetTable = "kpi_questions";
            $deleteTargetId = (int) $deletedQuestion['id'];
            $deleteDescription = "{$loggedInUserEmail} deleted KPI question (dept: {$deletedQuestion['department']}): " .
                substr($deletedQuestion['question_text'], 0, 80);
            $logStmt->bind_param(
                "iissis",
                $companyId,
                $loggedInUserId,
                $deleteAction,
                $deleteTargetTable,
                $deleteTargetId,
                $deleteDescription
            );
            $logStmt->execute();
        }

        $logStmt->close();
    }

    $conn->commit();

    $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
    $types = str_repeat('i', count($affectedIds));
    $fetchStmt = $conn->prepare("
        SELECT
            kq.*,
            s.code AS section_code,
            s.label AS section_label,
            ac.year AS cycle_year,
            CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name,
            CONCAT(stf.first_name, ' ', stf.last_name) AS staff_name
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id = kq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        LEFT JOIN users sup ON sup.id = kq.supervisor_id
        LEFT JOIN users stf ON stf.id = kq.staff_user_id
        WHERE kq.id IN ($placeholders)
        ORDER BY kq.sort_order ASC, kq.id ASC
    ");
    $fetchStmt->bind_param($types, ...$affectedIds);
    $fetchStmt->execute();
    $saved = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();

    $deletedCount = count($deletedQuestions);
    $message = count($affectedIds) . " KPI question row(s) saved successfully";
    if ($deletedCount > 0) {
        $message .= "; {$deletedCount} question(s) deleted";
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => $message,
        "data"    => $saved,
        "deleted_ids" => array_map('intval', array_column($deletedQuestions, 'id')),
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    error_log("BulkUpdateKpiQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
