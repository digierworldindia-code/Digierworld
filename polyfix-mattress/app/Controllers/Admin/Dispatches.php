<?php

namespace App\Controllers\Admin;

use App\Services\LogisticsService;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * Warehouse → dealer consignments. DRAFT ("Ready") reserves the units;
 * sending passes custody to the dealer; the dealer confirms receipt unit by
 * unit in their portal.
 */
class Dispatches extends AdminController
{
    private const STATUSES = ['DRAFT', 'DISPATCHED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CANCELLED'];

    public function index(): string
    {
        $status = $this->oneOf('status', self::STATUSES);
        $q      = $this->filter('q', 40);
        $builder = db_connect()->table('dispatches x')
            ->select('x.id, x.dispatch_code, x.status, x.dispatched_at, x.expected_at, x.created_at, x.invoice_number, x.transporter, d.business_name dealer, d.city, w.name warehouse,
                      (SELECT COUNT(*) FROM dispatch_items i WHERE i.dispatch_id = x.id) units', false)
            ->join('dealers d', 'd.id = x.dealer_id')->join('warehouses w', 'w.id = x.warehouse_id')
            ->where('x.deleted_at', null)->orderBy('x.created_at', 'DESC');
        if ($status) {
            $builder->where('x.status', $status);
        }
        if ($q) {
            $builder->groupStart()->like('x.dispatch_code', $this->like($q))->orLike('d.business_name', $this->like($q))->orLike('x.invoice_number', $this->like($q))->groupEnd();
        }

        return $this->render('admin/dispatches/index', 'Dispatches', 'admin/dispatches', [
            'list' => $this->paginate($builder), 'statuses' => self::STATUSES, 'f' => compact('status', 'q'),
        ]);
    }

    public function new(): string
    {
        $db = db_connect();

        return $this->render('admin/dispatches/new', 'New dispatch', 'admin/dispatches', [
            'dealers'    => $db->table('dealers')->select('id, code, business_name, city')->where(['status' => 'ACTIVE', 'deleted_at' => null])->orderBy('business_name')->get()->getResultArray(),
            'warehouses' => $db->table('warehouses')->select('id, code, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'stock'      => $db->query(
                "SELECT w.name warehouse, p.name product, v.size_label, COUNT(*) n FROM mattresses m
                   JOIN product_variants v ON v.id = m.product_variant_id JOIN products p ON p.id = v.product_id
                   JOIN warehouses w ON w.id = m.current_warehouse_id
                  WHERE m.deleted_at IS NULL AND m.current_status IN ('MANUFACTURED','RETURNED')
                  GROUP BY w.name, p.name, v.size_label ORDER BY w.name, p.name, v.size_label",
            )->getResultArray(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'dealer_id'      => 'required|exact_length[36]',
            'warehouse_id'   => 'required|exact_length[36]',
            'serials'        => 'required|max_length[60000]',
            'expected_at'    => 'permit_empty|valid_date[Y-m-d]',
            'transporter'    => 'permit_empty|max_length[120]|safe_text',
            'lr_number'      => 'permit_empty|max_length[60]|safe_text',
            'vehicle_number' => 'permit_empty|max_length[30]|safe_text',
            'invoice_number' => 'permit_empty|max_length[60]|safe_text',
            'remarks'        => 'permit_empty|max_length[1000]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        // One serial per line, or separated by commas/spaces — whatever a scanner produced.
        $in['serials'] = array_values(array_unique(array_filter(array_map(
            static fn ($s) => strtoupper(trim($s)),
            preg_split('/[\s,;]+/', $in['serials']),
        ))));
        $in = array_map(static fn ($v) => $v === '' ? null : $v, $in);

        return $this->act(
            fn () => LogisticsService::instance()->createDispatch($in),
            static fn ($r) => "Dispatch {$r['dispatch_code']} is ready with {$r['item_count']} unit(s). Send it when it leaves the warehouse.",
            site_url('admin/dispatches?status=DRAFT'),
        );
    }

    public function show(string $id): string
    {
        $db = db_connect();
        $x  = $db->table('dispatches x')
            ->select('x.*, d.business_name dealer, d.code dealer_code, d.city, d.state, w.name warehouse, u.full_name created_by_name')
            ->join('dealers d', 'd.id = x.dealer_id')->join('warehouses w', 'w.id = x.warehouse_id')->join('users u', 'u.id = x.created_by', 'left')
            ->where(['x.id' => $id, 'x.deleted_at' => null])->get()->getRowArray() ?? $this->notFound();

        $items = $db->query(
            'SELECT m.id, m.serial_number, m.current_status, p.name product, v.size_label,
                    (SELECT ri.condition FROM dealer_receipt_items ri JOIN dealer_receipts r ON r.id = ri.receipt_id
                      WHERE r.dispatch_id = ? AND ri.mattress_id = m.id ORDER BY r.received_at DESC LIMIT 1) received_condition
               FROM dispatch_items i JOIN mattresses m ON m.id = i.mattress_id
               JOIN product_variants v ON v.id = m.product_variant_id JOIN products p ON p.id = v.product_id
              WHERE i.dispatch_id = ? ORDER BY m.serial_number',
            [$id, $id],
        )->getResultArray();

        return $this->render('admin/dispatches/show', 'Dispatch ' . $x['dispatch_code'], 'admin/dispatches', [
            'x'        => $x,
            'items'    => $items,
            'receipts' => $db->table('dealer_receipts r')->select('r.*, u.full_name')->join('users u', 'u.id = r.received_by_user_id', 'left')
                ->where('r.dispatch_id', $id)->orderBy('r.received_at')->get()->getResultArray(),
        ]);
    }

    public function send(string $id): RedirectResponse
    {
        $in = [];
        foreach (['transporter' => 120, 'lr_number' => 60, 'vehicle_number' => 30, 'invoice_number' => 60] as $field => $max) {
            $v = $this->post($field, $max);
            if ($v !== '') {
                $in[$field] = $v;
            }
        }

        return $this->act(
            fn () => LogisticsService::instance()->sendDispatch($id, $in),
            static fn ($r) => "Dispatch {$r['dispatch_code']} sent with {$r['item_count']} unit(s). The dealer can now see it arriving.",
        );
    }

    public function cancel(string $id): RedirectResponse
    {
        return $this->act(
            fn () => LogisticsService::instance()->cancelDispatch($id, $this->post('reason', 500)),
            static fn ($r) => "Dispatch {$r['dispatch_code']} cancelled; {$r['released']} unit(s) are back in warehouse stock.",
        );
    }
}
