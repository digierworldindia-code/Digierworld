<?php
/**
 * Renders a page's CMS sections in order. Each block type has its own partial;
 * a type with no partial is skipped (and logged) rather than breaking the page,
 * so content edited ahead of a deploy cannot take the site down.
 *
 * @var list<array> $sections
 * @var array       $slots    data the controller supplies for live blocks
 */
$slots ??= [];
foreach ($sections as $i => $section) {
    $type = preg_replace('/[^a-z-]/', '', (string) ($section['type'] ?? ''));
    $file = APPPATH . 'Views/sections/' . $type . '.php';
    if ($type === '' || ! is_file($file)) {
        log_message('warning', 'CMS section type "{type}" has no renderer; skipped', ['type' => $type]);
        continue;
    }
    echo view('sections/' . $type, ['s' => $section, 'slots' => $slots, 'alt' => $i % 2 === 0]);
}
