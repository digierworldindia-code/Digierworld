<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\CategoryModel;

class Categories extends AdminController
{
    public function index(): string
    {
        $rows = db_connect()->table('categories c')->select('c.*, p.name AS parent_name, (SELECT COUNT(*) FROM products x WHERE x.category_id = c.id AND x.deleted_at IS NULL) AS products', false)
            ->join('categories p', 'p.id = c.parent_id', 'left')->orderBy('COALESCE(p.sort_order, c.sort_order)', '', false)->orderBy('c.parent_id IS NOT NULL', '', false)->orderBy('c.sort_order')->get()->getResultArray();

        return $this->page('categories', ['title' => 'Categories', 'rows' => $rows, 'parents' => array_column(array_filter($rows, static fn ($r) => $r['parent_id'] === null), 'name', 'id')]);
    }

    public function save(?int $id = null)
    {
        if ($r = $this->invalid(['name' => 'required|max_length[120]', 'parent_id' => 'permit_empty|is_natural_no_zero', 'sort_order' => 'permit_empty|integer', 'icon' => 'permit_empty|alpha_dash|max_length[60]', 'meta_description' => 'permit_empty|max_length[255]'])) {
            return $r;
        }
        $m    = model(CategoryModel::class);
        $data = ['name' => trim((string) $this->request->getPost('name')), 'parent_id' => (int) $this->request->getPost('parent_id') ?: null, 'sort_order' => (int) $this->request->getPost('sort_order'),
            'icon' => $this->request->getPost('icon') ?: null, 'is_active' => $this->request->getPost('is_active') === '0' ? 0 : 1, 'description' => $this->request->getPost('description') ?: null,
            'meta_title' => $this->request->getPost('meta_title') ?: null, 'meta_description' => $this->request->getPost('meta_description') ?: null];
        if ($id && $data['parent_id'] === $id) {
            return redirect()->back()->with('error', 'A category cannot be its own parent.');
        }
        if ($id) {
            $m->find($id) ?? $this->notFound();
            $m->update($id, $data);
        } else {
            $slug = make_slug($data['name']);
            $i = 2;
            while ($m->where('slug', $slug)->countAllResults() > 0) {
                $slug = make_slug($data['name']) . '-' . $i++;
            }
            $m->insert($data + ['slug' => $slug]);
        }
        service('audit')->log('category.saved', ['entity_type' => 'category', 'entity_id' => $id, 'description' => $data['name']]);

        return redirect()->back()->with('success', 'Category saved.');
    }
}
