<?php

namespace App\Controllers\Admin;

use App\Libraries\Crypto;

/**
 * Sales recorded by dealers, and the customers behind them.
 *
 * Contact details are stored encrypted. They are decrypted here only for staff
 * who hold customer:read, one page at a time, and never written to a log.
 */
class Sales extends AdminController
{
    public function index(): string
    {
        $db     = db_connect();
        $dealer = $this->filter('dealer', 36);
        $from   = $this->filter('from', 10);
        $to     = $this->filter('to', 10);
        $q      = $this->filter('q', 40);

        // The same filters serve the page and its totals; `select()` adds to a
        // builder rather than replacing, so each query gets its own.
        $filter = function (\CodeIgniter\Database\BaseBuilder $b) use ($dealer, $from, $to, $q) {
            $b->join('mattresses m', 'm.id = s.mattress_id')->join('dealers d', 'd.id = s.dealer_id')->join('customers c', 'c.id = s.customer_id')
                ->where('s.deleted_at', null);
            if ($dealer !== null && is_uuid($dealer)) {
                $b->where('s.dealer_id', $dealer);
            }
            if ($from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                $b->where('s.sold_at >=', $from . ' 00:00:00');
            }
            if ($to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $b->where('s.sold_at <=', $to . ' 23:59:59.999999');
            }
            if ($q !== null) {
                $term = $this->like($q);
                $b->groupStart()->like('m.serial_number', strtoupper($term))->orLike('s.invoice_number', $term)->orLike('c.full_name', $term)->groupEnd();
            }

            return $b;
        };

        $builder = $filter($db->table('sales s'))
            ->select('s.id, s.invoice_number, s.sold_at, s.sale_price, s.payment_mode, m.serial_number, m.id mattress_id,
                      p.name product, v.size_label, d.business_name dealer, d.city, c.full_name customer, w.status warranty_status, w.id warranty_id')
            ->join('product_variants v', 'v.id = m.product_variant_id')->join('products p', 'p.id = v.product_id')
            ->join('warranties w', 'w.sale_id = s.id AND w.deleted_at IS NULL', 'left')
            ->orderBy('s.sold_at', 'DESC');

        $totals = $filter($db->table('sales s'))->select('COUNT(*) n, COALESCE(SUM(s.sale_price), 0) value', false)->get()->getRowArray();

        return $this->render('admin/sales/index', 'Sales', 'admin/sales', [
            'list'    => $this->paginate($builder),
            'totals'  => $totals,
            'dealers' => $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray(),
            'f'       => compact('dealer', 'from', 'to', 'q'),
        ]);
    }

    public function customers(): string
    {
        $db     = db_connect();
        $crypto = Crypto::instance();
        $q      = $this->filter('q', 60);
        $dealer = $this->filter('dealer', 36);

        $builder = $db->table('customers c')
            ->select('c.id, c.full_name, c.phone_encrypted, c.city, c.state, c.created_at, d.business_name dealer,
                      (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.id AND s.deleted_at IS NULL) purchases,
                      (SELECT COUNT(*) FROM warranty_claims k WHERE k.customer_id = c.id AND k.deleted_at IS NULL) claims', false)
            ->join('dealers d', 'd.id = c.dealer_id')
            ->where('c.deleted_at', null)->orderBy('c.created_at', 'DESC');
        if ($dealer && is_uuid($dealer)) {
            $builder->where('c.dealer_id', $dealer);
        }
        if ($q) {
            // A phone number is searched by its blind index, never by scanning
            // the encrypted column.
            $builder->groupStart()->like('c.full_name', $this->like($q));
            if (preg_match('/^\+?[0-9 -]{6,}$/', $q)) {
                $builder->orWhere('c.phone_hash', $crypto->blindIndex(Crypto::normalisePhone($q)));
            }
            $builder->groupEnd();
        }

        $list = $this->paginate($builder);
        foreach ($list['rows'] as &$row) {
            $row['phone'] = $crypto->decryptOrNull($row['phone_encrypted']);
            unset($row['phone_encrypted']);
        }
        unset($row);

        return $this->render('admin/sales/customers', 'Customers', 'admin/customers', [
            'list'    => $list,
            'dealers' => $db->table('dealers')->select('id, business_name')->where('deleted_at', null)->orderBy('business_name')->get()->getResultArray(),
            'f'       => compact('q', 'dealer'),
        ]);
    }
}
