<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * Inspection, logistics and dispute management.
 */
class CreateServiceTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('inspection_requests', [
            'request_number'       => $this->str(30),
            'company_id'           => $this->ref(),
            'requested_by'         => $this->ref(true),
            'order_id'             => $this->ref(true),
            'product_id'           => $this->ref(true),
            'inspection_type'      => $this->enum(['in_house', 'third_party']),
            'scope'                => $this->json(),
            'location_country'     => ['type' => 'CHAR', 'constraint' => 2],
            'location_city'        => $this->str(100, true),
            'invoice_value'        => $this->dec(15, 2, true),
            'shipment_volume_cbm'  => $this->dec(10, 3, true),
            'currency'             => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'estimated_fee'        => $this->dec(15, 2, true),
            'quoted_fee'           => $this->dec(15, 2, true),
            'fee_basis'            => $this->str(255, true),
            'status'               => $this->enum(['requested', 'quoted', 'accepted', 'declined', 'assigned', 'in_progress', 'report_uploaded', 'completed', 'cancelled'], 'requested'),
            'inspector_user_id'    => $this->ref(true),
            'third_party_agency'   => $this->str(191, true),
            'scheduled_on'         => $this->date(),
            'quote_valid_until'    => $this->date(),
            'invoice_id'           => $this->ref(true),
            'notes'                => $this->text(),
            'admin_notes'          => $this->text(),
        ] + $this->timestamps(), ['status', 'company_id'], ['request_number'],
            [['company_id', 'companies', 'CASCADE'], ['requested_by', 'users', 'SET NULL'], ['order_id', 'orders', 'SET NULL'], ['product_id', 'products', 'SET NULL'], ['inspector_user_id', 'users', 'SET NULL'], ['invoice_id', 'invoices', 'SET NULL']]
        );

        $this->table('inspection_reports', [
            'inspection_request_id' => $this->ref(),
            'result'                => $this->enum(['pass', 'fail', 'conditional']),
            'inspected_on'          => $this->date(false),
            'quantity_expected'     => $this->int(true, null),
            'quantity_verified'     => $this->int(true, null),
            'packaging_ok'          => $this->bool(0),
            'authenticity_notes'    => $this->text(),
            'summary'               => $this->text(false),
            'findings'              => $this->text(),
            'document_id'           => $this->ref(true),
            'uploaded_by'           => $this->ref(true),
        ] + $this->timestamps(), [], ['inspection_request_id'],
            [['inspection_request_id', 'inspection_requests', 'CASCADE'], ['document_id', 'documents', 'SET NULL'], ['uploaded_by', 'users', 'SET NULL']]
        );

        $this->table('logistics_requests', [
            'request_number'     => $this->str(30),
            'company_id'         => $this->ref(),
            'requested_by'       => $this->ref(true),
            'order_id'           => $this->ref(true),
            'mode'               => $this->enum(['supplier_direct', 'platform_managed', 'confidential', 'consolidation']),
            'consolidation_ref'  => $this->str(40, true),
            'pickup_country'     => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'pickup_city'        => $this->str(100, true),
            'pickup_address'     => $this->text(),
            'pickup_contact'     => $this->str(191, true),
            'destination_country' => ['type' => 'CHAR', 'constraint' => 2],
            'destination_city'   => $this->str(100, true),
            'destination_address' => $this->text(),
            'destination_contact' => $this->str(191, true),
            'incoterm'           => $this->str(20, true),
            'cargo_description'  => $this->str(255, true),
            'weight_kg'          => $this->dec(12, 3, true),
            'volume_cbm'         => $this->dec(10, 3, true),
            'packages'           => $this->int(true, null),
            'goods_value'        => $this->dec(15, 2, true),
            'currency'           => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'estimated_fee'      => $this->dec(15, 2, true),
            'quoted_fee'         => $this->dec(15, 2, true),
            'fee_basis'          => $this->str(255, true),
            'status'             => $this->enum(['requested', 'quoted', 'accepted', 'booked', 'in_transit', 'delivered', 'cancelled'], 'requested'),
            'assigned_to'        => $this->ref(true),
            'invoice_id'         => $this->ref(true),
            'notes'              => $this->text(),
            'admin_notes'        => $this->text(),
        ] + $this->timestamps(), ['status', 'company_id'], ['request_number'],
            [['company_id', 'companies', 'CASCADE'], ['requested_by', 'users', 'SET NULL'], ['order_id', 'orders', 'SET NULL'], ['assigned_to', 'users', 'SET NULL'], ['invoice_id', 'invoices', 'SET NULL']]
        );

        $this->table('shipments', [
            'logistics_request_id' => $this->ref(true),
            'order_id'             => $this->ref(true),
            'carrier'              => $this->str(120, true),
            'tracking_number'      => $this->str(120, true),
            'transport_mode'       => $this->enum(['air', 'sea', 'road', 'rail', 'courier'], 'road'),
            'status'               => $this->enum(['booked', 'picked_up', 'in_transit', 'customs', 'out_for_delivery', 'delivered', 'exception'], 'booked'),
            'shipped_at'           => $this->dt(),
            'eta'                  => $this->date(),
            'delivered_at'         => $this->dt(),
            'delivered_on_time'    => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'created_by'           => $this->ref(true),
        ] + $this->timestamps(), ['status', 'order_id'], [],
            [['logistics_request_id', 'logistics_requests', 'SET NULL'], ['order_id', 'orders', 'SET NULL'], ['created_by', 'users', 'SET NULL']]
        );

        $this->table('shipment_events', [
            'shipment_id' => $this->ref(),
            'status'      => $this->str(40),
            'location'    => $this->str(191, true),
            'description' => $this->str(255, true),
            'event_at'    => $this->dt(false),
            'created_by'  => $this->ref(true),
            'created_at'  => $this->dt(),
        ], ['shipment_id'], [], [['shipment_id', 'shipments', 'CASCADE'], ['created_by', 'users', 'SET NULL']]);

        $this->table('disputes', [
            'dispute_number'        => $this->str(30),
            'order_id'              => $this->ref(),
            'raised_by_company_id'  => $this->ref(),
            'against_company_id'    => $this->ref(),
            'raised_by'             => $this->ref(true),
            'category'              => $this->enum(['quality', 'quantity_shortfall', 'wrong_item', 'damaged_goods', 'delayed_delivery', 'documentation', 'payment', 'inspection_service', 'platform_service', 'other']),
            'subject'               => $this->str(191),
            'description'           => $this->text(false),
            'desired_resolution'    => $this->text(),
            'status'                => $this->enum(['open', 'awaiting_response', 'under_review', 'resolved', 'rejected', 'closed'], 'open'),
            'assigned_to'           => $this->ref(true),
            'resolution_notes'      => $this->text(),
            'resolved_at'           => $this->dt(),
            'closed_at'             => $this->dt(),
        ] + $this->timestamps(), ['status', 'order_id'], ['dispute_number'],
            [['order_id', 'orders', 'CASCADE'], ['raised_by_company_id', 'companies', 'CASCADE'], ['against_company_id', 'companies', 'CASCADE'], ['raised_by', 'users', 'SET NULL'], ['assigned_to', 'users', 'SET NULL']]
        );

        $this->table('dispute_messages', [
            'dispute_id'  => $this->ref(),
            'user_id'     => $this->ref(true),
            'sender_side' => $this->enum(['buyer', 'supplier', 'admin']),
            'message'     => $this->text(false),
            'is_internal' => $this->bool(0),
            'created_at'  => $this->dt(),
        ], ['dispute_id'], [], [['dispute_id', 'disputes', 'CASCADE'], ['user_id', 'users', 'SET NULL']]);
    }

    public function down(): void
    {
        foreach (['dispute_messages', 'disputes', 'shipment_events', 'shipments', 'logistics_requests', 'inspection_reports', 'inspection_requests'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
