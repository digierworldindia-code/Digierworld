<?php

declare(strict_types=1);

namespace App\Config;

/**
 * CSRF token names (the token itself is stored in the session).
 */
final class Security
{
    /** Form field carrying the token. */
    public string $tokenName = 'csrf_qms';

    /** Header used by fetch() requests (see public/assets/js/app.js). */
    public string $headerName = 'X-CSRF-TOKEN';
}
