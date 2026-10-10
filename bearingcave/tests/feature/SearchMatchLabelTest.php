<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\BCTestCase;

/**
 * Part-number matches must never imply interchangeability (prompt §10):
 * a hit on a supplier-declared cross reference is labelled as unverified
 * in the web results and in the API.
 */
final class SearchMatchLabelTest extends BCTestCase
{
    public function testCrossReferenceHitIsLabelledUnverified(): void
    {
        $s = $this->verifiedSupplier();
        $p = $this->product($s['company'], ['part_number' => '6204-2RS', 'cross_references' => 'FAG:6204-2RSR']);

        $html = $this->getAs(null, 'marketplace?q=6204-2RSR');
        $html->assertOK();
        $html->assertSee($p['name']);
        $html->assertSee('interchangeability not verified');

        $api = json_decode((string) $this->getAs(null, 'api/v1/products?q=6204-2RSR')->getJSON(), true);
        $hit = array_values(array_filter($api['data'], static fn ($r) => $r['id'] === $p['uuid']))[0] ?? null;
        $this->assertNotNull($hit);
        $this->assertSame('declared_cross_reference', $hit['match_type']);
        $this->assertStringContainsString('not verified', (string) $hit['interchangeability_note']);

        // An exact part-number hit carries no cross-reference warning.
        $exact = json_decode((string) $this->getAs(null, 'api/v1/products?q=6204-2RS')->getJSON(), true);
        $hit   = array_values(array_filter($exact['data'], static fn ($r) => $r['id'] === $p['uuid']))[0] ?? null;
        $this->assertSame('part_number', $hit['match_type']);
        $this->assertNull($hit['interchangeability_note']);
    }
}
