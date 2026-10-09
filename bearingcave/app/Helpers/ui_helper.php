<?php

declare(strict_types=1);

/**
 * View helpers for the BearingCave design system (autoloaded).
 * All output is escaped; helpers that accept raw HTML say so explicitly.
 */
if (! function_exists('status_badge')) {
    function status_badge(?string $status, ?string $label = null): string
    {
        if ($status === null || $status === '') {
            return '<span class="badge-soft neutral">—</span>';
        }
        $map = [
            'success' => ['verified', 'approved', 'passed', 'published', 'active', 'paid', 'completed', 'delivered', 'accepted', 'resolved', 'sent', 'clear', 'valid', 'imported', 'pass', 'processed', 'succeeded', 'LOW', 'quoted_supplier', 'responded', 'validated', 'awarded'],
            'warning' => ['pending', 'pending_review', 'pending_payment', 'pending_supplier_confirmation', 'pending_approval', 'in_review', 'submitted', 'under_review', 'quoted', 'awaiting_payment', 'awaiting_response', 'requested', 'shortlisted', 'conditional', 'potential_match', 'duplicate', 'changes_requested', 'MEDIUM', 'invited', 'unpaid', 'queued', 'uploaded', 'mapped', 'scheduled', 'open', 'new', 'initiated'],
            'danger'  => ['rejected', 'failed', 'suspended', 'cancelled', 'expired', 'declined', 'fail', 'confirmed_match', 'error', 'disputed', 'HIGH', 'void', 'revoked', 'exception', 'closed_lost'],
            'info'    => ['distributed', 'evaluation', 'in_transit', 'shipped', 'booked', 'assigned', 'in_progress', 'in_fulfilment', 'confirmed', 'report_uploaded', 'viewed', 'picked_up', 'customs', 'out_for_delivery', 'suggested', 'contacted', 'quoted'],
        ];
        $tone = 'neutral';
        foreach ($map as $t => $list) {
            if (in_array($status, $list, true)) {
                $tone = $t;

                break;
            }
        }
        $label ??= ucfirst(str_replace('_', ' ', strtolower($status)));
        if (in_array($status, ['LOW', 'MEDIUM', 'HIGH'], true)) {
            $label = $status;
        }

        return '<span class="badge-soft ' . $tone . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('money')) {
    function money($amount, ?string $currency = 'INR', int $decimals = 2): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'];
        $sym     = $symbols[$currency ?? ''] ?? (($currency ?? '') . ' ');

        return esc($sym . number_format((float) $amount, $decimals));
    }
}

if (! function_exists('fdate')) {
    function fdate(?string $date, string $format = 'd M Y'): string
    {
        return $date ? esc(date($format, strtotime($date))) : '—';
    }
}

if (! function_exists('fdt')) {
    function fdt(?string $date): string
    {
        return fdate($date, 'd M Y, H:i');
    }
}

if (! function_exists('form_errors')) {
    /**
     * Validation errors (current request or flashed from the previous one).
     */
    function form_errors(): array
    {
        static $errors = null;
        if ($errors === null) {
            $errors = (array) (session('_ci_validation_errors') ?? []);
            if ($errors === [] && service('validation')->getErrors()) {
                $errors = service('validation')->getErrors();
            }
        }

        return $errors;
    }
}

if (! function_exists('field')) {
    /**
     * Renders a labelled Bootstrap input with old-value preservation and error.
     *
     * @param array{type?:string,required?:bool,help?:string,placeholder?:string,attrs?:string,class?:string,prepend?:string,append?:string} $o
     */
    function field(string $name, string $label, $value = null, array $o = []): string
    {
        $id    = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $type  = $o['type'] ?? 'text';
        $err   = form_errors()[$name] ?? null;
        $val   = old($name, $value);
        $req   = ! empty($o['required']);
        $input = '<input type="' . esc($type, 'attr') . '" class="form-control' . ($err ? ' is-invalid' : '') . '" id="' . $id . '" name="' . esc($name, 'attr') . '"'
            . ($type !== 'password' && $type !== 'file' ? ' value="' . esc((string) $val, 'attr') . '"' : '')
            . ($req ? ' required' : '')
            . (isset($o['placeholder']) ? ' placeholder="' . esc($o['placeholder'], 'attr') . '"' : '')
            . (isset($o['help']) ? ' aria-describedby="' . $id . '_help"' : '')
            . ' ' . ($o['attrs'] ?? '') . '>';
        if (isset($o['prepend']) || isset($o['append'])) {
            $input = '<div class="input-group">' . (isset($o['prepend']) ? '<span class="input-group-text">' . esc($o['prepend']) . '</span>' : '') . $input
                . (isset($o['append']) ? '<span class="input-group-text">' . esc($o['append']) . '</span>' : '') . '</div>';
        }

        return '<div class="' . esc($o['class'] ?? 'mb-3', 'attr') . '"><label class="form-label" for="' . $id . '">' . esc($label) . ($req ? '<span class="required-mark" aria-hidden="true">*</span>' : '') . '</label>'
            . $input
            . ($err ? '<div class="invalid-feedback d-block">' . esc($err) . '</div>' : '')
            . (isset($o['help']) ? '<div class="form-text" id="' . $id . '_help">' . esc($o['help']) . '</div>' : '')
            . '</div>';
    }
}

if (! function_exists('select_field')) {
    /**
     * @param array<string|int,string> $options value => label
     */
    function select_field(string $name, string $label, array $options, $selected = null, array $o = []): string
    {
        $id       = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $err      = form_errors()[$name] ?? null;
        $multiple = ! empty($o['multiple']);
        $sel      = old(rtrim($name, '[]'), $selected);
        $selArr   = array_map('strval', is_array($sel) ? $sel : [(string) $sel]);
        $req      = ! empty($o['required']);
        $html     = '<div class="' . esc($o['class'] ?? 'mb-3', 'attr') . '">';
        if ($label !== '') {
            $html .= '<label class="form-label" for="' . $id . '">' . esc($label) . ($req ? '<span class="required-mark" aria-hidden="true">*</span>' : '') . '</label>';
        }
        $html .= '<select class="form-select' . ($err ? ' is-invalid' : '') . '" id="' . $id . '" name="' . esc($name, 'attr') . '"' . ($multiple ? ' multiple' : '') . ($req ? ' required' : '') . ' ' . ($o['attrs'] ?? '') . '>';
        if (isset($o['placeholder'])) {
            $html .= '<option value="">' . esc($o['placeholder']) . '</option>';
        }
        foreach ($options as $v => $l) {
            $html .= '<option value="' . esc((string) $v, 'attr') . '"' . (in_array((string) $v, $selArr, true) ? ' selected' : '') . '>' . esc($l) . '</option>';
        }
        $html .= '</select>';
        $html .= $err ? '<div class="invalid-feedback d-block">' . esc($err) . '</div>' : '';
        $html .= isset($o['help']) ? '<div class="form-text">' . esc($o['help']) . '</div>' : '';

        return $html . '</div>';
    }
}

if (! function_exists('textarea_field')) {
    function textarea_field(string $name, string $label, $value = null, array $o = []): string
    {
        $id  = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name);
        $err = form_errors()[$name] ?? null;
        $req = ! empty($o['required']);

        return '<div class="' . esc($o['class'] ?? 'mb-3', 'attr') . '"><label class="form-label" for="' . $id . '">' . esc($label) . ($req ? '<span class="required-mark" aria-hidden="true">*</span>' : '') . '</label>'
            . '<textarea class="form-control' . ($err ? ' is-invalid' : '') . '" id="' . $id . '" name="' . esc($name, 'attr') . '" rows="' . (int) ($o['rows'] ?? 3) . '"' . ($req ? ' required' : '') . (isset($o['placeholder']) ? ' placeholder="' . esc($o['placeholder'], 'attr') . '"' : '') . '>' . esc((string) old($name, $value)) . '</textarea>'
            . ($err ? '<div class="invalid-feedback d-block">' . esc($err) . '</div>' : '')
            . (isset($o['help']) ? '<div class="form-text">' . esc($o['help']) . '</div>' : '')
            . '</div>';
    }
}

if (! function_exists('check_field')) {
    function check_field(string $name, string $label, bool $checked = false, array $o = []): string
    {
        $id  = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $name) . '_' . substr(md5($label), 0, 4);
        $old = old($name);
        $on  = $old !== null ? (bool) $old : $checked;

        return '<div class="form-check ' . esc($o['class'] ?? 'mb-2', 'attr') . '">'
            . '<input type="hidden" name="' . esc($name, 'attr') . '" value="0">'
            . '<input class="form-check-input" type="checkbox" value="' . esc((string) ($o['value'] ?? '1'), 'attr') . '" id="' . $id . '" name="' . esc($name, 'attr') . '"' . ($on ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . esc($label) . '</label>'
            . (isset($o['help']) ? '<div class="form-text mt-0">' . esc($o['help']) . '</div>' : '')
            . '</div>';
    }
}

if (! function_exists('country_options')) {
    function country_options(): array
    {
        static $opts = null;
        if ($opts === null) {
            $opts = array_column(db_connect()->table('countries')->select('iso2, name')->where('is_enabled', 1)->orderBy('name')->get()->getResultArray(), 'name', 'iso2');
        }

        return $opts;
    }
}

if (! function_exists('country_name')) {
    function country_name(?string $iso): string
    {
        return $iso ? (country_options()[$iso] ?? $iso) : '—';
    }
}

if (! function_exists('currency_options')) {
    function currency_options(): array
    {
        static $opts = null;
        if ($opts === null) {
            $rows = db_connect()->table('currencies')->select('code, name')->where('is_enabled', 1)->orderBy('is_base', 'DESC')->orderBy('code')->get()->getResultArray();
            $opts = [];
            foreach ($rows as $r) {
                $opts[$r['code']] = $r['code'] . ' — ' . $r['name'];
            }
        }

        return $opts;
    }
}

if (! function_exists('category_options')) {
    function category_options(bool $withParents = true): array
    {
        $rows = db_connect()->table('categories c')->select('c.id, c.name, p.name AS parent')
            ->join('categories p', 'p.id = c.parent_id', 'left')->where('c.is_active', 1)
            ->orderBy('COALESCE(p.sort_order, c.sort_order)', '', false)->orderBy('c.parent_id IS NOT NULL', '', false)->orderBy('c.sort_order')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            if (! $withParents && $r['parent'] === null) {
                continue;
            }
            $out[$r['id']] = $r['parent'] ? $r['parent'] . ' › ' . $r['name'] : $r['name'];
        }

        return $out;
    }
}

if (! function_exists('brand_options')) {
    function brand_options(): array
    {
        return array_column(db_connect()->table('brands')->select('id, name')->where('is_active', 1)->orderBy('name')->get()->getResultArray(), 'name', 'id');
    }
}

if (! function_exists('verified_badge')) {
    function verified_badge(?array $company, bool $withLabel = true): string
    {
        if ($company === null) {
            return '';
        }
        $ent = service('entitlements');
        if ($company['company_type'] === 'supplier' && $ent->isVerifiedSupplier($company)) {
            return '<span class="badge-verified" title="Verified by BearingCave"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>' . ($withLabel ? 'Verified Supplier' : '<span class="visually-hidden">Verified</span>') . '</span>';
        }
        if ($company['company_type'] === 'buyer' && $ent->isVerifiedBuyer($company)) {
            return '<span class="badge-verified" title="Verified buyer"><i class="bi bi-patch-check-fill" aria-hidden="true"></i>' . ($withLabel ? 'Verified Buyer' : '<span class="visually-hidden">Verified</span>') . '</span>';
        }

        return '';
    }
}

if (! function_exists('sample_badge')) {
    function sample_badge(?array $row): string
    {
        return ! empty($row['is_sample']) ? '<span class="badge-sample" title="Demo record — not a real business">SAMPLE DATA</span>' : '';
    }
}

if (! function_exists('pending_badge')) {
    function pending_badge(string $text = 'Pending client approval'): string
    {
        return '<span class="pending-approval"><i class="bi bi-hourglass-split" aria-hidden="true"></i> ' . esc($text) . '</span>';
    }
}

if (! function_exists('empty_state')) {
    /**
     * @param string $actionHtml raw HTML (caller escapes)
     */
    function empty_state(string $icon, string $title, string $text = '', string $actionHtml = ''): string
    {
        return '<div class="empty-state"><div class="empty-icon"><i class="bi ' . esc($icon, 'attr') . '" aria-hidden="true"></i></div><h3>' . esc($title) . '</h3>'
            . ($text !== '' ? '<p class="mb-3">' . esc($text) . '</p>' : '') . $actionHtml . '</div>';
    }
}

if (! function_exists('post_button')) {
    /**
     * A one-button POST form (CSRF protected).
     *
     * @param array<string,string> $hidden
     */
    function post_button(string $url, string $label, string $class = 'btn btn-sm btn-outline-primary', ?string $confirm = null, array $hidden = [], string $icon = ''): string
    {
        $h = '<form method="post" action="' . esc(site_url($url), 'attr') . '" class="d-inline"' . ($confirm ? ' data-confirm="' . esc($confirm, 'attr') . '"' : '') . '>' . csrf_field();
        foreach ($hidden as $k => $v) {
            $h .= '<input type="hidden" name="' . esc($k, 'attr') . '" value="' . esc($v, 'attr') . '">';
        }

        return $h . '<button type="submit" class="' . esc($class, 'attr') . '">' . ($icon ? '<i class="bi ' . esc($icon, 'attr') . ' me-1" aria-hidden="true"></i>' : '') . esc($label) . '</button></form>';
    }
}

if (! function_exists('pagination_links')) {
    /**
     * Pagination for manual (non-Model) result sets.
     */
    function pagination_links(int $page, int $pages, array $query = []): string
    {
        if ($pages <= 1) {
            return '';
        }
        $url = static function (int $p) use ($query) {
            return current_url() . '?' . http_build_query(array_merge($query, ['page' => $p]));
        };
        $h = '<nav aria-label="Pagination"><ul class="pagination pagination-sm justify-content-center mt-4">';
        $h .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . esc($url(max(1, $page - 1)), 'attr') . '">Previous</a></li>';
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        for ($p = $start; $p <= $end; $p++) {
            $h .= '<li class="page-item' . ($p === $page ? ' active" aria-current="page' : '') . '"><a class="page-link" href="' . esc($url($p), 'attr') . '">' . $p . '</a></li>';
        }
        $h .= '<li class="page-item' . ($page >= $pages ? ' disabled' : '') . '"><a class="page-link" href="' . esc($url(min($pages, $page + 1)), 'attr') . '">Next</a></li>';

        return $h . '</ul></nav>';
    }
}

if (! function_exists('product_image_url')) {
    function product_image_url(?string $path): ?string
    {
        return $path ? base_url('uploads/products/' . ltrim($path, '/')) : null;
    }
}

if (! function_exists('age_label')) {
    function age_label(?string $date): string
    {
        $b = \App\Services\InventoryService::ageBucket($date);

        return $b ? \App\Services\InventoryService::AGE_BUCKETS[$b] : '—';
    }
}

if (! function_exists('match_note')) {
    /**
     * Explains how a search result matched, without implying interchangeability.
     */
    function match_note(?string $type): string
    {
        return match ($type) {
            'oem'                      => '<span class="match-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Matched OEM number</span>',
            'alternate'                => '<span class="match-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Matched an alternate number listed by the supplier</span>',
            'declared_cross_reference' => '<span class="match-note"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Supplier-declared cross reference — interchangeability not verified</span>',
            'verified_cross_reference' => '<span class="match-note"><i class="bi bi-check2-circle" aria-hidden="true"></i> Cross reference verified by BearingCave</span>',
            default                    => '',
        };
    }
}
