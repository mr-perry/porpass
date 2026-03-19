<?php
/**
 * mailer.php — PHPMailer wrapper for PORPASS transactional email.
 *
 * Provides a configured PHPMailer instance and helper functions for
 * sending password reset emails. SMTP credentials are loaded from the
 * project .env file.
 *
 * Required .env variables:
 *   MAIL_HOST      — SMTP server hostname
 *   MAIL_PORT      — SMTP port (typically 587 for TLS)
 *   MAIL_USERNAME  — SMTP username
 *   MAIL_PASSWORD  — SMTP password
 *   MAIL_FROM      — From address (e.g. noreply@porpass.psi.edu)
 *   MAIL_FROM_NAME — From name (e.g. PORPASS)
 *   APP_URL        — Base URL of the application (e.g. https://porpass.psi.edu)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Create and return a configured PHPMailer instance.
 *
 * @return PHPMailer
 * @throws Exception If PHPMailer cannot be configured.
 */
function get_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $_ENV['MAIL_HOST']      ?? 'smtp.example.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $_ENV['MAIL_USERNAME']  ?? '';
    $mail->Password   = $_ENV['MAIL_PASSWORD']  ?? '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)($_ENV['MAIL_PORT'] ?? 587);
    $mail->setFrom(
        $_ENV['MAIL_FROM']      ?? 'noreply@example.com',
        $_ENV['MAIL_FROM_NAME'] ?? 'PORPASS'
    );
    $mail->isHTML(true);
    return $mail;
}

/**
 * Send a password reset email containing a tokenised reset link.
 *
 * @param string $to_email  Recipient email address.
 * @param string $to_name   Recipient display name.
 * @param string $token     The raw reset token (not hashed).
 *
 * @return bool True if the email was sent successfully, false otherwise.
 */
function send_password_reset(string $to_email, string $to_name, string $token): bool {
    $base_url  = rtrim($_ENV['APP_URL'] ?? 'http://porpass.local', '/');
    $reset_url = $base_url . '/auth/reset.php?token=' . urlencode($token);

    try {
        $mail = get_mailer();
        $mail->addAddress($to_email, $to_name);
        $mail->Subject = 'PORPASS — Password Reset Request';
        $mail->Body    = '
            <p>Hello ' . htmlspecialchars($to_name) . ',</p>
            <p>We received a request to reset your PORPASS password.
               Click the button below to choose a new password.
               This link will expire in <strong>15 minutes</strong>.</p>
            <p style="margin: 24px 0;">
                <a href="' . $reset_url . '"
                   style="background:#0d6efd;color:#fff;padding:10px 20px;
                          text-decoration:none;border-radius:4px;">
                    Reset Password
                </a>
            </p>
            <p>If you did not request a password reset, you can safely ignore this email.
               Your password will not be changed.</p>
            <p>— The PORPASS Team</p>
        ';
        $mail->AltBody = "Reset your PORPASS password by visiting:\n\n"
                       . $reset_url
                       . "\n\nThis link expires in 15 minutes. "
                       . "If you did not request this, ignore this email.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}
