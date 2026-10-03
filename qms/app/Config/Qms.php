<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Application constants that are not business settings.
 * Business settings (company, numbering, workflow, timeouts ...) live in the
 * system_settings table and are edited in Admin -> Settings.
 */
class Qms extends BaseConfig
{
    public string $version = '1.0.0';

    /** Uploads (kilobytes). Nginx/PHP limits must be at least this large. */
    public int $logoMaxKb        = 1024;
    public int $certificateMaxKb = 5120;

    /** Login rate limits per client IP. */
    public int $loginAttemptsPerMinute = 10;
    public int $loginAttemptsPerHour   = 60;

    /** Password re-entry attempts for signatures before the session is ended. */
    public int $reauthMaxFailures = 5;

    /** Idempotency keys older than this are purged by `php spark qms:maintenance`. */
    public int $idempotencyRetentionHours = 48;

    /** Google Sheets worker. */
    public int $syncBatchSize        = 40;
    public int $syncStaleLockMinutes = 10;
    public int $syncPauseMilliseconds = 1100;

    /** Pages. */
    public int $perPage = 25;

    /** Local drafts on tablets older than this are flagged (hours). */
    public int $localDraftWarnHours = 72;
}
