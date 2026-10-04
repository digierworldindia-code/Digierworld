<?php

declare(strict_types=1);

namespace App\Config;

use App\Core\Env;

/**
 * Google Sheets credentials location.
 *
 * Only the PATH to the service-account JSON key is configured (.env
 * GOOGLE_CREDENTIALS_FILE). The key itself lives outside the web root, e.g.
 * /etc/qms/secrets/google-sa.json (mode 0440) or storage/secrets/ on shared
 * hosting. The spreadsheet IDs are business settings (Admin → Settings → Google).
 */
final class Google
{
    public string $credentialsFile;

    /** Application name sent to Google in the User-Agent. */
    public string $applicationName = 'QMS Inspection Sync';

    /** HTTP timeout for Sheets API calls (seconds). */
    public int $timeout = 30;

    public function __construct()
    {
        $this->credentialsFile = (string) Env::get('GOOGLE_CREDENTIALS_FILE', '');
    }
}
