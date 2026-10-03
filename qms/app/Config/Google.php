<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Google Sheets credentials location.
 *
 * Only the PATH to the service-account JSON key is configured here, from the
 * environment (google.credentialsFile). The key itself lives outside the
 * project and the web root, e.g. /etc/qms/secrets/google-sa.json (0440).
 * The spreadsheet IDs are business settings (Admin -> Settings -> Google).
 */
class Google extends BaseConfig
{
    public string $credentialsFile = '';

    /** Application name sent to Google in the User-Agent. */
    public string $applicationName = 'QMS Inspection Sync';

    /** HTTP timeout for Sheets API calls (seconds). */
    public int $timeout = 30;
}
