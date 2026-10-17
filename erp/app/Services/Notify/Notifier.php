<?php

namespace App\Services\Notify;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * In-app notifications (bell) with optional email when SMTP is configured.
 * Never claims an email/WhatsApp was sent unless the mailer reports success.
 */
class Notifier
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Create a notification; a dedupe key prevents repeated reminders.
     * Returns true if a new notification was created.
     */
    public function notify(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?string $entityType = null, ?int $entityId = null, ?string $dedupeKey = null): bool
    {
        $sql = 'INSERT IGNORE INTO notifications (user_id, type, title, body, link, entity_type, entity_id, dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
        $this->db->query($sql, [$userId, $type, mb_substr($title, 0, 200), $body === null ? null : mb_substr($body, 0, 1000), $link, $entityType, $entityId, $dedupeKey]);

        return $this->db->affectedRows() > 0;
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->table('notifications')->where(['user_id' => $userId, 'read_at' => null])->countAllResults();
    }

    /** @return list<array<string, mixed>> */
    public function latest(int $userId, int $limit = 10): array
    {
        return $this->db->table('notifications')->where('user_id', $userId)
            ->orderBy('read_at IS NULL', 'DESC', false)->orderBy('created_at', 'DESC')->limit($limit)->get()->getResultArray();
    }

    public static function mailConfigured(): bool
    {
        $cfg = config('Email');

        return $cfg->fromEmail !== '' && ($cfg->protocol !== 'smtp' || $cfg->SMTPHost !== '');
    }

    /** Attempt an email; returns false (and logs) when not configured or failed. */
    public function email(string $to, string $subject, string $html): bool
    {
        if (! self::mailConfigured() || $to === '') {
            return false;
        }
        try {
            $email = service('email');
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($html);
            $ok = $email->send(false);
            if (! $ok) {
                log_message('error', 'Email send failed: ' . strip_tags($email->printDebugger(['headers'])));
            }

            return $ok;
        } catch (Throwable $e) {
            log_message('error', 'Email exception: ' . $e->getMessage());

            return false;
        }
    }
}
