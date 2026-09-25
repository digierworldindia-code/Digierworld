<?php

namespace App\Controllers\Dealer;

use App\Services\SalesService;
use CodeIgniter\HTTP\RedirectResponse;

/** Recording a sale — the moment a warranty starts. */
class Sales extends DealerController
{
    /** The same set the previous platform accepted, so imported sales still read correctly. */
    public const PAYMENT_MODES = [
        'CASH' => 'Cash', 'CARD' => 'Card', 'UPI' => 'UPI',
        'BANK_TRANSFER' => 'Bank transfer', 'FINANCE' => 'Finance', 'OTHER' => 'Something else',
    ];

    public function new(): string
    {
        $serial = strtoupper(trim((string) $this->request->getGet('serial')));
        $stock  = $this->scope->apply(
            db_connect()->table('mattresses m')->select('m.serial_number, p.name product, v.size_label, v.mrp')
                ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id'),
            'mattresses', 'm',
        )->where('m.deleted_at', null)->where('m.current_status', 'DEALER_RECEIVED')->orderBy('p.name')->limit(300)->get()->getResultArray();

        return $this->render('dealer/sell', 'Record a sale', 'sell', ['serial' => $serial, 'stock' => $stock]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'serial'                 => 'required|serial_number',
            'invoice_number'         => 'required|max_length[60]|safe_text',
            'sold_on'                => 'required|valid_date[Y-m-d]',
            'sale_price'             => 'required|decimal|greater_than_equal_to[0]',
            'payment_mode'           => 'required|in_list[' . implode(',', array_keys(self::PAYMENT_MODES)) . ']',
            'customer_full_name'     => 'required|max_length[160]|safe_text',
            'customer_phone'         => 'required|indian_mobile',
            'customer_email'         => 'permit_empty|max_length[255]|valid_email',
            'customer_address_line'  => 'permit_empty|max_length[300]|safe_text',
            'customer_city'          => 'permit_empty|max_length[80]|safe_text',
            'customer_state'         => 'permit_empty|max_length[80]|safe_text',
            'customer_pincode'       => 'permit_empty|pincode',
            'remarks'                => 'permit_empty|max_length[500]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        return $this->act(fn () => SalesService::instance()->recordSale([
            'serial'         => strtoupper(trim($in['serial'])),
            'invoice_number' => $in['invoice_number'],
            'sold_on'        => $in['sold_on'],
            'sale_price'     => $in['sale_price'],
            'payment_mode'   => $in['payment_mode'],
            'remarks'        => $in['remarks'] ?? null,
            'customer'       => [
                'full_name'    => $in['customer_full_name'],
                'phone'        => $in['customer_phone'],
                'email'        => $in['customer_email'] ?? null,
                'address_line' => $in['customer_address_line'] ?? null,
                'city'         => $in['customer_city'] ?? null,
                'state'        => $in['customer_state'] ?? null,
                'pincode'      => $in['customer_pincode'] ?? null,
            ],
        ]), static fn ($r) => "Sale recorded for {$r['serial']}. The warranty runs to " . local_date($r['warranty_end']) . " ({$r['warranty_years']} years).", site_url('dealer/sales'));
    }

    public function index(): string
    {
        $builder = $this->scope->apply(
            db_connect()->table('sales s')->select('s.id, s.invoice_number, s.sold_at, s.sale_price, s.payment_mode, m.serial_number,
                        p.name product, v.size_label, c.full_name customer, w.status warranty_status, w.end_date')
                ->join('mattresses m', 'm.id = s.mattress_id')->join('product_variants v', 'v.id = m.product_variant_id')
                ->join('products p', 'p.id = v.product_id')->join('customers c', 'c.id = s.customer_id')
                ->join('warranties w', 'w.sale_id = s.id AND w.deleted_at IS NULL', 'left'),
            'sales', 's',
        )->where('s.deleted_at', null)->orderBy('s.sold_at', 'DESC');

        return $this->render('dealer/sales', 'My sales', 'sell', ['list' => $this->paginate($builder)]);
    }
}
