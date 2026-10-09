<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

class Inventory extends AdminController
{
    public function index(): string
    {
        $b = db_connect()->table('inventory_movements m')->select('m.*, p.part_number, p.name, p.id AS pid, c.legal_name')
            ->join('products p', 'p.id = m.product_id')->join('companies c', 'c.id = p.company_id');
        if ($t = $this->request->getGet('type')) {
            $b->where('m.movement_type', $t);
        }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $b->groupStart()->like('p.part_number_norm', normalize_part($q))->orLike('c.legal_name', $q)->groupEnd();
        }
        $page = max(1, (int) $this->request->getGet('page'));
        $total = (clone $b)->countAllResults(false);

        return $this->page('inventory', ['title' => 'Inventory movements', 'rows' => $b->orderBy('m.id', 'DESC')->limit(50, ($page - 1) * 50)->get()->getResultArray(), 'page' => $page, 'pages' => (int) ceil(max(1, $total) / 50)]);
    }
}
