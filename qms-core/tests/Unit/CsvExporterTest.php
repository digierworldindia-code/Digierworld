<?php

namespace Tests\Unit;

use App\Services\Reports\CsvExporter;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class CsvExporterTest extends TestCase
{
    public function testFormulaInjectionIsNeutralised(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", CsvExporter::neutralise('=HYPERLINK("http://x")'));
        $this->assertSame("'+SUM(A1)", CsvExporter::neutralise('+SUM(A1)'));
        $this->assertSame("'@cmd", CsvExporter::neutralise('@cmd'));
        $this->assertSame("'-2+3", CsvExporter::neutralise('-2+3'));
        $this->assertSame("'\tx", CsvExporter::neutralise("\tx"));
        $this->assertSame('-0.05', CsvExporter::neutralise('-0.05'), 'plain negative numbers stay numbers');
        $this->assertSame('6.450', CsvExporter::neutralise('6.450'));
    }

    public function testBuildsUtf8CsvWithHeaderAndTotals(): void
    {
        $columns = ['a' => ['label' => 'Part', 'type' => 'text'], 'b' => ['label' => 'Qty', 'type' => 'int']];
        $csv     = CsvExporter::build($columns, [['a' => 'BF-1001 "3/4"', 'b' => 5], ['a' => '=1+1', 'b' => null]], ['a' => 'Total', 'b' => 5]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\n", trim(substr($csv, 3)));
        $this->assertSame('Part,Qty', $lines[0]);
        $this->assertSame('"BF-1001 ""3/4""",5', $lines[1]);
        $this->assertSame("'=1+1,", $lines[2]);
        $this->assertSame('Total,5', $lines[3]);
    }
}
