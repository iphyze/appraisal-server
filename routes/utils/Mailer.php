<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Central email-address eligibility guard.
 *
 * Users without real email addresses must never receive an outbound email.
 * NULL/blank values and old generated placeholder addresses are skipped.
 */
function isDeliverableEmail($email): bool
{
    $email = strtolower(trim((string) $email));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);

    if (
        $domain === ''
        || $domain === 'invalid'
        || substr($domain, -8) === '.invalid'
    ) {
        return false;
    }

    if (
        strpos($email, 'legacy.') === 0
        || strpos($email, '@archive.invalid') !== false
    ) {
        return false;
    }

    return true;
}

/**
 * Simple mail diagnostic logger.
 *
 * Set MAIL_LOG_PATH in .env to a private writable path outside public_html,
 * for example:
 * MAIL_LOG_PATH="/home/your_cpanel_user/logs/lambert_mail.log"
 */
function logMailEvent(string $message, array $context = []): void
{
    $logPath = trim((string) ($_ENV['MAIL_LOG_PATH'] ?? ''));

    $line = sprintf(
        "[%s] %s %s%s",
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    if ($logPath !== '') {
        $directory = dirname($logPath);

        if (is_dir($directory) && is_writable($directory)) {
            error_log($line, 3, $logPath);
            return;
        }
    }

    error_log(trim($line));
}

/**
 * Central SMTP email sender used by the portal.
 *
 * Return values:
 * - true: email submitted successfully.
 * - skipped_no_deliverable_email: user has no real deliverable address.
 * - error string: SMTP failed.
 */
function sendMail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $fromName = null,
    array $cc = []
): bool|string {
    $to = trim($to);

    if (!isDeliverableEmail($to)) {
        logMailEvent('Mail skipped: recipient has no deliverable email.', [
            'recipient' => $to === '' ? '[empty]' : $to,
            'subject'   => $subject,
        ]);

        return 'skipped_no_deliverable_email';
    }

    $smtpHost = trim((string) ($_ENV['SMTP_HOST'] ?? ''));
    $smtpPort = (int) ($_ENV['SMTP_PORT'] ?? 465);
    $smtpUser = trim((string) ($_ENV['SMTP_USER'] ?? ''));
    $smtpPass = (string) ($_ENV['SMTP_PASS'] ?? '');
    $fromName = $fromName ?? (
        $_ENV['SMTP_FROM_NAME'] ?? 'Lambert Electromec Appraisal System'
    );

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '') {
        logMailEvent('Mail failed: SMTP credentials are incomplete.', [
            'smtp_host_set' => $smtpHost !== '',
            'smtp_user_set' => $smtpUser !== '',
            'smtp_pass_set' => $smtpPass !== '',
        ]);

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

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 30;

        /*
         * Keep the same behaviour as the SMTP setup that already works.
         * Do not force STARTTLS here until the SMTP host configuration
         * has been confirmed to support it successfully.
         */
        $mail->SMTPDebug = 0;

        /*
         * This matches your currently working server setup.
         * For final production hardening, your mail host should have a
         * verifiable TLS certificate so these exceptions can be removed.
         */
        // $mail->SMTPOptions = [
        //     'ssl' => [
        //         'verify_peer'       => false,
        //         'verify_peer_name'  => false,
        //         'allow_self_signed' => true,
        //     ],
        // ];

        $mail->setFrom($smtpUser, $fromName);
        $mail->addReplyTo($smtpUser, $fromName);
        $mail->addAddress($to);

        $seenCc = [];
        foreach ($cc as $ccAddress) {
            $ccAddress = strtolower(trim((string) $ccAddress));

            if (
                $ccAddress === ''
                || $ccAddress === strtolower($to)
                || isset($seenCc[$ccAddress])
                || !isDeliverableEmail($ccAddress)
            ) {
                continue;
            }

            $mail->addCC($ccAddress);
            $seenCc[$ccAddress] = true;
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>'],
            "\n",
            $htmlBody
        )));

        logMailEvent('Attempting email delivery.', [
            'recipient' => $to,
            'subject'   => $subject,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'cc_count'   => count($seenCc),
        ]);

        $mail->send();

        logMailEvent('Email submitted successfully.', [
            'recipient' => $to,
            'subject'   => $subject,
        ]);

        return true;

    } catch (Exception $e) {
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();

        logMailEvent('Email delivery failed.', [
            'recipient' => $to,
            'subject'   => $subject,
            'error'     => $error,
        ]);

        return $error;
    }
}