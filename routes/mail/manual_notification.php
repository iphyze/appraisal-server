<?php

/**
 * Email template: Manual notification
 * Used by super_admin to send custom notifications to staff or supervisors.
 *
 * Available variables:
 *   $vars['recipient_name']   - Full name of recipient
 *   $vars['recipient_type']   - 'staff' or 'supervisor'
 *   $vars['custom_message']   - The admin's custom message body (optional)
 *   $vars['cycle_year']       - Appraisal year
 *   $vars['unique_ref']       - Staff unique_ref/login ID (staff only)
 *   $vars['company_name']
 *   $vars['company_color']
 *   $vars['app_url']
 *   $vars['sender_name']      - Name of the admin sending the email
 */

function getManualNotificationEmail(array $vars): string
{
    $recipientName  = htmlspecialchars($vars['recipient_name'] ?? '');
    $recipientType  = $vars['recipient_type'] ?? 'staff';
    $customMessage  = nl2br(htmlspecialchars($vars['custom_message'] ?? ''));
    $cycleYear      = htmlspecialchars($vars['cycle_year'] ?? date('Y'));
    $uniqueRef      = htmlspecialchars($vars['unique_ref'] ?? '');
    $companyName    = htmlspecialchars($vars['company_name'] ?? 'Lambert Electromec Ltd');
    $companyColor   = $vars['company_color'] ?? '#1a3c5e';
    $appUrl         = $vars['app_url'] ?? '#';
    $senderName     = htmlspecialchars($vars['sender_name'] ?? 'HR Administration');

    // For staff — show login credentials block
    $credentialsBlock = '';
    if ($recipientType === 'staff' && !empty($uniqueRef)) {
        $credentialsBlock = "
            <tr>
                <td style='padding:0 40px 28px 40px;'>
                    <p style='margin:0 0 12px 0; font-size:13px; font-weight:700; color:#1a1a2e; text-transform:uppercase; letter-spacing:1px;'>Your Portal Access</p>
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f9fc; border-radius:8px; border:1px solid #eee;'>
                        <tr>
                            <td style='padding:14px 20px; border-bottom:1px solid #eee;'>
                                <span style='font-size:12px; color:#888; display:block; margin-bottom:2px;'>Unique ID / Password</span>
                                <span style='font-size:16px; font-weight:700; color:#1a1a2e; letter-spacing:1px;'>{$uniqueRef}</span>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding:14px 20px;'>
                                <span style='font-size:12px; color:#888; display:block; margin-bottom:2px;'>Portal Link</span>
                                <a href='{$appUrl}' style='font-size:14px; color:{$companyColor}; font-weight:600; text-decoration:none;'>{$appUrl}</a>
                            </td>
                        </tr>
                    </table>
                    <p style='margin:12px 0 0 0; font-size:12px; color:#aaa;'>
                        Please ensure you acknowledge your appraisal after logging in. This is strictly required.
                    </p>
                </td>
            </tr>
        ";
    }

    // Custom message block
    $messageBlock = '';
    if (!empty($customMessage)) {
        $messageBlock = "
            <tr>
                <td style='padding:0 40px 28px 40px;'>
                    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f8f9fc; border-left:4px solid {$companyColor}; border-radius:0 8px 8px 0;'>
                        <tr>
                            <td style='padding:20px 24px;'>
                                <p style='margin:0 0 6px 0; font-size:11px; color:#aaa; text-transform:uppercase; letter-spacing:1px; font-weight:600;'>Message from {$senderName}</p>
                                <p style='margin:0; font-size:14px; color:#333; line-height:24px;'>{$customMessage}</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        ";
    }

    return "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Appraisal Notification — {$cycleYear}</title>
</head>
<body style='margin:0; padding:0; background-color:#f4f6f9; font-family: Arial, Helvetica, sans-serif;'>

    <table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f4f6f9; padding:40px 0;'>
        <tr>
            <td align='center'>
                <table width='600' cellpadding='0' cellspacing='0' style='background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08); max-width:600px; width:100%;'>

                    <!-- Header -->
                    <tr>
                        <td style='background-color:{$companyColor}; padding:36px 40px; text-align:center;'>
                            <p style='margin:0 0 4px 0; color:rgba(255,255,255,0.75); font-size:12px; letter-spacing:2px; text-transform:uppercase;'>Annual Performance Review</p>
                            <h1 style='margin:0; color:#ffffff; font-size:26px; font-weight:700; letter-spacing:-0.5px;'>{$companyName}</h1>
                            <p style='margin:8px 0 0 0; color:rgba(255,255,255,0.85); font-size:14px;'>Staff Appraisal — {$cycleYear}</p>
                        </td>
                    </tr>

                    <!-- Greeting -->
                    <tr>
                        <td style='padding:36px 40px 24px 40px;'>
                            <p style='margin:0 0 16px 0; font-size:18px; font-weight:600; color:#1a1a2e;'>Hello, {$recipientName} 👋</p>
                            <p style='margin:0; font-size:14px; color:#555; line-height:24px;'>
                                You have received this notification from the {$companyName} HR Administration
                                regarding the <strong>{$cycleYear}</strong> Staff Appraisal exercise.
                            </p>
                        </td>
                    </tr>

                    {$messageBlock}

                    {$credentialsBlock}

                    <!-- CTA -->
                    <tr>
                        <td style='padding:0 40px 36px 40px; text-align:center;'>
                            <a href='{$appUrl}' style='display:inline-block; background-color:{$companyColor}; color:#ffffff; text-decoration:none; padding:14px 36px; border-radius:8px; font-size:14px; font-weight:600; letter-spacing:0.5px;'>
                                Access Appraisal Portal
                            </a>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style='background:#f8f9fc; padding:20px 40px; border-top:1px solid #eee; text-align:center;'>
                            <p style='margin:0; font-size:12px; color:#aaa; line-height:20px;'>
                                &copy; {$cycleYear} {$companyName} &bull; Staff Appraisal System<br>
                                This is an automated notification. Please do not reply to this email.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
";
}
