<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompanyMemberModel;
use App\Models\EmailOutboxModel;
use App\Models\NotificationModel;
use CodeIgniter\Shield\Models\UserModel;

/**
 * In-app notifications + email outbox.
 *
 * An email is recorded as "sent" only when the configured transport accepted
 * it. When email delivery is disabled the message stays in the outbox with
 * status "disabled" so nothing is claimed as delivered.
 */
class NotificationService
{
    public function __construct(
        private NotificationModel $notifications = new NotificationModel(),
        private EmailOutboxModel $outbox = new EmailOutboxModel(),
    ) {
    }

    public function notifyUser(int $userId, string $type, string $title, string $body = '', ?string $link = null, bool $email = true): void
    {
        $this->notifications->insert([
            'user_id' => $userId,
            'type'    => $type,
            'title'   => mb_substr($title, 0, 191),
            'body'    => $body,
            'link'    => $link,
        ]);

        if ($email && $this->emailEnabledFor($type)) {
            $user = model(UserModel::class)->find($userId);
            if ($user !== null && $user->email) {
                $this->queueEmail($userId, $user->email, $title, $this->renderEmail($title, $body, $link));
            }
        }
    }

    /**
     * Notifies all active members of a company.
     */
    public function notifyCompany(int $companyId, string $type, string $title, string $body = '', ?string $link = null): void
    {
        $members = model(CompanyMemberModel::class)->where('company_id', $companyId)->where('status', 'active')->findAll();
        foreach ($members as $m) {
            $this->notifyUser((int) $m['user_id'], $type, $title, $body, $link);
        }
    }

    /**
     * Notifies every user in the given staff groups (superadmin is always included).
     *
     * @param list<string> $groups
     */
    public function notifyStaff(array $groups, string $type, string $title, string $body = '', ?string $link = null): void
    {
        $groups[] = 'superadmin';
        $ids      = db_connect()->table('auth_groups_users')->select('user_id')->distinct()
            ->whereIn('group', array_unique($groups))->get()->getResultArray();
        foreach ($ids as $r) {
            $this->notifyUser((int) $r['user_id'], $type, $title, $body, $link, false);
        }
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->where('user_id', $userId)->where('read_at', null)->countAllResults();
    }

    public function queueEmail(?int $userId, string $to, string $subject, string $html): int
    {
        $id = (int) $this->outbox->insert([
            'user_id'   => $userId,
            'to_email'  => $to,
            'subject'   => mb_substr($subject, 0, 191),
            'body_html' => $html,
            'status'    => service('settings_store')->get('email.delivery_enabled', false) ? 'queued' : 'disabled',
        ]);

        if (service('settings_store')->get('email.send_immediately', false)) {
            $this->deliver($id);
        }

        return $id;
    }

    /**
     * Attempts delivery through the configured CodeIgniter Email transport.
     */
    public function deliver(int $outboxId): bool
    {
        $row = $this->outbox->find($outboxId);
        if ($row === null || ! in_array($row['status'], ['queued', 'failed'], true)) {
            return false;
        }

        $email = service('email');
        $email->clear();
        $email->setTo($row['to_email']);
        $email->setSubject($row['subject']);
        $email->setMessage($row['body_html']);
        $email->setMailType('html');

        $accepted = false;
        $error    = null;

        try {
            $accepted = $email->send(false);
            if (! $accepted) {
                $error = mb_substr(strip_tags($email->printDebugger(['headers'])), 0, 2000);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->outbox->update($outboxId, [
            'status'     => $accepted ? 'sent' : 'failed',
            'attempts'   => (int) $row['attempts'] + 1,
            'last_error' => $error,
            'sent_at'    => $accepted ? date('Y-m-d H:i:s') : null,
        ]);

        return $accepted;
    }

    private function emailEnabledFor(string $type): bool
    {
        $disabled = service('settings_store')->get('notifications.email_disabled_types', []);

        return ! in_array($type, (array) $disabled, true);
    }

    private function renderEmail(string $title, string $body, ?string $link): string
    {
        return view('emails/notification', ['title' => $title, 'body' => $body, 'link' => $link ? site_url($link) : null]);
    }
}
