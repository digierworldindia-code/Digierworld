<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Token-bucket rate limiter (login attempts per IP). Buckets are small JSON
 * files under storage/cache/throttle, updated under an exclusive file lock.
 */
final class Throttler
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * Takes one token from the bucket $key (capacity tokens per $seconds).
     * Returns false when the bucket is empty.
     */
    public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
    {
        if (! is_dir($this->dir) && ! @mkdir($this->dir, 0770, true) && ! is_dir($this->dir)) {
            log_message('error', 'Throttle directory is not writable: {dir}', ['dir' => $this->dir]);

            return true; // never lock everybody out because of a disk problem
        }

        $file   = $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return true;
        }

        try {
            flock($handle, LOCK_EX);
            $raw   = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $now   = microtime(true);
            $rate  = $capacity / max(1, $seconds);

            if (! is_array($state) || ! isset($state['tokens'], $state['time']) || $now - (float) $state['time'] > $seconds) {
                $tokens = (float) $capacity;
            } else {
                $tokens = min((float) $capacity, (float) $state['tokens'] + $rate * ($now - (float) $state['time']));
            }

            $allowed = $tokens >= $cost;
            if ($allowed) {
                $tokens -= $cost;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['tokens' => $tokens, 'time' => $now]));
            fflush($handle);

            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Deletes buckets untouched for a day (maintenance script). */
    public function prune(int $olderThanSeconds = 86400): int
    {
        $count = 0;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < time() - $olderThanSeconds && @unlink($file)) {
                $count++;
            }
        }

        return $count;
    }
}
