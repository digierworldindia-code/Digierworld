<?php

namespace App\Controllers\Admin;

use App\Services\LogisticsService;
use CodeIgniter\HTTP\RedirectResponse;

/** Manufacturing batches, and the production run that issues serials and QR tokens. */
class Batches extends AdminController
{
    public function index(): string
    {
        $db = db_connect();
        $builder = $db->table('manufacturing_batches b')
            ->select('b.*, w.name warehouse, (SELECT COUNT(*) FROM mattresses m WHERE m.batch_id = b.id AND m.deleted_at IS NULL) serialised', false)
            ->join('warehouses w', 'w.id = b.warehouse_id')
            ->orderBy('b.manufactured_on', 'DESC')->orderBy('b.batch_code', 'DESC');

        return $this->render('admin/batches/index', 'Batches', 'admin/batches', [
            'list'       => $this->paginate($builder),
            'warehouses' => $db->table('warehouses')->select('id, code, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
        ]);
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'warehouse_id'       => 'required|exact_length[36]',
            'manufactured_on'    => 'required|valid_date[Y-m-d]',
            'planned_quantity'   => 'required|is_natural_no_zero|less_than_equal_to[100000]',
            'line_supervisor'    => 'permit_empty|max_length[120]|safe_text',
            'quality_checked_by' => 'permit_empty|max_length[120]|safe_text',
            'notes'              => 'permit_empty|max_length[1000]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }
        if ($in['manufactured_on'] > local_time(utc_now(), 'Y-m-d')) {
            return redirect()->back()->withInput()->with('error', 'The manufacturing date cannot be in the future.');
        }

        return $this->act(
            fn () => LogisticsService::instance()->createBatch(array_map(static fn ($v) => $v === '' ? null : $v, $in)),
            static fn ($r) => "Batch {$r['batch_code']} created. Produce units against it below.",
            null,
        );
    }

    public function show(string $id): string
    {
        $db    = db_connect();
        $batch = $db->table('manufacturing_batches b')->select('b.*, w.name warehouse, u.full_name created_by_name')
            ->join('warehouses w', 'w.id = b.warehouse_id')->join('users u', 'u.id = b.created_by', 'left')
            ->where('b.id', $id)->get()->getRowArray() ?? $this->notFound();

        return $this->render('admin/batches/show', 'Batch ' . $batch['batch_code'], 'admin/batches', [
            'b'        => $batch,
            'mix'      => $db->query(
                'SELECT p.name, v.size_label, COUNT(*) n FROM mattresses m JOIN product_variants v ON v.id = m.product_variant_id
                   JOIN products p ON p.id = v.product_id WHERE m.batch_id = ? AND m.deleted_at IS NULL GROUP BY p.name, v.size_label ORDER BY p.name, v.size_label',
                [$id],
            )->getResultArray(),
            'variants' => $db->table('product_variants v')->select('v.id, v.sku, v.size_label, p.name product')
                ->join('products p', 'p.id = v.product_id')
                ->where(['v.deleted_at' => null, 'p.deleted_at' => null])->whereIn('v.status', ['PUBLISHED', 'DRAFT'])
                ->orderBy('p.sort_order')->orderBy('v.sort_order')->get()->getResultArray(),
        ]);
    }

    public function produce(string $id): RedirectResponse
    {
        $quantities = (array) $this->request->getPost('qty');
        $items      = [];
        foreach ($quantities as $variantId => $qty) {
            if (is_uuid((string) $variantId) && ctype_digit((string) $qty) && (int) $qty > 0) {
                $items[] = ['product_variant_id' => $variantId, 'quantity' => (int) $qty];
            }
        }

        return $this->act(
            fn () => LogisticsService::instance()->produce($id, $items),
            static fn ($r) => "{$r['count']} unit(s) serialised: {$r['first_serial']} to {$r['last_serial']}.",
            site_url('admin/mattresses?batch=' . $id),
        );
    }
}
