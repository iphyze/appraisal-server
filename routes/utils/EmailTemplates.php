<?php

/**
 * Lambert Electromec Staff Appraisal email templates.
 * Branded with the portal green and intentionally excludes appraisal scores
 * from staff email notifications. Staff must log in to view the secured report.
 */

function _portalGreen(): string
{
    return '#3da050';
}

function _safe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function _emailWrapper(string $headerTag, string $body, string $companyName, string $companyColor, string $cycleYear): string
{
    $company = _safe($companyName);
    $tag = _safe($headerTag);
    $year = _safe($cycleYear);
    $color = $companyColor ?: _portalGreen();

    return "<!DOCTYPE html>
<html lang='en'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0;padding:0;background:#f4f7f5;font-family:Arial,Helvetica,sans-serif;color:#111827;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f7f5;padding:38px 14px;'>
<tr><td align='center'>
<table width='620' cellpadding='0' cellspacing='0' style='max-width:620px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 12px 36px rgba(15,23,42,.08);'>
<tr><td style='background:{$color};padding:34px 38px;text-align:left;'>
<p style='margin:0 0 8px;color:rgba(255,255,255,.82);font-size:11px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;'>Annual Performance Review</p>
<h1 style='margin:0;color:#fff;font-size:25px;font-weight:700;'>{$company}</h1>
<p style='margin:10px 0 0;color:rgba(255,255,255,.92);font-size:14px;'>{$tag} &bull; {$year}</p>
</td></tr>
{$body}
<tr><td style='background:#f8faf9;border-top:1px solid #e5e7eb;padding:22px 38px;text-align:center;'>
<p style='margin:0;color:#6b7280;font-size:12px;line-height:20px;'>&copy; {$year} {$company} &bull; Staff Appraisal System<br>This is an automated notification. Please do not reply to this email.</p>
</td></tr>
</table>
</td></tr></table>
</body></html>";
}

function _ctaButton(string $url, string $label, string $companyColor, string $note = ''): string
{
    $safeUrl = _safe($url);
    $safeLabel = _safe($label);
    $noteHtml = $note !== '' ? "<p style='margin:15px 0 0;color:#6b7280;font-size:12px;line-height:19px;'>" . _safe($note) . "</p>" : '';
    return "<tr><td style='padding:0 38px 34px;text-align:center;'>
      <a href='{$safeUrl}' style='display:inline-block;background:{$companyColor};border-radius:12px;color:#fff;text-decoration:none;padding:15px 30px;font-size:14px;font-weight:700;'>{$safeLabel}</a>
      {$noteHtml}
    </td></tr>";
}

function _portalAccessBlock(string $accountEmail, string $appUrl, string $companyColor, string $cycleYear): string
{
    $email = _safe($accountEmail ?: 'your registered company email');
    $url = _safe($appUrl);
    $password = _safe('Lambert@' . date('Y'));
    return "<tr><td style='padding:0 38px 25px;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #dceee0;background:#f6fbf7;border-radius:14px;'>
        <tr><td style='padding:19px 20px;'>
          <p style='margin:0 0 9px;color:{$companyColor};font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;'>Secure Portal Access</p>
          <p style='margin:0 0 8px;color:#374151;font-size:13.5px;line-height:22px;'>Log in through the portal using your email address: <strong style='color:#111827;'>{$email}</strong>.</p>
          <p style='margin:0 0 8px;color:#374151;font-size:13.5px;line-height:22px;'>If you have not logged in before, use the temporary password <strong style='color:#111827;'>{$password}</strong>. You will be prompted to set your own password immediately after login.</p>
          <p style='margin:0;color:#374151;font-size:13.5px;line-height:22px;'>If you already updated your password, sign in with your existing password.</p>
          <p style='margin:11px 0 0;color:#6b7280;font-size:12px;'><a href='{$url}' style='color:{$companyColor};font-weight:700;text-decoration:none;'>{$url}</a></p>
        </td></tr>
      </table>
    </td></tr>";
}

function getAppraisalSubmittedEmail(array $vars): string
{
    $staffName = _safe($vars['staff_name'] ?? 'Team Member');
    $supervisorName = _safe($vars['supervisor_name'] ?? 'your supervisor');
    $cycleYear = (string)($vars['cycle_year'] ?? date('Y'));
    $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
    $color = _portalGreen();
    $appUrl = $vars['app_url'] ?? '#';
    $staffEmail = $vars['staff_email'] ?? '';

    $body = "<tr><td style='padding:34px 38px 25px;'>
      <p style='margin:0 0 13px;color:#111827;font-size:19px;font-weight:700;'>Hello, {$staffName}</p>
      <p style='margin:0;color:#4b5563;font-size:14px;line-height:25px;'>Your performance appraisal for the <strong>{$cycleYear}</strong> appraisal cycle has been submitted by <strong>{$supervisorName}</strong>. For your privacy, appraisal details are available only within the secure portal.</p>
      <p style='margin:14px 0 0;color:#4b5563;font-size:14px;line-height:25px;'>Please log in, review your appraisal and submit your acknowledgement.</p>
    </td></tr>"
    . _portalAccessBlock($staffEmail, $appUrl, $color, $cycleYear)
    . _ctaButton($appUrl, 'Review My Appraisal', $color, 'Your acknowledgement is required after reviewing the appraisal.');

    return _emailWrapper('Appraisal Submitted', $body, $companyName, $color, $cycleYear);
}

function getAppraisalUpdatedEmail(array $vars): string
{
    $staffName = _safe($vars['staff_name'] ?? 'Team Member');
    $supervisorName = _safe($vars['supervisor_name'] ?? 'your supervisor');
    $cycleYear = (string)($vars['cycle_year'] ?? date('Y'));
    $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
    $color = _portalGreen();
    $appUrl = $vars['app_url'] ?? '#';
    $staffEmail = $vars['staff_email'] ?? '';
    $updateNumber = (int)($vars['update_number'] ?? 1);
    $finalText = $updateNumber >= 2
        ? ' This is the final permitted supervisor update; please review and acknowledge the latest version.'
        : ' Please review the changes and acknowledge the current version.';

    $body = "<tr><td style='padding:34px 38px 25px;'>
      <p style='margin:0 0 13px;color:#111827;font-size:19px;font-weight:700;'>Hello, {$staffName}</p>
      <p style='margin:0;color:#4b5563;font-size:14px;line-height:25px;'>Your performance appraisal for the <strong>{$cycleYear}</strong> cycle has been updated by <strong>{$supervisorName}</strong>.{$finalText}</p>
      <p style='margin:14px 0 0;color:#4b5563;font-size:14px;line-height:25px;'>Appraisal details remain protected and can only be viewed after logging into the portal.</p>
    </td></tr>"
    . _portalAccessBlock($staffEmail, $appUrl, $color, $cycleYear)
    . _ctaButton($appUrl, 'Review Updated Appraisal', $color, 'Log in securely to review your updated appraisal.');

    return _emailWrapper('Appraisal Updated', $body, $companyName, $color, $cycleYear);
}

function getFeedbackSubmittedEmail(array $vars): string
{
    $supervisorName = _safe($vars['supervisor_name'] ?? 'Supervisor');
    $staffName = _safe($vars['staff_name'] ?? 'Staff Member');
    $feedback = nl2br(_safe($vars['feedback'] ?? ''));
    $cycleYear = (string)($vars['cycle_year'] ?? date('Y'));
    $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
    $color = _portalGreen();
    $appUrl = $vars['app_url'] ?? '#';

    $body = "<tr><td style='padding:34px 38px 25px;'>
      <p style='margin:0 0 13px;color:#111827;font-size:19px;font-weight:700;'>Hello, {$supervisorName}</p>
      <p style='margin:0;color:#4b5563;font-size:14px;line-height:25px;'><strong>{$staffName}</strong> has acknowledged the {$cycleYear} appraisal and submitted feedback:</p>
    </td></tr>
    <tr><td style='padding:0 38px 28px;'>
      <div style='border-left:4px solid {$color};background:#f6fbf7;border-radius:0 12px 12px 0;padding:19px 20px;'>
        <p style='margin:0;color:#374151;font-size:14px;line-height:24px;'>{$feedback}</p>
      </div>
    </td></tr>"
    . _ctaButton($appUrl, 'View Appraisal Feedback', $color);

    return _emailWrapper('Feedback Received', $body, $companyName, $color, $cycleYear);
}

function getManualNotificationEmail(array $vars): string
{
    $recipientName = _safe($vars['recipient_name'] ?? 'Team Member');
    $customMessage = nl2br(_safe($vars['custom_message'] ?? ''));
    $cycleYear = (string)($vars['cycle_year'] ?? date('Y'));
    $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
    $color = _portalGreen();
    $appUrl = $vars['app_url'] ?? '#';
    $recipientType = $vars['recipient_type'] ?? 'staff';

    $body = "<tr><td style='padding:34px 38px 25px;'>
      <p style='margin:0 0 13px;color:#111827;font-size:19px;font-weight:700;'>Hello, {$recipientName}</p>
      <p style='margin:0;color:#4b5563;font-size:14px;line-height:25px;'>You have a message regarding the <strong>{$cycleYear}</strong> Lambert staff appraisal exercise.</p>
    </td></tr>";

    if ($customMessage !== '') {
        $body .= "<tr><td style='padding:0 38px 25px;'><div style='border-left:4px solid {$color};background:#f6fbf7;border-radius:0 12px 12px 0;padding:19px 20px;'><p style='margin:0;color:#374151;font-size:14px;line-height:24px;'>{$customMessage}</p></div></td></tr>";
    }

    if (!empty($vars['is_portal_user']) || in_array($recipientType, ['staff', 'supervisor'], true)) {
        $body .= _portalAccessBlock($vars['recipient_email'] ?? '', $appUrl, $color, $cycleYear);
    }
    $body .= _ctaButton($appUrl, 'Access Appraisal Portal', $color);

    return _emailWrapper('Appraisal Communication', $body, $companyName, $color, $cycleYear);
}
