<?php

namespace App\Controllers\Warehouse;

use App\Services\DispatchPicking;
use App\Services\LogisticsService;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PREPARE DISPATCH and DISPATCH HISTORY.
 *
 * Picking a consignment is a draft that units are added to — by ticking a
 * batch, by scanning, or one at a time — and confirming it is what sends it.
 * No screen here has a box to type a serial into as part of the normal job.
 */
class Dispatch extends FloorController
{
    /** Consignments being picked, and the button to start another. */
    public function index(): string
    {
        $db = db_connect();

        return $this->render('warehouse/dispatch/index', 'Prepare dispatch', [
            'drafts'     => DispatchPicking::instance()->openDrafts(),
            'dealers'    => $db->table('dealers')->select('id, code, business_name, city')
                ->where(['status' => 'ACTIVE', 'deleted_at' => null])
                ->orderBy('business_name')->get()->getResultArray(),
            'warehouses' => $db->table('warehouses')->select('id, name')
                ->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
        ], site_url('warehouse'));
    }

    public function start(): RedirectResponse
    {
        $in = $this->validated([
            'warehouse_id' => 'required|exact_length[36]',
            'dealer_id'    => 'required|exact_length[36]',
        ]);
        if ($in instanceof RedirectResponse) {
            return $in;
        }

        try {
            $dispatch = DispatchPicking::instance()->start($in);
        } catch (\App\Exceptions\AppException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('warehouse/dispatch/' . $dispatch['id']))
            ->with('success', "Consignment {$dispatch['dispatch_code']} opened. Scan or tick the mattresses going on it.");
    }

    /** The picking screen: what is on the lorry so far, and how to add more. */
    public function show(string $id): string
    {
        $picking = DispatchPicking::instance();
        $state   = $picking->picking($id);

        if ($state['dispatch']['status'] !== 'DRAFT') {
            return $this->render('warehouse/dispatch/sent', 'Consignment ' . $state['dispatch']['dispatch_code'], [
                'd' => $state['dispatch'], 'items' => $state['items'],
            ], site_url('warehouse/dispatches'));
        }

        $batchId = $this->request->getGet('batch');
        $batchId = is_string($batchId) && is_uuid($batchId) ? $batchId : null;
        $variant = $this->request->getGet('size');
        $variant = is_string($variant) && is_uuid($variant) ? $variant : null;

        return $this->render('warehouse/dispatch/pick', 'Pick for ' . $state['dispatch']['dealer'], [
            'd'        => $state['dispatch'],
            'items'    => $state['items'],
            'counter'  => $state['counter'],
            'batches'  => $picking->batchesWithStock($state['dispatch']['warehouse_id']),
            'pickable' => $batchId === null ? [] : $picking->pickable($state['dispatch']['warehouse_id'], $batchId, $variant),
            'batchId'  => $batchId,
            'variant'  => $variant,
            'scanner'  => true,
        ], site_url('warehouse/dispatch'));
    }

    /** Tick boxes: add everything that was ticked, or nothing. */
    public function add(string $id): RedirectResponse
    {
        $ids = array_values(array_filter((array) $this->request->getPost('units'), 'is_uuid'));

        return $this->act(
            static fn () => DispatchPicking::instance()->addUnits($id, $ids),
            static fn ($r) => $r['added'] === 0
                ? "Nothing new to add — all {$r['skipped']} were already on {$r['dispatch_code']}."
                : "{$r['added']} added. {$r['total']} now on {$r['dispatch_code']}.",
            site_url('warehouse/dispatch/' . $id),
        );
    }

    public function remove(string $id): RedirectResponse
    {
        $ids = array_values(array_filter((array) $this->request->getPost('units'), 'is_uuid'));

        return $this->act(
            static fn () => DispatchPicking::instance()->removeUnits($id, $ids),
            static fn ($r) => "{$r['removed']} taken off. {$r['total']} still on {$r['dispatch_code']}.",
            site_url('warehouse/dispatch/' . $id),
        );
    }

    /** One scan onto the consignment. Answers JSON. */
    public function scan(string $id): ResponseInterface
    {
        $code = $this->scannedCode();
        if ($code === '') {
            return $this->scanFailed('Nothing scanned', 'No code was read. Try again, or type the serial.');
        }

        try {
            return $this->scanned(DispatchPicking::instance()->scan($id, $code, $this->scanSource()));
        } catch (\App\Exceptions\AppException $e) {
            return $this->scanFailed('Not added', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[{rid}] dispatch scan failed: {msg} at {file}:{line}', [
                'rid' => $this->ctx->requestId, 'msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);

            return $this->scanFailed('Not added', 'Something went wrong. Reference ' . $this->ctx->requestId . '.');
        }
    }

    /** The confirmation screen: who it is for, what is on it, and the lorry details. */
    public function confirm(string $id): string
    {
        $state = DispatchPicking::instance()->picking($id);

        return $this->render('warehouse/dispatch/confirm', 'Confirm ' . $state['dispatch']['dispatch_code'], [
            'd'       => $state['dispatch'],
            'items'   => $state['items'],
            'counter' => $state['counter'],
            'mix'     => $this->mix($state['items']),
        ], site_url('warehouse/dispatch/' . $id));
    }

    /**
     * CONFIRM DISPATCH. One transaction: every unit moves and the dealer's
     * incoming record appears, or nothing happens.
     */
    public function send(string $id): RedirectResponse
    {
        $input = [
            'transporter'    => $this->post('transporter', 120),
            'lr_number'      => $this->post('lr_number', 60),
            'vehicle_number' => $this->post('vehicle_number', 30),
            'invoice_number' => $this->post('invoice_number', 60),
        ];

        return $this->act(
            static fn () => LogisticsService::instance()->sendDispatch($id, $input),
            static fn ($r) => "{$r['dispatch_code']} sent with {$r['item_count']} mattresses. The dealer can now see it coming.",
            site_url('warehouse/dispatches'),
        );
    }

    public function cancel(string $id): RedirectResponse
    {
        return $this->act(
            fn () => LogisticsService::instance()->cancelDispatch($id, $this->post('reason', 500)),
            static fn ($r) => "{$r['dispatch_code']} cancelled. {$r['released']} mattresses are back in stock.",
            site_url('warehouse/dispatch'),
        );
    }

    /** What has gone out. */
    public function history(): string
    {
        return $this->render('warehouse/dispatch/history', 'Dispatch history', [
            'rows' => DispatchPicking::instance()->history(40),
        ], site_url('warehouse'));
    }

    /**
     * The consignment summarised by model and size — what goes on the
     * paperwork, and what the person loading counts against.
     *
     * @param list<array<string,mixed>> $items
     *
     * @return list<array{product:string, size:string, n:int}>
     */
    private function mix(array $items): array
    {
        $mix = [];
        foreach ($items as $item) {
            $key = $item['product_name'] . "\0" . $item['size_label'];
            $mix[$key] ??= ['product' => $item['product_name'], 'size' => $item['size_label'], 'n' => 0];
            $mix[$key]['n']++;
        }
        ksort($mix);

        return array_values($mix);
    }
}
