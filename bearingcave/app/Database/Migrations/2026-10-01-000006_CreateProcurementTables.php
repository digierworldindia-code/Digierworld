<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\Support\SchemaHelper;
use CodeIgniter\Database\Migration;

/**
 * RFQs, supplier matching, quotations (sealed bids), negotiation,
 * carts and orders.
 */
class CreateProcurementTables extends Migration
{
    use SchemaHelper;

    public function up(): void
    {
        $this->table('rfqs', [
            'rfq_number'           => $this->str(30),
            'buyer_company_id'     => $this->ref(),
            'created_by'           => $this->ref(true),
            'title'                => $this->str(191),
            'destination_country'  => ['type' => 'CHAR', 'constraint' => 2],
            'delivery_terms'       => $this->str(20, true),
            'delivery_requirements' => $this->text(),
            'required_by'          => $this->date(),
            'deadline_at'          => $this->dt(false),
            'currency'             => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'inspection_required'  => $this->bool(0),
            'logistics_required'   => $this->bool(0),
            'comments'             => $this->text(),
            'status'               => $this->enum(['draft', 'submitted', 'under_review', 'distributed', 'evaluation', 'awarded', 'closed', 'cancelled', 'rejected'], 'submitted'),
            'source'               => $this->enum(['form', 'cart', 'product', 'enquiry'], 'form'),
            'admin_notes'          => $this->text(),
            'reviewed_by'          => $this->ref(true),
            'reviewed_at'          => $this->dt(),
            'distributed_at'       => $this->dt(),
            'awarded_quotation_id' => $this->ref(true),
        ] + $this->timestamps(), ['status', 'buyer_company_id', 'deadline_at'], ['rfq_number'],
            [['buyer_company_id', 'companies', 'CASCADE'], ['created_by', 'users', 'SET NULL'], ['reviewed_by', 'users', 'SET NULL'], ['destination_country', 'countries', 'RESTRICT', 'iso2']]
        );

        $this->table('rfq_items', [
            'rfq_id'            => $this->ref(),
            'product_id'        => $this->ref(true),
            'category_id'       => $this->ref(true),
            'brand_id'          => $this->ref(true),
            'brand_text'        => $this->str(120, true),
            'part_number'       => $this->str(80, true),
            'part_number_norm'  => $this->str(80, true),
            'description'       => $this->str(255),
            'specifications'    => $this->text(),
            'quantity'          => $this->int(false, 1),
            'unit'              => $this->str(20, false, 'pcs'),
            'target_unit_price' => $this->dec(15, 4, true),
        ] + $this->timestamps(), ['rfq_id', 'part_number_norm'], [],
            [['rfq_id', 'rfqs', 'CASCADE'], ['product_id', 'products', 'SET NULL'], ['category_id', 'categories', 'SET NULL'], ['brand_id', 'brands', 'SET NULL']]
        );

        $this->table('rfq_matches', [
            'rfq_id'              => $this->ref(),
            'supplier_company_id' => $this->ref(),
            'match_score'         => $this->dec(6, 2, false, '0.00'),
            'match_reasons'       => $this->json(),
            'matched_product_ids' => $this->json(),
            'status'              => $this->enum(['suggested', 'invited', 'excluded', 'viewed', 'declined', 'quoted'], 'suggested'),
            'invited_by'          => $this->ref(true),
            'invited_at'          => $this->dt(),
            'viewed_at'           => $this->dt(),
            'responded_at'        => $this->dt(),
            'decline_reason'      => $this->str(255, true),
        ] + $this->timestamps(), ['status', 'supplier_company_id'], [['rfq_id', 'supplier_company_id']],
            [['rfq_id', 'rfqs', 'CASCADE'], ['supplier_company_id', 'companies', 'CASCADE'], ['invited_by', 'users', 'SET NULL']]
        );

        // A quotation is the supplier's sealed bid. Revisions create a new row.
        $this->table('quotations', [
            'quotation_number'    => $this->str(30),
            'rfq_id'              => $this->ref(),
            'supplier_company_id' => $this->ref(),
            'rfq_match_id'        => $this->ref(true),
            'revision'            => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'previous_id'         => $this->ref(true),
            'is_current'          => $this->bool(1),
            'status'              => $this->enum(['draft', 'submitted', 'superseded', 'withdrawn', 'shortlisted', 'accepted', 'rejected', 'expired'], 'submitted'),
            'currency'            => ['type' => 'CHAR', 'constraint' => 3],
            'total_amount'        => $this->dec(15, 2, false, '0.00'),
            'valid_until'         => $this->date(),
            'delivery_terms'      => $this->str(20, true),
            'lead_time_days'      => $this->int(true, null),
            'payment_terms'       => $this->str(191, true),
            'inspection_offered'  => $this->bool(0),
            'logistics_option'    => $this->enum(['platform_managed', 'supplier_direct', 'confidential', 'buyer_arranged'], 'platform_managed'),
            'notes'               => $this->text(),
            'submitted_by'        => $this->ref(true),
            'submitted_at'        => $this->dt(),
            'buyer_decision_at'   => $this->dt(),
            'buyer_decision_note' => $this->text(),
        ] + $this->timestamps(), [['rfq_id', 'is_current'], 'supplier_company_id', 'status'], ['quotation_number'],
            [['rfq_id', 'rfqs', 'CASCADE'], ['supplier_company_id', 'companies', 'CASCADE'], ['rfq_match_id', 'rfq_matches', 'SET NULL'], ['previous_id', 'quotations', 'SET NULL'], ['submitted_by', 'users', 'SET NULL']]
        );

        $this->table('quotation_items', [
            'quotation_id'   => $this->ref(),
            'rfq_item_id'    => $this->ref(true),
            'product_id'     => $this->ref(true),
            'description'    => $this->str(255),
            'part_number'    => $this->str(80, true),
            'quantity'       => $this->int(false, 1),
            'unit_price'     => $this->dec(15, 4, false),
            'line_total'     => $this->dec(15, 2, false),
            'lead_time_days' => $this->int(true, null),
            'notes'          => $this->str(255, true),
        ] + $this->timestamps(), ['quotation_id'], [],
            [['quotation_id', 'quotations', 'CASCADE'], ['rfq_item_id', 'rfq_items', 'SET NULL'], ['product_id', 'products', 'SET NULL']]
        );

        // Negotiation thread between buyer and supplier on one quotation (relayed by platform).
        $this->table('quotation_messages', [
            'quotation_id'   => $this->ref(),
            'sender_side'    => $this->enum(['buyer', 'supplier', 'admin']),
            'user_id'        => $this->ref(true),
            'message'        => $this->text(false),
            'proposed_total' => $this->dec(15, 2, true),
            'created_at'     => $this->dt(),
        ], ['quotation_id'], [], [['quotation_id', 'quotations', 'CASCADE'], ['user_id', 'users', 'SET NULL']]);

        $this->table('carts', [
            'user_id'    => $this->ref(),
            'company_id' => $this->ref(),
            'status'     => $this->enum(['active', 'converted', 'abandoned'], 'active'),
        ] + $this->timestamps(), [['user_id', 'status']], [],
            [['user_id', 'users', 'CASCADE'], ['company_id', 'companies', 'CASCADE']]
        );

        $this->table('cart_items', [
            'cart_id'    => $this->ref(),
            'product_id' => $this->ref(),
            'quantity'   => $this->int(false, 1),
        ] + $this->timestamps(), [], [['cart_id', 'product_id']],
            [['cart_id', 'carts', 'CASCADE'], ['product_id', 'products', 'CASCADE']]
        );

        $this->table('orders', [
            'order_number'          => $this->str(30),
            'buyer_company_id'      => $this->ref(),
            'supplier_company_id'   => $this->ref(),
            'quotation_id'          => $this->ref(true),
            'rfq_id'                => $this->ref(true),
            'source'                => $this->enum(['quotation', 'cart']),
            'status'                => $this->enum(['pending_supplier_confirmation', 'confirmed', 'awaiting_payment', 'paid', 'in_fulfilment', 'shipped', 'delivered', 'completed', 'cancelled', 'disputed'], 'pending_supplier_confirmation'),
            'payment_status'        => $this->enum(['unpaid', 'pending', 'partially_paid', 'paid', 'refunded'], 'unpaid'),
            'shipment_status'       => $this->enum(['not_started', 'requested', 'booked', 'in_transit', 'delivered'], 'not_started'),
            'currency'              => ['type' => 'CHAR', 'constraint' => 3],
            'subtotal'              => $this->dec(15, 2, false, '0.00'),
            'inspection_fee'        => $this->dec(15, 2, false, '0.00'),
            'logistics_fee'         => $this->dec(15, 2, false, '0.00'),
            'tax_amount'            => $this->dec(15, 2, false, '0.00'),
            'total'                 => $this->dec(15, 2, false, '0.00'),
            'incoterm'              => $this->str(20, true),
            'payment_terms'         => $this->str(191, true),
            'delivery_country'      => ['type' => 'CHAR', 'constraint' => 2],
            'delivery_address'      => $this->text(),
            'logistics_mode'        => $this->enum(['platform_managed', 'supplier_direct', 'confidential', 'buyer_arranged'], 'platform_managed'),
            'commercial_terms'      => $this->text(),
            'buyer_po_reference'    => $this->str(80, true),
            'buyer_confirmed_at'    => $this->dt(),
            'supplier_confirmed_at' => $this->dt(),
            'cancelled_reason'      => $this->text(),
            'created_by'            => $this->ref(true),
        ] + $this->timestamps(), ['status', 'buyer_company_id', 'supplier_company_id'], ['order_number'],
            [['buyer_company_id', 'companies', 'RESTRICT'], ['supplier_company_id', 'companies', 'RESTRICT'], ['quotation_id', 'quotations', 'SET NULL'], ['rfq_id', 'rfqs', 'SET NULL'], ['created_by', 'users', 'SET NULL']]
        );

        $this->table('order_items', [
            'order_id'          => $this->ref(),
            'product_id'        => $this->ref(true),
            'quotation_item_id' => $this->ref(true),
            'description'       => $this->str(255),
            'part_number'       => $this->str(80, true),
            'quantity'          => $this->int(false, 1),
            'unit_price'        => $this->dec(15, 4, false),
            'line_total'        => $this->dec(15, 2, false),
            'stock_reserved'    => $this->bool(0),
        ] + $this->timestamps(), ['order_id'], [],
            [['order_id', 'orders', 'CASCADE'], ['product_id', 'products', 'SET NULL'], ['quotation_item_id', 'quotation_items', 'SET NULL']]
        );

        $this->table('order_status_history', [
            'order_id'    => $this->ref(),
            'from_status' => $this->str(40, true),
            'to_status'   => $this->str(40),
            'user_id'     => $this->ref(true),
            'note'        => $this->text(),
            'created_at'  => $this->dt(),
        ], ['order_id'], [], [['order_id', 'orders', 'CASCADE'], ['user_id', 'users', 'SET NULL']]);
    }

    public function down(): void
    {
        foreach (['order_status_history', 'order_items', 'orders', 'cart_items', 'carts', 'quotation_messages', 'quotation_items', 'quotations', 'rfq_matches', 'rfq_items', 'rfqs'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
