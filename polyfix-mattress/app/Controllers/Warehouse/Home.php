<?php

namespace App\Controllers\Warehouse;

use App\Services\WarehouseService;

/**
 * The warehouse panel's front screen: five buttons and the figures behind
 * them, and nothing else.
 *
 * Every number is counted from the records when the page is asked for. None
 * of it is stored, so a figure here cannot disagree with what is in the
 * building.
 */
class Home extends FloorController
{
    public function index(): string
    {
        $warehouse = WarehouseService::instance();

        return $this->render('warehouse/home', 'Warehouse', [
            'figures' => $warehouse->dashboard(),
            'batches' => array_slice($warehouse->batches(5), 0, 5),
        ]);
    }
}
