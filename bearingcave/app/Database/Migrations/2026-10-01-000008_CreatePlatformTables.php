<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Notifications, email outbox, audit log, CMS and contact leads.
 */
class CreatePlatformTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('notifications', [
            'user_id'    => $this->ref(),
            'type'       => $this->str(60),
            'title'      => $this->str(191),
            'body'       => $this->text(),
            'link'       => $this->str(255, true),
            'read_at'    => $this->dt(),
            'created_at' => $this->dt(),
        ], [['user_id', 'read_at']], [], [['user_id', 'users', 'CASCADE']]);

        // An email is only "sent" once the configured transport accepted it.
        $this->table('email_outbox', [
            'user_id'      => $this->ref(true),
            'to_email'     => $this->str(191),
            'subject'      => $this->str(191),
            'body_html'    => ['type' => 'MEDIUMTEXT', 'null' => true],
            'status'       => $this->enum(['queued', 'sent', 'failed', 'disabled'], 'queued'),
            'attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'last_error'   => $this->text(),
            'sent_at'      => $this->dt(),
        ] + $this->timestamps(), ['status'], [], [['user_id', 'users', 'SET NULL']]);

        $this->table('audit_logs', [
            'user_id'     => $this->ref(true),
            'company_id'  => $this->ref(true),
            'event'       => $this->str(80),
            'severity'    => $this->enum(['info', 'warning', 'security'], 'info'),
            'entity_type' => $this->str(40, true),
            'entity_id'   => $this->ref(true),
            'description' => $this->str(255, true),
            'old_values'  => $this->json(),
            'new_values'  => $this->json(),
            'ip_address'  => $this->str(45, true),
            'user_agent'  => $this->str(255, true),
            'created_at'  => $this->dt(),
        ], ['event', 'severity', ['entity_type', 'entity_id'], 'user_id', 'created_at']);

        $this->table('cms_pages', [
            'slug'             => $this->str(120),
            'title'            => $this->str(191),
            'body'             => ['type' => 'MEDIUMTEXT', 'null' => true],
            'meta_title'       => $this->str(191, true),
            'meta_description' => $this->str(255, true),
            'status'           => $this->enum(['draft', 'published'], 'draft'),
            'show_in_footer'   => $this->bool(0),
            'approval_status'  => $this->enum(['approved', 'pending_approval'], 'pending_approval'),
            'updated_by'       => $this->ref(true),
        ] + $this->timestamps(), [], ['slug']);

        $this->table('contact_leads', [
            'name'         => $this->str(120),
            'company_name' => $this->str(191, true),
            'email'        => $this->str(191),
            'phone'        => $this->str(40, true),
            'country_code' => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'enquiry_type' => $this->enum(['general', 'buyer', 'supplier', 'membership', 'inspection', 'logistics', 'partnership'], 'general'),
            'message'      => $this->text(false),
            'status'       => $this->enum(['new', 'contacted', 'closed'], 'new'),
            'ip_address'   => $this->str(45, true),
        ] + $this->timestamps(), ['status']);
    }

    public function down(): void
    {
        foreach (['contact_leads', 'cms_pages', 'audit_logs', 'email_outbox', 'notifications'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
