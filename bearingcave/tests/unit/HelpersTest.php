<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class HelpersTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('app');
    }

    public function testPartNumberNormalisation(): void
    {
        $this->assertSame('62042RSC3', normalize_part('6204-2RS/C3'));
        $this->assertSame('62042RSC3', normalize_part(' 6204 2rs c3 '));
        $this->assertSame('', normalize_part(null));
    }

    public function testUuidAndSlug(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', uuid4());
        $this->assertSame('deep-groove-bearing-6204-2rs', make_slug('Deep Groove Bearing 6204-2RS'));
    }
}
