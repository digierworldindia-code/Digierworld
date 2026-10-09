<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Membership plans, entitlements, subscriptions, invoices and payments.
 */
class CreateMembershipBillingTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('membership_plans', [
            'code'            => $this->str(40),
            'name'            => $this->str(100),
            'audience'        => $this->enum(['supplier', 'buyer']),
            'annual_fee'      => $this->dec(12, 2, false, '0.00'),
            'currency'        => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'duration_months' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 12],
            'requires_verification' => $this->bool(0),
            'is_default'      => $this->bool(0),
            'is_active'       => $this->bool(1),
            'description'     => $this->text(),
            'sort_order'      => $this->int(),
            'approval_status' => $this->enum(['approved', 'pending_approval'], 'pending_approval'),
        ] + $this->timestamps(), ['audience'], ['code']);

        $this->table('plan_entitlements', [
            'plan_id'     => $this->ref(),
            'feature_key' => $this->str(80),
            'is_enabled'  => $this->bool(0),
            'limit_value' => $this->int(true, null),
        ] + $this->timestamps(), [], [['plan_id', 'feature_key']],
            [['plan_id', 'membership_plans', 'CASCADE']]
        );

        $this->table('subscriptions', [
            'company_id'   => $this->ref(),
            'plan_id'      => $this->ref(),
            'status'       => $this->enum(['pending_payment', 'active', 'expired', 'cancelled', 'suspended'], 'pending_payment'),
            'starts_on'    => $this->date(),
            'ends_on'      => $this->date(),
            'invoice_id'   => $this->ref(true),
            'renewal_of_id' => $this->ref(true),
            'reminder_sent_at' => $this->dt(),
            'notes'        => $this->text(),
            'created_by'   => $this->ref(true),
        ] + $this->timestamps(), ['status', 'ends_on', 'company_id'], [],
            [['company_id', 'companies', 'CASCADE'], ['plan_id', 'membership_plans', 'RESTRICT'], ['created_by', 'users', 'SET NULL'], ['renewal_of_id', 'subscriptions', 'SET NULL']]
        );

        $this->table('invoices', [
            'invoice_number' => $this->str(40),
            'company_id'     => $this->ref(),
            'invoice_type'   => $this->enum(['subscription', 'inspection', 'logistics', 'proforma']),
            'reference_type' => $this->str(40, true),
            'reference_id'   => $this->ref(true),
            'currency'       => ['type' => 'CHAR', 'constraint' => 3],
            'subtotal'       => $this->dec(15, 2, false, '0.00'),
            'tax_rate'       => $this->dec(5, 2, false, '0.00'),
            'tax_amount'     => $this->dec(15, 2, false, '0.00'),
            'total'          => $this->dec(15, 2, false, '0.00'),
            'tax_note'       => $this->str(191, true),
            'status'         => $this->enum(['draft', 'issued', 'paid', 'void'], 'issued'),
            'issued_at'      => $this->dt(),
            'due_at'         => $this->dt(),
            'paid_at'        => $this->dt(),
            'notes'          => $this->text(),
            'created_by'     => $this->ref(true),
        ] + $this->timestamps(), ['status', ['reference_type', 'reference_id']], ['invoice_number'],
            [['company_id', 'companies', 'CASCADE'], ['created_by', 'users', 'SET NULL']]
        );

        $this->table('payments', [
            'company_id'        => $this->ref(),
            'invoice_id'        => $this->ref(true),
            'order_id'          => $this->ref(true),
            'provider'          => $this->str(40),
            'provider_reference' => $this->str(191, true),
            'idempotency_key'   => $this->str(100),
            'method'            => $this->str(40, true),
            'amount'            => $this->dec(15, 2, false),
            'currency'          => ['type' => 'CHAR', 'constraint' => 3],
            'status'            => $this->enum(['initiated', 'pending', 'succeeded', 'failed', 'refunded', 'cancelled'], 'initiated'),
            'is_sandbox'        => $this->bool(0),
            'is_escrow'         => $this->bool(0),
            'escrow_status'     => $this->enum(['none', 'held_by_provider', 'released', 'refunded'], 'none'),
            'recorded_by'       => $this->ref(true),
            'paid_at'           => $this->dt(),
            'notes'             => $this->text(),
            'raw_payload'       => $this->json(),
        ] + $this->timestamps(), ['status', 'order_id', 'invoice_id'], ['idempotency_key'],
            [['company_id', 'companies', 'CASCADE'], ['invoice_id', 'invoices', 'SET NULL'], ['recorded_by', 'users', 'SET NULL']]
        );

        $this->table('payment_webhook_events', [
            'provider'     => $this->str(40),
            'event_id'     => $this->str(191),
            'event_type'   => $this->str(100, true),
            'payload'      => $this->json(),
            'signature_valid' => $this->bool(0),
            'status'       => $this->enum(['received', 'processed', 'ignored', 'failed'], 'received'),
            'error'        => $this->text(),
            'processed_at' => $this->dt(),
        ] + $this->timestamps(), [], [['provider', 'event_id']]);
    }

    public function down(): void
    {
        foreach (['payment_webhook_events', 'payments', 'invoices', 'subscriptions', 'plan_entitlements', 'membership_plans'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
