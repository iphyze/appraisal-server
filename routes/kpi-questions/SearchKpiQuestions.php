<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData     = authenticateUser();
    $companyScope = resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'kq');

    $search       = isset($_GET['search'])        ? trim($_GET['search'])        : '';
    $sectionId    = isset($_GET['section_id'])     ? (int) $_GET['section_id']   : null;
    $department   = isset($_GET['department'])     ? trim($_GET['department'])    : null;
    $supervisorId = isset($_GET['supervisor_id'])  ? (int)$_GET['supervisor_id'] : null;
    $staffUserId  = isset($_GET['staff_user_id'])  ? (int)$_GET['staff_user_id'] : null;

    $conditions = ["1=1", "kq.is_active = 1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "kq.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }

    if ($sectionId) {
        $conditions[] = "kq.section_id = ?";
        $params[]     = $sectionId;
        $types       .= "i";
    }

    if ($department) {
        $conditions[] = "kq.department = ?";
        $params[]     = $department;
        $types       .= "s";
    }

    /**
     * Scope resolution — fetch questions at the most specific level available:
     *   1. staff_user_id match (individual)
     *   2. supervisor_id match (supervisor-specific)
     *   3. both NULL (departmental default)
     * If staff_user_id is passed, we fetch individual + supervisor + dept questions
     * so the caller can decide which set to use.
     */
    if ($staffUserId) {
        $conditions[] = "(kq.staff_user_id = ? OR kq.supervisor_id = ? OR (kq.staff_user_id IS NULL AND kq.supervisor_id IS NULL))";
        $params[]     = $staffUserId;
        $params[]     = $supervisorId ?? 0;
        $types       .= "ii";
    } elseif ($supervisorId) {
        $conditions[] = "(kq.supervisor_id = ? OR kq.supervisor_id IS NULL)";
        $params[]     = $supervisorId;
        $types       .= "i";
    }

    if (!empty($search)) {
        $conditions[] = "kq.question_text LIKE ?";
        $params[]     = "%" . $search . "%";
        $types       .= "s";
    }

    $sql = "
        SELECT
            kq.id,
            kq.department,
            kq.question_text,
            kq.sort_order,
            s.id    AS section_id,
            s.code  AS section_code,
            s.label AS section_label,
            ac.year AS cycle_year,
            kq.supervisor_id,
            kq.staff_user_id,
            CASE
                WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                ELSE 'department'
            END AS scope
        FROM kpi_questions kq
        INNER JOIN appraisal_sections s ON s.id  = kq.section_id
        INNER JOIN appraisal_cycles ac  ON ac.id = s.cycle_id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY
            CASE
                WHEN kq.staff_user_id IS NOT NULL THEN 1
                WHEN kq.supervisor_id IS NOT NULL THEN 2
                ELSE 3
            END ASC,
            kq.sort_order ASC
        LIMIT 100
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "KPI questions fetched successfully",
        "data"    => $data,
        "meta"    => [
            "count"         => count($data),
            "section_id"    => $sectionId,
            "department"    => $department,
            "supervisor_id" => $supervisorId,
            "staff_user_id" => $staffUserId,
            "search"        => $search ?: null,
        ]
    ]);

} catch (Exception $e) {
    error_log("SearchKpiQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
