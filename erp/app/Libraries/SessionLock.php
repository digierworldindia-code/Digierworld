<?php

namespace App\Libraries;

/**
 * PHP's file sessions lock the session file for the whole request, so while
 * one slow request runs (a big export, a Google Sheets sync, an AI review,
 * a backup) every other page the same user opens waits behind it, and the
 * web server's gateway eventually answers 504. Slow work releases the lock
 * first; the session stays readable. Anything written to the session after
 * release() is only kept if reopen() is called before the response is sent.
 */
final class SessionLock
{
    public static function release(): void
    {
        if (ENVIRONMENT !== 'testing' && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** Takes the session back (e.g. to store a flash message after slow work). */
    public static function reopen(): void
    {
        if (ENVIRONMENT !== 'testing' && session_status() === PHP_SESSION_NONE && ! headers_sent()) {
            session_start();
        }
    }
}
