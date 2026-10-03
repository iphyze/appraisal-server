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

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['section_id']) || !is_numeric($data['section_id'])) {
        throw new Exception("Field 'section_id' is required.", 400);
    }

    if (!isset($data['questions']) || !is_array($data['questions']) || count($data['questions']) === 0) {
        throw new Exception("Field 'questions' must be a non-empty array.", 400);
    }

    $sectionId = (int) $data['section_id'];
    $isActive  = isset($data['is_active']) ? ((int) $data['is_active'] === 1 ? 1 : 0) : 1;
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
        throw new Exception("Field 'group_anchor_id' is required when deleting questions from a general question set.", 400);
    }

    $sectionStmt = $conn->prepare("
        SELECT id, company_id, type, code, label, cycle_id
        FROM appraisal_sections
        WHERE id = ?
        LIMIT 1
    ");
    if (!$sectionStmt) throw new Exception("Database error: " . $conn->error, 500);
    $sectionStmt->bind_param("i", $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found.", 404);
    if ($section['type'] !== 'general') throw new Exception("Selected section is not a general section.", 400);

    $companyId = (int) $section['company_id'];

    if ($loggedInUserRole !== 'super_admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception("Unauthorized: You can only update questions within your company.", 403);
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
                throw new Exception("General question ID {$rowId} was submitted more than once.", 400);
            }
            $submittedExistingIds[$rowId] = true;
        }

        $rowIsActive = isset($row['is_active'])
            ? ((int) $row['is_active'] === 1 ? 1 : 0)
            : $isActive;

        $cleanQuestions[] = [
            'id'            => $rowId,
            'question_text' => $questionText,
            'sort_order'    => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
            'is_active'     => $rowIsActive,
        ];
    }

    foreach ($deleteIds as $deleteId) {
        if (isset($submittedExistingIds[$deleteId])) {
            throw new Exception("General question ID {$deleteId} cannot be updated and deleted in the same request.", 400);
        }
    }

    if ($groupAnchorId) {
        $anchorStmt = $conn->prepare("
            SELECT id, company_id, section_id
            FROM general_questions
            WHERE id = ?
            LIMIT 1
        ");
        if (!$anchorStmt) throw new Exception("Database error: " . $conn->error, 500);
        $anchorStmt->bind_param("i", $groupAnchorId);
        $anchorStmt->execute();
        $sourceGroup = $anchorStmt->get_result()->fetch_assoc();
        $anchorStmt->close();

        if (!$sourceGroup) {
            throw new Exception("The general question set no longer exists. Refresh the page and try again.", 409);
        }

        if ((int) $sourceGroup['company_id'] !== $companyId) {
            throw new Exception("A general question set cannot be moved to another company.", 400);
        }

        if ($loggedInUserRole !== 'super_admin' && (int) $sourceGroup['company_id'] !== $loggedInCompanyId) {
            throw new Exception("Unauthorized: This general question set is outside your company.", 403);
        }

        $sourceCompanyId = (int) $sourceGroup['company_id'];
        $sourceSectionId = (int) $sourceGroup['section_id'];

        $sourceStmt = $conn->prepare("
            SELECT id
            FROM general_questions
            WHERE company_id = ?
              AND section_id = ?
            ORDER BY id ASC
        ");
        if (!$sourceStmt) throw new Exception("Database error: " . $conn->error, 500);
        $sourceStmt->bind_param("ii", $sourceCompanyId, $sourceSectionId);
        $sourceStmt->execute();
        $sourceRows = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sourceStmt->close();

        $sourceGroupIds = array_map('intval', array_column($sourceRows, 'id'));
        sort($sourceGroupIds);

        $submittedGroupIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($submittedExistingIds)),
            $deleteIds
        )));
        sort($submittedGroupIds);

        if ($sourceGroupIds !== $submittedGroupIds) {
            throw new Exception(
                "This general question set changed after it was opened. Refresh the page before saving so no questions are missed.",
                409
            );
        }
    }

    if (!empty($deleteIds)) {
        foreach ($deleteIds as $deleteId) {
            $usedStmt = $conn->prepare("
                SELECT COUNT(*) AS cnt
                FROM appraisal_section_responses
                WHERE general_question_id = ?
            ");
            if (!$usedStmt) throw new Exception("Database error: " . $conn->error, 500);
            $usedStmt->bind_param("i", $deleteId);
            $usedStmt->execute();
            $responseCount = (int) $usedStmt->get_result()->fetch_assoc()['cnt'];
            $usedStmt->close();

            if ($responseCount > 0) {
                throw new Exception(
                    "Cannot delete general question ID {$deleteId} — it has {$responseCount} appraisal response(s). Deactivate it instead.",
                    409
                );
            }
        }
    }

    $conn->begin_transaction();

    $updateStmt = $conn->prepare("
        UPDATE general_questions
        SET section_id = ?, question_text = ?, sort_order = ?, is_active = ?, updated_by = ?
        WHERE id = ? AND company_id = ?
    ");
    if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);

    $insertStmt = $conn->prepare("
        INSERT INTO general_questions
            (company_id, section_id, question_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $affectedIds = [];
    foreach ($cleanQuestions as $row) {
        $questionText = $row['question_text'];
        $sortOrder    = $row['sort_order'];
        $rowIsActive  = $row['is_active'];

        if ($row['id']) {
            $id = $row['id'];

            $checkStmt = $conn->prepare("
                SELECT id
                FROM general_questions
                WHERE id = ? AND company_id = ?
                LIMIT 1
            ");
            if (!$checkStmt) throw new Exception("Database error: " . $conn->error, 500);
            $checkStmt->bind_param("ii", $id, $companyId);
            $checkStmt->execute();
            if ($checkStmt->get_result()->num_rows === 0) {
                $checkStmt->close();
                throw new Exception("General question ID {$id} was not found for this company.", 404);
            }
            $checkStmt->close();

            $updateStmt->bind_param(
                "isiiiii",
                $sectionId,
                $questionText,
                $sortOrder,
                $rowIsActive,
                $loggedInUserId,
                $id,
                $companyId
            );
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update general question: " . $updateStmt->error, 500);
            }
            $affectedIds[] = $id;
        } else {
            $insertStmt->bind_param(
                "iisiii",
                $companyId,
                $sectionId,
                $questionText,
                $sortOrder,
                $rowIsActive,
                $loggedInUserId
            );
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to create new general question: " . $insertStmt->error, 500);
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
        $deleteFetchTypes = 'i' . $deleteTypes;
        $deleteFetchParams = array_merge([$companyId], $deleteIds);

        $deleteFetchStmt = $conn->prepare("
            SELECT id, question_text
            FROM general_questions
            WHERE company_id = ?
              AND id IN ({$deletePlaceholders})
        ");
        if (!$deleteFetchStmt) throw new Exception("Database error: " . $conn->error, 500);
        $deleteFetchStmt->bind_param($deleteFetchTypes, ...$deleteFetchParams);
        $deleteFetchStmt->execute();
        $deletedQuestions = $deleteFetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $deleteFetchStmt->close();

        if (count($deletedQuestions) !== count($deleteIds)) {
            throw new Exception("One or more general questions selected for deletion could not be found.", 409);
        }

        $deleteStmt = $conn->prepare("
            DELETE FROM general_questions
            WHERE company_id = ?
              AND id IN ({$deletePlaceholders})
        ");
        if (!$deleteStmt) throw new Exception("Database error: " . $conn->error, 500);
        $deleteStmt->bind_param($deleteFetchTypes, ...$deleteFetchParams);
        if (!$deleteStmt->execute()) {
            throw new Exception("Failed to delete general question: " . $deleteStmt->error, 500);
        }
        $deleteStmt->close();
    }

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($logStmt) {
        $action      = "bulk_update_general_questions";
        $targetTable = "general_questions";
        $targetId    = $affectedIds[0] ?? 0;
        $count       = count($affectedIds);
        $deletedCount = count($deletedQuestions);
        $description = "{$loggedInUserEmail} saved {$count} general question row(s) for section {$section['code']}" .
            ($deletedCount > 0 ? " and deleted {$deletedCount} row(s)." : ".");
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();

        foreach ($deletedQuestions as $deletedQuestion) {
            $deleteAction = "delete_general_question";
            $deleteTargetTable = "general_questions";
            $deleteTargetId = (int) $deletedQuestion['id'];
            $deleteDescription = "{$loggedInUserEmail} deleted general question ID {$deletedQuestion['id']}: " .
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

    $saved = [];
    if (!empty($affectedIds)) {
        $placeholders = implode(',', array_fill(0, count($affectedIds), '?'));
        $types = str_repeat('i', count($affectedIds));
        $fetchStmt = $conn->prepare("
            SELECT gq.*, s.code AS section_code, s.label AS section_label, ac.year AS cycle_year
            FROM general_questions gq
            INNER JOIN appraisal_sections s ON s.id = gq.section_id
            INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
            WHERE gq.id IN ($placeholders)
            ORDER BY gq.sort_order ASC, gq.id ASC
        ");
        if (!$fetchStmt) throw new Exception("Database error: " . $conn->error, 500);
        $fetchStmt->bind_param($types, ...$affectedIds);
        $fetchStmt->execute();
        $saved = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $fetchStmt->close();
    }

    $deletedCount = count($deletedQuestions);
    $message = count($affectedIds) . " general question row(s) saved successfully";
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
    error_log("BulkUpdateGeneralQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
