<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Central email-address eligibility guard.
 *
 * A user without a real email address must never receive an outbound email.
 * NULL/blank values and any old archive.placeholder email addresses are skipped.
 */
function isDeliverableEmail($email): bool
{
    $email = strtolower(trim((string) $email));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);

    if ($domain === '' || $domain === 'invalid' || substr($domain, -8) === '.invalid') {
        return false;
    }

    if (strpos($email, 'legacy.') === 0 || strpos($email, '@archive.invalid') !== false) {
        return false;
    }

    return true;
}

/**
 * Central SMTP email sender used by the portal.
 * Every email-triggering route should pass through this function.
 */
function sendMail(string $to, string $subject, string $htmlBody, string $fromName = null): bool|string
{
    if (!isDeliverableEmail($to)) {
        return 'skipped_no_deliverable_email';
    }

    $smtpHost = $_ENV['SMTP_HOST'] ?? '';
    $smtpPort = (int) ($_ENV['SMTP_PORT'] ?? 587);
    $smtpUser = $_ENV['SMTP_USER'] ?? '';
    $smtpPass = $_ENV['SMTP_PASS'] ?? '';
    $fromName = $fromName ?? ($_ENV['SMTP_FROM_NAME'] ?? 'Lambert Electromec Appraisal System');

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        return 'SMTP credentials are not configured in .env';
    }

    $mail = null;

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;
        $mail->SMTPDebug = 0;

        if ($smtpPort === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($smtpUser, $fromName);
        $mail->addReplyTo($smtpUser, $fromName);
        $mail->addAddress(trim($to));
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));
        $mail->send();

        return true;
    } catch (Exception $e) {
        return $mail ? $mail->ErrorInfo : $e->getMessage();
    }
}
