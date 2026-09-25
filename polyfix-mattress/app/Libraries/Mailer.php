<?php

namespace App\Libraries;

/**
 * Outgoing email through the SMTP account in .env (email.*).
 *
 * A send failure is logged without the message body — a body can carry a
 * password-reset link, and a link in a log file is a link anyone with the log
 * can use.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        $config = config('Email');
        if (($config->SMTPHost ?? '') === '' || str_contains((string) $config->SMTPHost, 'example.com')) {
            log_message('warning', 'mail.NOT_CONFIGURED subject="{subject}" — set email.* in .env', ['subject' => $subject]);

            return false;
        }

        $email = service('email', null, false);
        $email->setFrom($config->fromEmail, $config->fromName ?: brand('name'));
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMailType('text');
        $email->setMessage($text);

        if (! $email->send(false)) {
            log_message('error', 'mail.SEND_FAILED subject="{subject}"', ['subject' => $subject]);

            return false;
        }

        return true;
    }
}
