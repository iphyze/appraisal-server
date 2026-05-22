<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    foreach (['staff_user_id', 'sections'] as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $staffUserId = (int) $data['staff_user_id'];
    $sections    = $data['sections'];
    $sendEmail   = isset($data['send_email']) ? (bool) $data['send_email'] : true;

    if (!is_array($sections) || count($sections) === 0) {
        throw new Exception("'sections' must be a non-empty array.", 400);
    }

    // ── Validate staff ────────────────────────────────────────────────────────
    $staffStmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.department,
               u.job_title, u.staff_type, u.location, u.date_of_joining,
               u.unique_ref, u.company_id
        FROM users u WHERE u.id = ? LIMIT 1
    ");
    $staffStmt->bind_param("i", $staffUserId);
    $staffStmt->execute();
    $staff = $staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();

    if (!$staff) throw new Exception("Staff member not found.", 404);

    if (
        $loggedInRole !== 'super_admin' &&
        (int) $staff['company_id'] !== $loggedInCompanyId
    ) {
        throw new Exception("Unauthorized: Staff member does not belong to your company.", 403);
    }

    // ── Active cycle ──────────────────────────────────────────────────────────
    $cycleStmt = $conn->prepare("
        SELECT id, year, title FROM appraisal_cycles
        WHERE company_id = ? AND is_active = 1 LIMIT 1
    ");
    $cycleStmt->bind_param("i", $staff['company_id']);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) throw new Exception("No active appraisal cycle found.", 400);

    // ── Supervisor check ──────────────────────────────────────────────────────
    if ($loggedInRole === 'supervisor') {
        $assignStmt = $conn->prepare("
            SELECT id FROM supervisor_assignments
            WHERE supervisor_id = ? AND staff_id = ? AND cycle_id = ? LIMIT 1
        ");
        $assignStmt->bind_param("iii", $loggedInUserId, $staffUserId, $cycle['id']);
        $assignStmt->execute();
        if ($assignStmt->get_result()->num_rows === 0) {
            throw new Exception("This staff member is not assigned to you in the current cycle.", 403);
        }
        $assignStmt->close();
        $supervisorId = $loggedInUserId;
    } else {
        $supStmt = $conn->prepare("
            SELECT supervisor_id FROM supervisor_assignments
            WHERE staff_id = ? AND cycle_id = ? LIMIT 1
        ");
        $supStmt->bind_param("ii", $staffUserId, $cycle['id']);
        $supStmt->execute();
        $supRow       = $supStmt->get_result()->fetch_assoc();
        $supStmt->close();
        $supervisorId = $supRow ? (int) $supRow['supervisor_id'] : $loggedInUserId;
    }

    // ── Fetch supervisor name ─────────────────────────────────────────────────
    $supNameStmt = $conn->prepare("
        SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1
    ");
    $supNameStmt->bind_param("i", $supervisorId);
    $supNameStmt->execute();
    $supNameRow   = $supNameStmt->get_result()->fetch_assoc();
    $supNameStmt->close();
    $supervisorName = $supNameRow
        ? $supNameRow['first_name'] . " " . $supNameRow['last_name']
        : 'Your Supervisor';

    // ── Duplicate check ───────────────────────────────────────────────────────
    $dupStmt = $conn->prepare("
        SELECT id FROM appraisals WHERE staff_user_id = ? AND cycle_id = ? LIMIT 1
    ");
    $dupStmt->bind_param("ii", $staffUserId, $cycle['id']);
    $dupStmt->execute();
    if ($dupStmt->get_result()->num_rows > 0) {
        throw new Exception(
            "This staff member has already been appraised for the {$cycle['year']} cycle.",
            400
        );
    }
    $dupStmt->close();

    // ── Fetch cycle sections ──────────────────────────────────────────────────
    $secStmt = $conn->prepare("
        SELECT id, code, label, type, weight FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ? AND is_active = 1
        ORDER BY sort_order ASC
    ");
    $secStmt->bind_param("ii", $cycle['id'], $staff['company_id']);
    $secStmt->execute();
    $cycleSections = $secStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $secStmt->close();

    if (empty($cycleSections)) {
        throw new Exception("No active sections found for the current cycle.", 400);
    }

    $sectionMap = array_column($cycleSections, null, 'id');

    // ── Validate sections ─────────────────────────────────────────────────────
    foreach ($sections as $sec) {
        if (!isset($sec['section_id'], $sec['responses'])) {
            throw new Exception("Each section must have 'section_id' and 'responses'.", 400);
        }
        if (!isset($sectionMap[(int) $sec['section_id']])) {
            throw new Exception("Section ID {$sec['section_id']} is not valid for this cycle.", 400);
        }
        if (!is_array($sec['responses']) || count($sec['responses']) === 0) {
            throw new Exception("Section {$sec['section_id']} must have at least one response.", 400);
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

    // ── Calculate scores ──────────────────────────────────────────────────────
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

    $durationYears = null;
    if ($staff['date_of_joining']) {
        $diff          = (new DateTime($staff['date_of_joining']))->diff(new DateTime());
        $durationYears = round($diff->y + ($diff->m / 12), 1);
    }

    $staffFullname = $staff['first_name'] . " " . $staff['last_name'];

    // ── Fetch company for email branding ──────────────────────────────────────
    $companyStmt = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
    $companyStmt->bind_param("i", $staff['company_id']);
    $companyStmt->execute();
    $company     = $companyStmt->get_result()->fetch_assoc();
    $companyStmt->close();
    $companyName = $company ? $company['name'] : 'Lambert Electromec Ltd';

    // ── Transaction ───────────────────────────────────────────────────────────
    $conn->begin_transaction();
    try {

        // 1. Insert appraisal
        $aprStmt = $conn->prepare("
            INSERT INTO appraisals (
                company_id, cycle_id, staff_user_id, supervisor_id,
                staff_fullname, staff_department, staff_location,
                staff_job_title, staff_type, staff_email,
                date_of_joining, duration_years,
                kpi_rating, appraisal_summary,
                evaluation_statement, status,
                created_by, updated_by
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending',?,?)
        ");
        if (!$aprStmt) throw new Exception("Database error: " . $conn->error, 500);

        $aprStmt->bind_param(
            "iiiiissssssdddssii",
            $staff['company_id'], $cycle['id'],
            $staffUserId, $supervisorId,
            $staffFullname, $staff['department'], $staff['location'],
            $staff['job_title'], $staff['staff_type'], $staff['email'],
            $staff['date_of_joining'], $durationYears,
            $kpiRating, $appraisalSummary, $evaluationStatement,
            $loggedInUserId, $loggedInUserId
        );
        if (!$aprStmt->execute()) {
            throw new Exception("Failed to create appraisal: " . $aprStmt->error, 500);
        }
        $appraisalId = $aprStmt->insert_id;
        $aprStmt->close();

        // 2. Section scores + per-question responses
        foreach ($sections as $sec) {
            $sectionId = (int) $sec['section_id'];
            $section   = $sectionMap[$sectionId];
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

        // 3. Audit log
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?,?,?,?,?,?)
        ");
        if ($logStmt) {
            $action      = "create_appraisal";
            $targetTable = "appraisals";
            $description = "{$loggedInUserEmail} appraised {$staffFullname} — {$cycle['year']}. Score: {$appraisalSummary} ({$evaluationStatement})";
            $logStmt->bind_param("iissis", $staff['company_id'], $loggedInUserId, $action, $targetTable, $appraisalId, $description);
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

    // ── Send email to staff (outside transaction so DB is committed first) ────
    $emailStatus = null;
    if ($sendEmail && !empty($staff['email'])) {
        $emailHtml = getAppraisalSubmittedEmail([
            'staff_name'           => $staffFullname,
            'cycle_year'           => $cycle['year'],
            'evaluation_statement' => $evaluationStatement,
            'appraisal_summary'    => $appraisalSummary,
            'unique_ref'           => $staff['unique_ref'] ?? '',
            'company_name'         => $companyName,
            'company_color'        => '#1a3c5e',
            'app_url'              => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name'      => $supervisorName,
            'section_scores'       => array_values($sectionScores),
        ]);

        $mailResult  = sendMail(
            $staff['email'],
            "Your {$cycle['year']} Appraisal Has Been Submitted — {$companyName}",
            $emailHtml,
            $companyName
        );
        $emailStatus = $mailResult === true ? 'sent' : 'failed: ' . $mailResult;
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Appraisal submitted successfully",
        "data"    => [
            "appraisal_id"         => $appraisalId,
            "staff_name"           => $staffFullname,
            "cycle_year"           => $cycle['year'],
            "kpi_rating"           => $kpiRating,
            "appraisal_summary"    => $appraisalSummary,
            "evaluation_statement" => $evaluationStatement,
            "section_scores"       => array_values($sectionScores),
            "status"               => "Pending",
            "email_status"         => $emailStatus,
        ]
    ]);

} catch (Exception $e) {
    error_log("CreateAppraisal Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}