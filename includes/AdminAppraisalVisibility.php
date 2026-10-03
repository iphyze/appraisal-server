<?php

/**
 * Admin-to-admin appraisal visibility.
 *
 * This permission only governs passive access by an Admin to an appraisal whose
 * appraisee is another Admin. It never removes access to:
 * - the Admin's own appraisal;
 * - an appraisal the Admin personally conducted as the recorded supervisor;
 * - non-Admin appraisal subjects already visible through the existing company /
 *   staff-scope rules.
 *
 * Super Admin configuration is stored in:
 * - admin_appraisal_visibility_policies
 * - admin_appraisal_visibility_targets
 */

function aavRoleKey($role): string
{
    return strtolower(str_replace(' ', '_', trim((string) $role)));
}

function aavValidateAlias(string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias provided.');
    }

    return $alias;
}

function adminAppraisalVisibilityMode(mysqli $conn, int $viewerAdminId, int $companyId): string
{
    if ($viewerAdminId <= 0 || $companyId <= 0) {
        return 'none';
    }

    $stmt = $conn->prepare("\n        SELECT visibility_mode\n        FROM admin_appraisal_visibility_policies\n        WHERE viewer_admin_id = ?\n          AND company_id = ?\n        LIMIT 1\n    ");

    if (!$stmt) {
        throw new Exception(
            'Admin appraisal visibility is not initialised. Run the admin appraisal visibility migration.',
            500
        );
    }

    $stmt->bind_param('ii', $viewerAdminId, $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $mode = strtolower(trim((string) ($row['visibility_mode'] ?? 'none')));

    return in_array($mode, ['none', 'all', 'selected'], true) ? $mode : 'none';
}

function adminAppraisalSubject(mysqli $conn, int $subjectUserId): ?array
{
    if ($subjectUserId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("\n        SELECT u.id, u.company_id, u.is_active, r.name AS role_name\n        FROM users u\n        INNER JOIN roles r ON r.id = u.role_id\n        WHERE u.id = ?\n        LIMIT 1\n    ");

    if (!$stmt) {
        throw new Exception('Unable to validate appraisal visibility.', 500);
    }

    $stmt->bind_param('i', $subjectUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function adminCanViewAppraisalSubject(
    mysqli $conn,
    int $viewerAdminId,
    int $viewerCompanyId,
    int $subjectUserId,
    int $recordedSupervisorId = 0
): bool {
    if ($viewerAdminId <= 0 || $viewerCompanyId <= 0 || $subjectUserId <= 0) {
        return false;
    }

    // An Admin must always retain access to their own appraisal and any appraisal
    // they personally conducted, including historical records after capability changes.
    if ($subjectUserId === $viewerAdminId || $recordedSupervisorId === $viewerAdminId) {
        return true;
    }

    $subject = adminAppraisalSubject($conn, $subjectUserId);

    // Historical appraisal snapshots must remain readable even if the source user
    // was later deleted or moved to another company. The caller already enforces
    // the appraisal record's company scope; without a same-company current Admin
    // record there is no peer-Admin restriction to apply.
    if (!$subject || (int) ($subject['company_id'] ?? 0) !== $viewerCompanyId) {
        return true;
    }

    // This feature only restricts appraisals whose current appraisee role is Admin.
    if (aavRoleKey($subject['role_name'] ?? '') !== 'admin') {
        return true;
    }

    $mode = adminAppraisalVisibilityMode($conn, $viewerAdminId, $viewerCompanyId);

    if ($mode === 'all') {
        return true;
    }

    if ($mode !== 'selected') {
        return false;
    }

    $stmt = $conn->prepare("\n        SELECT 1\n        FROM admin_appraisal_visibility_targets\n        WHERE viewer_admin_id = ?\n          AND target_admin_id = ?\n          AND company_id = ?\n        LIMIT 1\n    ");

    if (!$stmt) {
        throw new Exception(
            'Admin appraisal visibility is not initialised. Run the admin appraisal visibility migration.',
            500
        );
    }

    $stmt->bind_param('iii', $viewerAdminId, $subjectUserId, $viewerCompanyId);
    $stmt->execute();
    $allowed = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $allowed;
}

/**
 * SQL predicate for an appraisal-row alias. IDs are integer-cast before being
 * interpolated and aliases are validated, so the returned expression is safe to
 * compose into existing prepared or non-prepared queries.
 */
function adminAppraisalVisibilityWhereSql(
    int $viewerAdminId,
    int $companyId,
    string $appraisalAlias = 'ap'
): string {
    $appraisalAlias = aavValidateAlias($appraisalAlias);
    $viewerAdminId = (int) $viewerAdminId;
    $companyId = (int) $companyId;

    return "(\n        {$appraisalAlias}.staff_user_id = {$viewerAdminId}\n        OR {$appraisalAlias}.supervisor_id = {$viewerAdminId}\n        OR NOT EXISTS (\n            SELECT 1\n            FROM users aav_subject_user\n            INNER JOIN roles aav_subject_role ON aav_subject_role.id = aav_subject_user.role_id\n            WHERE aav_subject_user.id = {$appraisalAlias}.staff_user_id\n              AND aav_subject_user.company_id = {$companyId}\n              AND LOWER(REPLACE(TRIM(aav_subject_role.name), ' ', '_')) = 'admin'\n        )\n        OR EXISTS (\n            SELECT 1\n            FROM admin_appraisal_visibility_policies aav_policy_all\n            WHERE aav_policy_all.viewer_admin_id = {$viewerAdminId}\n              AND aav_policy_all.company_id = {$companyId}\n              AND aav_policy_all.visibility_mode = 'all'\n        )\n        OR EXISTS (\n            SELECT 1\n            FROM admin_appraisal_visibility_policies aav_policy_selected\n            INNER JOIN admin_appraisal_visibility_targets aav_target\n                ON aav_target.viewer_admin_id = aav_policy_selected.viewer_admin_id\n               AND aav_target.company_id = aav_policy_selected.company_id\n            WHERE aav_policy_selected.viewer_admin_id = {$viewerAdminId}\n              AND aav_policy_selected.company_id = {$companyId}\n              AND aav_policy_selected.visibility_mode = 'selected'\n              AND aav_target.target_admin_id = {$appraisalAlias}.staff_user_id\n        )\n    )";
}

/**
 * Predicate for Users queries where the candidate appraisee user and role are
 * already joined. This keeps Admin dashboard denominators aligned with the same
 * privacy rules used for appraisal records.
 */
function adminAppraiseeVisibilityWhereSql(
    int $viewerAdminId,
    int $companyId,
    string $userAlias = 'u',
    string $roleAlias = 'r',
    int $cycleId = 0
): string {
    $userAlias = aavValidateAlias($userAlias);
    $roleAlias = aavValidateAlias($roleAlias);
    $viewerAdminId = (int) $viewerAdminId;
    $companyId = (int) $companyId;

    return "(\n        {$userAlias}.id = {$viewerAdminId}\n        OR LOWER(REPLACE(TRIM({$roleAlias}.name), ' ', '_')) <> 'admin'\n        OR EXISTS (\n            SELECT 1\n            FROM supervisor_assignments aav_assignment\n            WHERE aav_assignment.staff_id = {$userAlias}.id\n              AND aav_assignment.supervisor_id = {$viewerAdminId}\n        )\n        OR EXISTS (\n            SELECT 1\n            FROM admin_appraisal_visibility_policies aav_policy_all_users\n            WHERE aav_policy_all_users.viewer_admin_id = {$viewerAdminId}\n              AND aav_policy_all_users.company_id = {$companyId}\n              AND aav_policy_all_users.visibility_mode = 'all'\n        )\n        OR EXISTS (\n            SELECT 1\n            FROM admin_appraisal_visibility_policies aav_policy_selected_users\n            INNER JOIN admin_appraisal_visibility_targets aav_target_users\n                ON aav_target_users.viewer_admin_id = aav_policy_selected_users.viewer_admin_id\n               AND aav_target_users.company_id = aav_policy_selected_users.company_id\n            WHERE aav_policy_selected_users.viewer_admin_id = {$viewerAdminId}\n              AND aav_policy_selected_users.company_id = {$companyId}\n              AND aav_policy_selected_users.visibility_mode = 'selected'\n              AND aav_target_users.target_admin_id = {$userAlias}.id\n        )\n    )";
}

/**
 * Restrictive predicate used by Global Search. Unlike the general appraisal
 * predicate, this expression only grants extra search visibility for other Admin
 * subjects; it does not broaden Global Search to every regular staff appraisal.
 */
function adminOtherAdminAppraisalGrantSql(
    int $viewerAdminId,
    int $companyId,
    string $appraisalAlias = 'ap'
): string {
    $appraisalAlias = aavValidateAlias($appraisalAlias);
    $viewerAdminId = (int) $viewerAdminId;
    $companyId = (int) $companyId;

    return "(\n        EXISTS (\n            SELECT 1\n            FROM users aav_search_subject\n            INNER JOIN roles aav_search_role ON aav_search_role.id = aav_search_subject.role_id\n            WHERE aav_search_subject.id = {$appraisalAlias}.staff_user_id\n              AND aav_search_subject.company_id = {$companyId}\n              AND LOWER(REPLACE(TRIM(aav_search_role.name), ' ', '_')) = 'admin'\n        )\n        AND (\n            EXISTS (\n                SELECT 1\n                FROM admin_appraisal_visibility_policies aav_search_all\n                WHERE aav_search_all.viewer_admin_id = {$viewerAdminId}\n                  AND aav_search_all.company_id = {$companyId}\n                  AND aav_search_all.visibility_mode = 'all'\n            )\n            OR EXISTS (\n                SELECT 1\n                FROM admin_appraisal_visibility_policies aav_search_selected\n                INNER JOIN admin_appraisal_visibility_targets aav_search_target\n                    ON aav_search_target.viewer_admin_id = aav_search_selected.viewer_admin_id\n                   AND aav_search_target.company_id = aav_search_selected.company_id\n                WHERE aav_search_selected.viewer_admin_id = {$viewerAdminId}\n                  AND aav_search_selected.company_id = {$companyId}\n                  AND aav_search_selected.visibility_mode = 'selected'\n                  AND aav_search_target.target_admin_id = {$appraisalAlias}.staff_user_id\n            )\n        )\n    )";
}
