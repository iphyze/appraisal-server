<?php

function mailRoleKey($role): string
{
    return strtolower(str_replace(' ', '_', trim((string) $role)));
}

function mailEsc($conn, $value): string
{
    return $conn->real_escape_string(trim((string) $value));
}

/**
 * SQL condition for a real recipient address.
 * Legacy .invalid placeholders and blank addresses must never appear in email recipients.
 */
function mailDeliverableWhere(string $expression): string
{
    return "NULLIF(TRIM({$expression}), '') IS NOT NULL"
        . " AND LOWER(TRIM({$expression})) NOT LIKE '%@archive.invalid'"
        . " AND LOWER(TRIM({$expression})) NOT LIKE 'legacy.%@%'";
}

function resolveMailCycle($conn, array $userData, int $cycleId): ?array
{
    if ($cycleId <= 0) {
        return null;
    }

    $role = mailRoleKey($userData['role'] ?? '');
    $companyId = (int) ($userData['company_id'] ?? 0);
    $companyScope = resolveCompanyScope($userData);
    $scope = $companyScope !== null ? " AND ac.company_id = " . (int) $companyScope : '';

    $result = $conn->query("
        SELECT ac.id, ac.year, ac.title, ac.company_id, c.name AS company_name
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        WHERE ac.id = {$cycleId}{$scope}
        LIMIT 1
    ");

    if (!$result) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $cycle = $result->fetch_assoc();

    if (!$cycle) {
        throw new Exception('Selected appraisal cycle was not found or is outside your scope.', 404);
    }

    return $cycle;
}

function fetchMailRecipients($conn, array $userData, string $audience, int $cycleId = 0, string $search = ''): array
{
    $role = mailRoleKey($userData['role'] ?? '');
    $companyId = (int) ($userData['company_id'] ?? 0);
    $companyScope = resolveCompanyScope($userData);
    $staffScope = (string) ($userData['staff_scope'] ?? 'All');
    $safeSearch = mailEsc($conn, $search);
    $cycleAudiences = ['pending_acknowledgements', 'pending_supervisors', 'cycle_supervisors'];

    $cycle = in_array($audience, $cycleAudiences, true)
        ? resolveMailCycle($conn, $userData, $cycleId)
        : ($cycleId > 0 ? resolveMailCycle($conn, $userData, $cycleId) : null);

    if (in_array($audience, $cycleAudiences, true) && !$cycle) {
        throw new Exception('Select an appraisal cycle first.', 400);
    }

    $cycleCompanyId = (int) ($cycle['company_id'] ?? $companyId);

    if ($audience === 'pending_acknowledgements') {
        $recipientExpr = "COALESCE(NULLIF(TRIM(ap.staff_email), ''), NULLIF(TRIM(u.email), ''))";
        $where = [
            "ap.cycle_id = {$cycleId}",
            "ap.status = 'Pending'",
            mailDeliverableWhere($recipientExpr),
            'u.is_active = 1',
        ];

        if ($role === 'admin') {
            $where[] = "ap.company_id = {$companyId}";
            if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
                $scopeEsc = mailEsc($conn, $staffScope);
                $where[] = "(LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) <> 'staff' OR ap.staff_type = '{$scopeEsc}')";
            }
        }

        if ($safeSearch !== '') {
            $where[] = "(ap.staff_fullname LIKE '%{$safeSearch}%' OR {$recipientExpr} LIKE '%{$safeSearch}%' OR ap.staff_department LIKE '%{$safeSearch}%')";
        }

        $sql = "SELECT
                    u.id AS recipient_id,
                    u.first_name,
                    u.last_name,
                    COALESCE(NULLIF(ap.staff_fullname, ''), CONCAT(u.first_name, ' ', u.last_name)) AS full_name,
                    {$recipientExpr} AS email,
                    LOWER(REPLACE(TRIM(subject_role.name), ' ', '_')) AS role,
                    CONCAT(COALESCE(ap.staff_department, 'No department'), ' • Pending acknowledgement') AS summary,
                    ap.id AS appraisal_id
                FROM appraisals ap
                INNER JOIN users u ON u.id = ap.staff_user_id
                INNER JOIN roles subject_role ON subject_role.id = u.role_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY full_name ASC';
    } elseif ($audience === 'pending_supervisors') {
        $where = [
            "sa.cycle_id = {$cycleId}",
            "sup.company_id = {$cycleCompanyId}",
            'sup.is_active = 1',
            mailDeliverableWhere('sup.email'),
            "LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor')",
        ];

        if ($safeSearch !== '') {
            $where[] = "(CONCAT(sup.first_name, ' ', sup.last_name) LIKE '%{$safeSearch}%' OR sup.email LIKE '%{$safeSearch}%')";
        }

        $sql = "SELECT
                    sup.id AS recipient_id,
                    sup.first_name,
                    sup.last_name,
                    CONCAT(sup.first_name, ' ', sup.last_name) AS full_name,
                    sup.email,
                    LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role,
                    COUNT(DISTINCT sa.staff_id) AS assigned_count,
                    COUNT(DISTINCT ap.staff_user_id) AS appraised_count,
                    (COUNT(DISTINCT sa.staff_id) - COUNT(DISTINCT ap.staff_user_id)) AS pending_count,
                    CONCAT((COUNT(DISTINCT sa.staff_id) - COUNT(DISTINCT ap.staff_user_id)), ' outstanding of ', COUNT(DISTINCT sa.staff_id), ' assigned') AS summary
                FROM supervisor_assignments sa
                INNER JOIN users sup ON sup.id = sa.supervisor_id
                INNER JOIN roles r ON r.id = sup.role_id
                LEFT JOIN appraisals ap
                    ON ap.cycle_id = sa.cycle_id
                   AND ap.staff_user_id = sa.staff_id
                   AND ap.supervisor_id = sa.supervisor_id
                WHERE " . implode(' AND ', $where) . '
                GROUP BY sup.id, sup.first_name, sup.last_name, sup.email, r.name
                HAVING pending_count > 0
                ORDER BY pending_count DESC, full_name ASC';
    } elseif ($audience === 'cycle_supervisors') {
        $where = [
            "sup.company_id = {$cycleCompanyId}",
            'sup.is_active = 1',
            mailDeliverableWhere('sup.email'),
            "LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin', 'supervisor')",
        ];

        if ($safeSearch !== '') {
            $where[] = "(CONCAT(sup.first_name, ' ', sup.last_name) LIKE '%{$safeSearch}%' OR sup.email LIKE '%{$safeSearch}%')";
        }

        $sql = "SELECT
                    sup.id AS recipient_id,
                    sup.first_name,
                    sup.last_name,
                    CONCAT(sup.first_name, ' ', sup.last_name) AS full_name,
                    sup.email,
                    LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role,
                    CONCAT(COUNT(DISTINCT sa.staff_id), ' assigned employees for cycle') AS summary
                FROM users sup
                INNER JOIN roles r ON r.id = sup.role_id
                LEFT JOIN supervisor_assignments sa
                    ON sa.supervisor_id = sup.id
                   AND sa.cycle_id = {$cycleId}
                WHERE " . implode(' AND ', $where) . '
                GROUP BY sup.id, sup.first_name, sup.last_name, sup.email, r.name
                ORDER BY full_name ASC';
    } elseif ($audience === 'specific_users') {
        $where = [
            'u.is_active = 1',
            mailDeliverableWhere('u.email'),
        ];

        if ($companyScope !== null) {
            $where[] = "u.company_id = " . (int) $companyScope;
        }

        if ($safeSearch !== '') {
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE '%{$safeSearch}%' OR u.email LIKE '%{$safeSearch}%' OR u.department LIKE '%{$safeSearch}%')";
        }

        if ($role === 'admin' && in_array($staffScope, ['Local', 'Expatriate'], true)) {
            $scopeEsc = mailEsc($conn, $staffScope);
            $where[] = "(LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'staff' OR u.staff_type = '{$scopeEsc}')";
        }

        $sql = "SELECT
                    u.id AS recipient_id,
                    u.first_name,
                    u.last_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                    u.email,
                    LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role,
                    CONCAT(COALESCE(u.department, 'No department'), ' • ', r.name) AS summary
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE " . implode(' AND ', $where) . '
                ORDER BY full_name ASC
                LIMIT 250';
    } else {
        throw new Exception('Unsupported recipient audience.', 400);
    }

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    return [
        'rows' => $result->fetch_all(MYSQLI_ASSOC),
        'cycle' => $cycle,
    ];
}
