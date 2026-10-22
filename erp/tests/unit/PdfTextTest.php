<?php

namespace Tests\Unit;

use App\Libraries\PdfText;
use CodeIgniter\Test\CIUnitTestCase;

final class PdfTextTest extends CIUnitTestCase
{
    public function testShortTextStaysInOnePieceAndEmptyTextHasNone(): void
    {
        $this->assertSame([], PdfText::chunks("  \r\n "));
        $this->assertSame(["Line one\nLine two"], PdfText::chunks("Line one\r\nLine two"));
    }

    public function testLongTextIsSplitWithoutLosingOrReorderingAnything(): void
    {
        $text   = str_repeat('Monthly search engine optimisation and reporting. ', 50) . "\nSecond paragraph.\n" . str_repeat('X', 1000) . "\nLast line";
        $pieces = PdfText::chunks($text, 420, 10);
        $this->assertGreaterThan(5, count($pieces));
        foreach ($pieces as $p) {
            $this->assertLessThanOrEqual(420, mb_strlen(str_replace("\n", '', $p)));
            $this->assertLessThanOrEqual(10, substr_count($p, "\n") + 1);
        }
        // same words, same order (only line breaks and the spaces at cut points differ)
        $this->assertSame(preg_replace('/\s+/', '', $text), preg_replace('/\s+/', '', implode('', $pieces)));
        $this->assertStringEndsWith('Last line', end($pieces));
    }

    public function testManyShortLinesAreGroupedByLineCount(): void
    {
        $pieces = PdfText::chunks(implode("\n", array_map(static fn ($i) => 'Item ' . $i, range(1, 25))), 420, 10);
        $this->assertSame([10, 10, 5], array_map(static fn ($p) => substr_count($p, "\n") + 1, $pieces));
    }

    public function testMultibyteTextIsCutOnCharacterBoundaries(): void
    {
        $pieces = PdfText::chunks(str_repeat('सेवा ₹ ', 200), 100);
        foreach ($pieces as $p) {
            $this->assertTrue(mb_check_encoding($p, 'UTF-8'));
        }
        $this->assertSame(200, substr_count(implode(' ', $pieces), '₹'));
    }
}
