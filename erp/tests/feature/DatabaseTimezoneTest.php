<?php

namespace Tests\Feature;

use Tests\Support\DgrTestCase;

/** DB DEFAULT CURRENT_TIMESTAMP values must agree with PHP (IST) timestamps. */
final class DatabaseTimezoneTest extends DgrTestCase
{
    public function testSessionTimeZoneMatchesAppTimezone(): void
    {
        $dbNow  = strtotime((string) db_connect()->query('SELECT NOW() AS n')->getRow()->n);
        $phpNow = strtotime(date('Y-m-d H:i:s'));
        $this->assertLessThan(120, abs($dbNow - $phpNow), 'MySQL session time zone is not aligned with ' . config('App')->appTimezone);
    }
}
