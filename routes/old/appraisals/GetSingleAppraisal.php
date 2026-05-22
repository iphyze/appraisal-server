<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData       = authenticateUser();
    $loggedInUserId = (int) $userData['id'];
    $loggedInRole   = $userData['role'];

    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'.", 400);
    }

    $appraisalId = (int) $_GET['id'];

    // Fetch appraisal
    $stmt = $conn->prepare("
        SELECT
            ap.*,
            ac.year  AS cycle_year,
            ac.title AS cycle_title,
            c.code   AS company_code,
            c.name   AS company_name,
            CONCAT(sup.first_name,' ',sup.last_name) AS supervisor_name,
            sup.email                                 AS supervisor_email,
            sup.department                            AS supervisor_department
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id  = ap.cycle_id
        INNER JOIN companies c         ON c.id   = ap.company_id
        LEFT  JOIN users sup           ON sup.id = ap.supervisor_id
        WHERE ap.id = ? LIMIT 1
    ");
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    $stmt->bind_param("i", $appraisalId);
    $stmt->execute();
    $appraisal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$appraisal) throw new Exception("Appraisal not found.", 404);

    // ── Access control ────────────────────────────────────────────────────────
    if ($loggedInRole === 'staff') {
        if ((int) $appraisal['staff_user_id'] !== $loggedInUserId) {
            throw new Exception("Unauthorized: You can only view your own appraisal.", 403);
        }
    } elseif ($loggedInRole === 'supervisor') {
        if ((int) $appraisal['supervisor_id'] !== $loggedInUserId) {
            throw new Exception("Unauthorized: You can only view appraisals you conducted.", 403);
        }
    } elseif ($loggedInRole === 'admin') {
        if ((int) $appraisal['company_id'] !== (int) $userData['company_id']) {
            throw new Exception("Unauthorized: This appraisal does not belong to your company.", 403);
        }
    }

    // ── Fetch section scores ──────────────────────────────────────────────────
    $scoresStmt = $conn->prepare("
        SELECT section_code, section_label, section_weight,
               section_avg, weighted_score
        FROM appraisal_section_scores
        WHERE appraisal_id = ?
        ORDER BY section_code ASC
    ");
    $scoresStmt->bind_param("i", $appraisalId);
    $scoresStmt->execute();
    $sectionScores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $scoresStmt->close();

    // ── Fetch KPI responses ───────────────────────────────────────────────────
    $kpiStmt = $conn->prepare("
        SELECT kpi_question_id, question_text, rating
        FROM appraisal_kpi_responses
        WHERE appraisal_id = ?
        ORDER BY kpi_question_id ASC
    ");
    $kpiStmt->bind_param("i", $appraisalId);
    $kpiStmt->execute();
    $kpiResponses = $kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $kpiStmt->close();

    // ── Fetch general section responses ───────────────────────────────────────
    $genStmt = $conn->prepare("
        SELECT asr.section_id, s.code AS section_code, s.label AS section_label,
               asr.general_question_id, asr.question_text, asr.rating
        FROM appraisal_section_responses asr
        INNER JOIN appraisal_sections s ON s.id = asr.section_id
        WHERE asr.appraisal_id = ?
        ORDER BY s.sort_order ASC, asr.general_question_id ASC
    ");
    $genStmt->bind_param("i", $appraisalId);
    $genStmt->execute();
    $generalResponses = $genStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $genStmt->close();

    // Group general responses by section
    $groupedGeneral = [];
    foreach ($generalResponses as $r) {
        $key = $r['section_code'];
        if (!isset($groupedGeneral[$key])) {
            $groupedGeneral[$key] = [
                'section_id'    => $r['section_id'],
                'section_code'  => $r['section_code'],
                'section_label' => $r['section_label'],
                'responses'     => [],
            ];
        }
        $groupedGeneral[$key]['responses'][] = [
            'question_id'   => $r['general_question_id'],
            'question_text' => $r['question_text'],
            'rating'        => $r['rating'],
        ];
    }

    $appraisal['section_scores']      = $sectionScores;
    $appraisal['kpi_responses']       = $kpiResponses;
    $appraisal['general_responses']   = array_values($groupedGeneral);

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal fetched successfully",
        "data"    => $appraisal
    ]);

} catch (Exception $e) {
    error_log("GetSingleAppraisal Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
