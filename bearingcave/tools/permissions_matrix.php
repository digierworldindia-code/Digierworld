<?php
// Prints the staff permission matrix from app/Config/AuthGroups.php as Markdown.
// Usage: php tools/permissions_matrix.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Config/AuthGroups.php';
// Read property defaults without booting the framework.
$g = (new ReflectionClass(Config\AuthGroups::class))->newInstanceWithoutConstructor();
$staff = $g->staffGroups;
$has = static function (string $group, string $perm) use ($g): bool {
    foreach ($g->matrix[$group] ?? [] as $p) {
        if ($p === $perm || (str_ends_with($p, '.*') && str_starts_with($perm, substr($p, 0, -1)))) {
            return true;
        }
    }
    return false;
};
echo '| Permission | ' . implode(' | ', array_map(static fn ($s) => $g->groups[$s]['title'], $staff)) . " |\n";
echo '|---|' . str_repeat(':-:|', count($staff)) . "\n";
foreach ($g->permissions as $perm => $label) {
    echo "| `{$perm}` — {$label} | " . implode(' | ', array_map(static fn ($s) => $has($s, $perm) ? '✔' : '', $staff)) . " |\n";
}
