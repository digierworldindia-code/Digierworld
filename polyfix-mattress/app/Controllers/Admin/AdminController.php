<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Database\BaseBuilder;

/**
 * Shared by the admin console. Lists are paginated on the server — no screen
 * ever loads a whole table.
 */
abstract class AdminController extends BaseController
{
    protected const PER_PAGE = 25;

    protected function render(string $view, string $title, string $nav, array $data = []): string
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        return view($view, $data + ['title' => $title, 'nav' => $nav, 'ctx' => $this->ctx]);
    }

    /**
     * Runs a list query one page at a time.
     *
     * @return array{rows: list<array>, total: int, page: int, pages: int, per: int}
     */
    protected function paginate(BaseBuilder $builder, int $per = self::PER_PAGE): array
    {
        $page  = max(1, (int) $this->request->getGet('page'));
        $total = (clone $builder)->countAllResults(false);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);
        $rows  = $builder->limit($per, ($page - 1) * $per)->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per];
    }

    /** A GET filter value, trimmed and bounded, or null. */
    protected function filter(string $name, int $max = 80): ?string
    {
        $value = $this->request->getGet($name);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }

    /** A filter restricted to known values (an enum), or null. */
    protected function oneOf(string $name, array $allowed): ?string
    {
        $value = $this->filter($name);

        return in_array($value, $allowed, true) ? $value : null;
    }

    /** LIKE-safe search term. */
    protected function like(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    protected function post(string $name, int $max = 2000): string
    {
        return mb_substr(trim((string) $this->request->getPost($name)), 0, $max);
    }
}
