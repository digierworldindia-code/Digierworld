<?php

namespace App\Services\Sync;

use RuntimeException;

/**
 * The detail spreadsheet is nearly full: the sync job waits; it is not an error of the job.
 */
final class CapacityException extends RuntimeException
{
}
