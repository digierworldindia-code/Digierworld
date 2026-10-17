<?php

namespace App\Controllers\Warehouse;

use App\Services\WarehouseService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Batches on the floor: open one, count units into it by scanning, print the
 * label sheet.
 *
 * The console has its own two-step version of this for the office (create a
 * batch, then produce against it). This is the one-screen version for a
 * phone, and both write the same records.
 */
class Batches extends FloorController
{
    /** Open batches first — that is the work in hand. */
    public function index(): string
    {
        return $this->render('warehouse/batches/index', 'Batches', [
            'batches' => WarehouseService::instance()->batches(40),
        ], site_url('warehouse'));
    }

    public function new(): string
    {
        $db = db_connect();

        return $this->render('warehouse/batches/new', 'New batch', [
            'warehouses' => $db->table('warehouses')->select('id, code, name')
                ->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
            'variants' => $db->table('product_variants v')
                ->select('v.id, v.sku, v.size_label, p.name AS product')
                ->join('products p', 'p.id = v.product_id')
                ->where(['v.deleted_at' => null, 'p.deleted_at' => null])
                ->whereIn('v.status', ['PUBLISHED', 'DRAFT'])
                ->orderBy('p.sort_order')->orderBy('v.sort_order')->get()->getResultArray(),
        ], site_url('warehouse/batches'));
    }

    public function create(): RedirectResponse
    {
        $in = $this->validated([
            'warehouse_id'    => 'required|exact_length[36]',
            'manufactured_on' => 'required|valid_date[Y-m-d]',
            'line_supervisor' => 'permit_empty|max_length[120]|safe_text',
            'notes'           => 'permit_empty|max_length[1000]|safe_text',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        // One number per size, straight off the form. Anything that is not a
        // size and a count is ignored rather than guessed at.
        $mix = [];
        foreach ((array) $this->request->getPost('qty') as $variantId => $quantity) {
            if (is_uuid((string) $variantId) && ctype_digit((string) $quantity) && (int) $quantity > 0) {
                $mix[] = ['product_variant_id' => $variantId, 'quantity' => (int) $quantity];
            }
        }

        return $this->act(
            static fn () => WarehouseService::instance()->openBatch($in, $mix),
            static fn ($r) => "Batch {$r['batch_code']} opened with {$r['count']} mattresses ({$r['first_serial']} to {$r['last_serial']}). Print the labels, then scan them in.",
            null,
        );
    }

    public function show(string $id): string
    {
        $warehouse = WarehouseService::instance();
        $batch     = $this->batchOr404($id);

        return $this->render('warehouse/batches/show', 'Batch ' . $batch['batch_code'], [
            'b'        => $batch,
            'progress' => $warehouse->batchProgress($id),
            'mix'      => db_connect()->query(
                'SELECT p.name AS product, v.size_label, COUNT(*) AS n,
                        SUM(CASE WHEN s.id IS NULL THEN 0 ELSE 1 END) AS scanned
                   FROM mattresses m
                   JOIN product_variants v ON v.id = m.product_variant_id
                   JOIN products p ON p.id = v.product_id
                   LEFT JOIN warehouse_scans s
                        ON s.mattress_id = m.id AND s.purpose = ? AND s.batch_id = m.batch_id
                  WHERE m.batch_id = ? AND m.deleted_at IS NULL
                  GROUP BY p.name, v.size_label
                  ORDER BY p.name, v.size_label',
                ['PRODUCTION', $id],
            )->getResultArray(),
        ], site_url('warehouse/batches'));
    }

    /** The scanning screen: camera open, count on top, nothing to read. */
    public function scan(string $id): string
    {
        $batch = $this->batchOr404($id);

        return $this->render('warehouse/batches/scan', 'Scan into ' . $batch['batch_code'], [
            'b'        => $batch,
            'progress' => WarehouseService::instance()->batchProgress($id),
            'scanner'  => true,
        ], site_url('warehouse/batches/' . $id));
    }

    /** One scan. Answers JSON; never redirects. */
    public function record(string $id): ResponseInterface
    {
        $code = $this->scannedCode();
        if ($code === '') {
            return $this->scanFailed('Nothing scanned', 'No code was read. Try again, or type the serial.');
        }

        try {
            return $this->scanned(
                WarehouseService::instance()->productionScan($id, $code, $this->scanSource()),
            );
        } catch (\App\Exceptions\AppException $e) {
            return $this->scanFailed('Not recorded', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[{rid}] warehouse scan failed: {msg} at {file}:{line}', [
                'rid' => $this->ctx->requestId, 'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            return $this->scanFailed('Not recorded', 'Something went wrong. Reference ' . $this->ctx->requestId . '.');
        }
    }

    /**
     * The printable label sheet for a whole batch.
     *
     * This is what makes "no typing" true: the serials and QR codes are
     * generated when the batch opens, printed here, and stuck on the line.
     */
    public function labels(string $id): string
    {
        $batch = $this->batchOr404($id);
        $units = WarehouseService::instance()->batchUnits($id);

        // Rendered as SVG at print time rather than stored: a label is a view
        // of the record, and the record is the serial and the token.
        foreach ($units as $i => $unit) {
            $units[$i]['qr']      = \App\Libraries\Labels::qrSvg($unit['qr_token'], 3);
            $units[$i]['barcode'] = \App\Libraries\Labels::barcodeSvg($unit['serial_number']);
        }

        return $this->render('warehouse/batches/labels', 'Labels ' . $batch['batch_code'], [
            'b' => $batch, 'units' => $units,
        ], site_url('warehouse/batches/' . $id));
    }

    public function close(string $id): RedirectResponse
    {
        return $this->act(
            fn () => WarehouseService::instance()->closeBatch($id, $this->post('reason', 500)),
            static fn ($r) => "Batch {$r['batch_code']} closed at {$r['scanned']} of {$r['produced']}.",
            site_url('warehouse/batches'),
        );
    }

    /** @return array<string,mixed> */
    private function batchOr404(string $id): array
    {
        return db_connect()->table('manufacturing_batches b')
            ->select('b.*, w.name AS warehouse')
            ->join('warehouses w', 'w.id = b.warehouse_id')
            ->where('b.id', $id)->get()->getRowArray() ?? $this->notFound();
    }
}
