<?php

namespace App\Controllers\Warehouse;

use App\Libraries\ScanCode;
use App\Services\WarehouseService;

/**
 * VIEW STOCK, and looking one mattress up.
 *
 * Free stock only — what can actually go on a lorry. Showing reserved or sold
 * units here would have the floor promising stock it cannot send.
 */
class Stock extends FloorController
{
    public function index(): string
    {
        $db          = db_connect();
        $warehouseId = $this->request->getGet('plant');
        $warehouseId = is_string($warehouseId) && is_uuid($warehouseId) ? $warehouseId : null;
        $search      = $this->request->getGet('q');
        $search      = is_string($search) ? mb_substr(trim($search), 0, 60) : null;

        $rows = WarehouseService::instance()->freeStock($warehouseId, $search);

        return $this->render('warehouse/stock', 'Stock', [
            'rows'       => $rows,
            'total'      => array_sum(array_map(static fn ($r) => (int) $r['available'], $rows)),
            'plant'      => $warehouseId,
            'q'          => $search,
            'warehouses' => $db->table('warehouses')->select('id, name')
                ->where('is_active', 1)->orderBy('name')->get()->getResultArray(),
        ], site_url('warehouse'));
    }

    /**
     * One mattress, scanned or typed: where it is and what has happened to it.
     *
     * The whole history is shown, because the question on the floor is
     * usually "why is this one not where I expected?" and the answer is
     * always in the events.
     */
    public function lookup(): string
    {
        $code = $this->request->getGet('code');
        $code = is_string($code) ? mb_substr(trim($code), 0, 300) : '';
        $unit = $code === '' ? null : ScanCode::find(db_connect(), $code);

        return $this->render('warehouse/lookup', 'Find a mattress', [
            'code'   => $code,
            'unit'   => $unit,
            'events' => $unit === null ? [] : db_connect()->table('mattress_events e')
                ->select('e.event_type, e.from_status, e.to_status, e.occurred_at, u.full_name AS actor, d.business_name AS dealer')
                ->join('users u', 'u.id = e.actor_user_id', 'left')
                ->join('dealers d', 'd.id = e.dealer_id', 'left')
                ->where('e.mattress_id', $unit['id'])
                ->orderBy('e.occurred_at', 'DESC')->get()->getResultArray(),
        ], site_url('warehouse'));
    }
}
