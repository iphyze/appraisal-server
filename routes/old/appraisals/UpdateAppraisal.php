<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
// EmailTemplates already loaded

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    /**
     * Supervisors can update an appraisal they conducted.
     * MAX 2 updates after the initial submission.
     * On the 3rd attempt, the API blocks it entirely.
     * Admin/super_admin have no update limit.
     */
    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }
    if (!isset($data['sections']) || !is_array($data['sections']) || count($data['sections']) === 0) {
        throw new Exception("Field 'sections' is required and must be a non-empty array.", 400);
    }

    $appraisalId = (int) $data['id'];
    $sections    = $data['sections'];
    $sendEmail   = isset($data['send_email']) ? (bool) $data['send_email'] : false; // default OFF for updates

    // ── Fetch existing appraisal ──────────────────────────────────────────────
    $fetchStmt = $conn->prepare("
        SELECT ap.*, ac.year AS cycle_year,
               c.name AS company_name
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c         ON c.id  = ap.company_id
        WHERE ap.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $appraisalId);
    $fetchStmt->execute();
    $appraisal = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    if (!$appraisal) throw new Exception("Appraisal not found.", 404);

    // ── Company check ─────────────────────────────────────────────────────────
    if (
        $loggedInRole !== 'super_admin' &&
        (int) $appraisal['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: This appraisal does not belong to your company.", 403);
    }

    // ── Supervisor check ──────────────────────────────────────────────────────
    if ($loggedInRole === 'supervisor') {
        if ((int) $appraisal['supervisor_id'] !== $loggedInUserId) {
            throw new Exception("Unauthorized: You can only update appraisals you conducted.", 403);
        }

        /**
         * Update limit: supervisors can update at most 2 times after initial submission.
         * We track this via a new column `update_count` on the appraisals table.
         * If update_count >= 2, block the supervisor.
         */
        $updateCount = (int) ($appraisal['update_count'] ?? 0);
        if ($updateCount >= 2) {
            throw new Exception(
                "You have reached the maximum number of updates (2) for this appraisal. " .
                "Please contact an administrator if further changes are needed.",
                403
            );
        }
    }

    // ── Fetch sections for this cycle ─────────────────────────────────────────
    $secStmt = $conn->prepare("
        SELECT id, code, label, type, weight FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ? AND is_active = 1
        ORDER BY sort_order ASC
    ");
    $secStmt->bind_param("ii", $appraisal['cycle_id'], $appraisal['company_id']);
    $secStmt->execute();
    $cycleSections = $secStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $secStmt->close();

    $sectionMap = array_column($cycleSections, null, 'id');

    // ── Validate sections ─────────────────────────────────────────────────────
    foreach ($sections as $sec) {
        if (!isset($sec['section_id'], $sec['responses'])) {
            throw new Exception("Each section must have 'section_id' and 'responses'.", 400);
        }
        if (!isset($sectionMap[(int) $sec['section_id']])) {
            throw new Exception("Section ID {$sec['section_id']} is not valid for this cycle.", 400);
        }
        foreach ($sec['responses'] as $r) {
            if (!isset($r['question_id'], $r['rating'])) {
                throw new Exception("Each response must have 'question_id' and 'rating'.", 400);
            }
            $rating = (float) $r['rating'];
            if ($rating < 1 || $rating > 5) {
                throw new Exception("Rating must be between 1 and 5. Got: {$rating}", 400);
            }
        }
    }

    // ── Recalculate scores ────────────────────────────────────────────────────
    $sectionScores    = [];
    $appraisalSummary = 0;
    $kpiRating        = null;

    foreach ($sections as $sec) {
        $sectionId = (int) $sec['section_id'];
        $section   = $sectionMap[$sectionId];
        $ratings   = array_map(fn($r) => (float) $r['rating'], $sec['responses']);
        $avg       = count($ratings) > 0 ? array_sum($ratings) / count($ratings) : 0;
        $weighted  = $avg * ($section['weight'] / 100);

        $sectionScores[$sectionId] = [
            'section_code'   => $section['code'],
            'section_label'  => $section['label'],
            'section_weight' => (float) $section['weight'],
            'section_avg'    => round($avg, 2),
            'weighted_score' => round($weighted, 4),
        ];

        $appraisalSummary += $weighted;
        if ($section['type'] === 'kpi') $kpiRating = round($avg, 2);
    }

    $appraisalSummary = round($appraisalSummary, 2);

    $evaluationStatement = match(true) {
        $appraisalSummary >= 4.6 => 'Significant / Outstanding',
        $appraisalSummary >= 3.1 => 'Consistently Exceeds Requirements',
        $appraisalSummary >= 2.1 => 'Completely Meet Requirements',
        $appraisalSummary >= 1.1 => 'Marginally Meets Requirements',
        default                  => 'Requires Development',
    };

    // Track update count
    $newUpdateCount = ($appraisal['update_count'] ?? 0) + 1;

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();
    try {

        // 1. Update appraisal summary row
        $updStmt = $conn->prepare("
            UPDATE appraisals
            SET kpi_rating           = ?,
                appraisal_summary    = ?,
                evaluation_statement = ?,
                update_count         = ?,
                updated_by           = ?
            WHERE id = ?
        ");
        $updStmt->bind_param("ddsiii", $kpiRating, $appraisalSummary, $evaluationStatement, $newUpdateCount, $loggedInUserId, $appraisalId);
        if (!$updStmt->execute()) throw new Exception("Update failed: " . $updStmt->error, 500);
        $updStmt->close();

        // 2. Replace section scores — delete old, insert new
        $delScoresStmt = $conn->prepare("DELETE FROM appraisal_section_scores WHERE appraisal_id = ?");
        $delScoresStmt->bind_param("i", $appraisalId);
        $delScoresStmt->execute();
        $delScoresStmt->close();

        foreach ($sections as $sec) {
            $sectionId = (int) $sec['section_id'];
            $scores    = $sectionScores[$sectionId];

            $ssStmt = $conn->prepare("
                INSERT INTO appraisal_section_scores
                  (appraisal_id, section_id, section_code, section_label,
                   section_weight, section_avg, weighted_score)
                VALUES (?,?,?,?,?,?,?)
            ");
            $ssStmt->bind_param(
                "iissddd",
                $appraisalId, $sectionId,
                $scores['section_code'], $scores['section_label'],
                $scores['section_weight'], $scores['section_avg'],
                $scores['weighted_score']
            );
            $ssStmt->execute();
            $ssStmt->close();
        }

        // 3. Replace question responses — delete old, insert new
        $delKpiStmt = $conn->prepare("DELETE FROM appraisal_kpi_responses WHERE appraisal_id = ?");
        $delKpiStmt->bind_param("i", $appraisalId);
        $delKpiStmt->execute();
        $delKpiStmt->close();

        $delGenStmt = $conn->prepare("DELETE FROM appraisal_section_responses WHERE appraisal_id = ?");
        $delGenStmt->bind_param("i", $appraisalId);
        $delGenStmt->execute();
        $delGenStmt->close();

        foreach ($sections as $sec) {
            $sectionId = (int) $sec['section_id'];
            $section   = $sectionMap[$sectionId];

            foreach ($sec['responses'] as $resp) {
                $qId    = (int) $resp['question_id'];
                $rating = (float) $resp['rating'];

                if ($section['type'] === 'kpi') {
                    $qTxt = $conn->prepare("SELECT question_text FROM kpi_questions WHERE id = ? LIMIT 1");
                    $qTxt->bind_param("i", $qId);
                    $qTxt->execute();
                    $qTxtRow      = $qTxt->get_result()->fetch_assoc();
                    $questionText = $qTxtRow ? $qTxtRow['question_text'] : '';
                    $qTxt->close();

                    $qrStmt = $conn->prepare("
                        INSERT INTO appraisal_kpi_responses
                          (appraisal_id, section_id, kpi_question_id, question_text, rating)
                        VALUES (?,?,?,?,?)
                    ");
                    $qrStmt->bind_param("iiiss", $appraisalId, $sectionId, $qId, $questionText, $rating);
                    $qrStmt->execute();
                    $qrStmt->close();
                } else {
                    $qTxt = $conn->prepare("SELECT question_text FROM general_questions WHERE id = ? LIMIT 1");
                    $qTxt->bind_param("i", $qId);
                    $qTxt->execute();
                    $qTxtRow      = $qTxt->get_result()->fetch_assoc();
                    $questionText = $qTxtRow ? $qTxtRow['question_text'] : '';
                    $qTxt->close();

                    $qrStmt = $conn->prepare("
                        INSERT INTO appraisal_section_responses
                          (appraisal_id, section_id, general_question_id, question_text, rating)
                        VALUES (?,?,?,?,?)
                    ");
                    $qrStmt->bind_param("iiiss", $appraisalId, $sectionId, $qId, $questionText, $rating);
                    $qrStmt->execute();
                    $qrStmt->close();
                }
            }
        }

        // 4. Audit log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?,?,?,?,?,?)
        ");
        if ($logStmt) {
            $action      = "update_appraisal";
            $targetTable = "appraisals";
            $description = "{$loggedInUserEmail} updated appraisal ID {$appraisalId} for {$appraisal['staff_fullname']} — update #{$newUpdateCount}. New score: {$appraisalSummary} ({$evaluationStatement})";
            $logStmt->bind_param("iissis", $appraisal['company_id'], $loggedInUserId, $action, $targetTable, $appraisalId, $description);
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    // ── Send email to staff (optional) ────────────────────────────────────────
    $emailStatus = null;
    if ($sendEmail && !empty($appraisal['staff_email'])) {
        $supNameStmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1");
        $supNameStmt->bind_param("i", $appraisal['supervisor_id']);
        $supNameStmt->execute();
        $supNameRow     = $supNameStmt->get_result()->fetch_assoc();
        $supNameStmt->close();
        $supervisorName = $supNameRow
            ? $supNameRow['first_name'] . " " . $supNameRow['last_name']
            : 'Your Supervisor';

        $emailHtml = getAppraisalUpdatedEmail([
            'staff_name'           => $appraisal['staff_fullname'],
            'cycle_year'           => $appraisal['cycle_year'],
            'appraisal_summary'    => $appraisalSummary,
            'evaluation_statement' => $evaluationStatement,
            'section_scores'       => array_values($sectionScores),
            'company_name'         => $appraisal['company_name'],
            'company_color'        => '#1a3c5e',
            'app_url'              => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name'      => $supervisorName,
            'update_number'        => $newUpdateCount,
        ]);

        $mailResult  = sendMail(
            $appraisal['staff_email'],
            "Your {$appraisal['cycle_year']} Appraisal Has Been Updated — {$appraisal['company_name']}",
            $emailHtml,
            $appraisal['company_name']
        );
        $emailStatus = $mailResult === true ? 'sent' : 'failed: ' . $mailResult;
    }

    $updatesRemaining = $loggedInRole === 'supervisor' ? max(0, 2 - $newUpdateCount) : null;

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal updated successfully",
        "data"    => [
            "appraisal_id"         => $appraisalId,
            "staff_name"           => $appraisal['staff_fullname'],
            "kpi_rating"           => $kpiRating,
            "appraisal_summary"    => $appraisalSummary,
            "evaluation_statement" => $evaluationStatement,
            "section_scores"       => array_values($sectionScores),
            "update_count"         => $newUpdateCount,
            "updates_remaining"    => $updatesRemaining,
            "email_status"         => $emailStatus,
        ]
    ]);

} catch (Exception $e) {
    error_log("UpdateAppraisal Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}