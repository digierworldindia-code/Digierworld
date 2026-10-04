<?php

namespace App\Libraries;

use App\Core\Request;

/**
 * Minimal offset pagination for list pages (keeps the current query string).
 */
final class Paging
{
    public int $total = 0;

    public function __construct(public readonly int $page, public readonly int $perPage)
    {
    }

    public static function fromRequest(Request $request, int $perPage = 25): self
    {
        return new self(max(1, (int) $request->getGet('page')), max(1, min(200, $perPage)));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** URL of another page with the current filters. */
    public function url(int $page): string
    {
        $query         = service('request')->getGet();
        $query['page'] = $page;

        return current_url() . '?' . http_build_query($query);
    }
}
