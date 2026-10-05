<?php
/**
 * Reads config/config.php (written by install.php; never part of the source code).
 */
defined('QMS') || exit;

/**
 * config('db.host'), config('base_url') …
 */
function config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['qms_config'] ?? [];
    if ($key === null) {
        return $config;
    }
    foreach (explode('.', $key) as $part) {
        if (! is_array($config) || ! array_key_exists($part, $config)) {
            return $default;
        }
        $config = $config[$part];
    }

    return $config;
}

function config_load(): bool
{
    $file = QMS_ROOT . '/config/config.php';
    if (! is_file($file)) {
        $GLOBALS['qms_config'] = ['environment' => 'production'];

        return false;
    }
    $config = require $file;
    $GLOBALS['qms_config'] = is_array($config) ? $config : [];

    return true;
}
