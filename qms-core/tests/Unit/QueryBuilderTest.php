<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\DatabaseException;
use App\Core\QueryBuilder;
use App\Core\SqlScript;
use PHPUnit\Framework\TestCase;

/**
 * SQL produced by the query builder: values are always bound, never inlined.
 *
 * @internal
 */
final class QueryBuilderTest extends TestCase
{
    private function builder(string $table = 'inspection_reports r'): QueryBuilder
    {
        return new QueryBuilder(new Database(['database' => 'x', 'username' => 'x', 'password' => 'x']), $table);
    }

    public function testWhereOperatorsAndNullHandling(): void
    {
        [$sql, $binds] = $this->builder()
            ->select('r.id')->where('r.status', 'DRAFT')->where('r.inspection_date >=', '2026-10-01')
            ->where('r.report_no', null)->where('r.submitted_by !=', null)->where('rd.result IS NOT NULL')
            ->compileSelect();

        $this->assertSame("SELECT r.id FROM inspection_reports r WHERE r.status = ? AND r.inspection_date >= ? AND r.report_no IS NULL AND r.submitted_by IS NOT NULL AND rd.result IS NOT NULL", $sql);
        $this->assertSame(['DRAFT', '2026-10-01'], $binds);
    }

    public function testGroupsLikeAndInLists(): void
    {
        [$sql, $binds] = $this->builder()
            ->where('r.is_current', 1)
            ->groupStart()->like('r.report_no', "50%_off!")->orLike('p.part_number', 'BF', 'after')->groupEnd()
            ->whereIn('r.status', ['SUBMITTED', 'APPROVED'])->whereNotIn('r.id', [])->whereIn('r.id', [])
            ->orderBy('r.id', 'DESC')->limit(25, 50)
            ->compileSelect();

        $this->assertSame("SELECT * FROM inspection_reports r WHERE r.is_current = ? AND ( r.report_no LIKE ? ESCAPE '!' OR p.part_number LIKE ? ESCAPE '!' ) AND r.status IN (?, ?) AND 1 = 1 AND 1 = 0 ORDER BY r.id DESC LIMIT 25 OFFSET 50", $sql);
        $this->assertSame([1, '%50!%!_off!!%', 'BF%', 'SUBMITTED', 'APPROVED'], $binds);
    }

    public function testSubqueryInWhereIn(): void
    {
        [$sql, $binds] = $this->builder()
            ->where('r.shift_id', 2)
            ->orWhereIn('r.id', static fn (QueryBuilder $s): QueryBuilder => $s->select('report_id')->from('inspection_rounds')->where('shift_id', 2))
            ->compileSelect();

        $this->assertSame('SELECT * FROM inspection_reports r WHERE r.shift_id = ? OR r.id IN (SELECT report_id FROM inspection_rounds WHERE shift_id = ?)', $sql);
        $this->assertSame([2, 2], $binds);
    }

    public function testDirectionIsWhitelisted(): void
    {
        [$sql] = $this->builder()->orderBy('r.id', 'DESC; DROP TABLE users')->compileSelect();
        $this->assertSame('SELECT * FROM inspection_reports r ORDER BY r.id', $sql);
    }

    public function testColumnNamesInDataAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder('users')->set(['username`=1, password_hash' => 'x']);
    }

    public function testUpdateAndDeleteNeedAWhereClause(): void
    {
        try {
            $this->builder('users')->update(['status' => 'DISABLED']);
            $this->fail('UPDATE without WHERE was allowed');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('WHERE', $e->getMessage());
        }
        $this->expectException(DatabaseException::class);
        $this->builder('users')->delete();
    }

    public function testSqlSplitterHandlesDelimiterBlocksAndComments(): void
    {
        $sql = "-- comment; not a statement\nCREATE TABLE a (x INT); # another\nINSERT INTO a VALUES (';');\nDELIMITER \$\$\nCREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW\nBEGIN\n  SET NEW.x = 1;\nEND\$\$\nDELIMITER ;\n/* block; */ SELECT 1;";
        $this->assertSame([
            'CREATE TABLE a (x INT)',
            "INSERT INTO a VALUES (';')",
            "CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW\nBEGIN\n  SET NEW.x = 1;\nEND",
            'SELECT 1',
        ], SqlScript::split($sql));
    }
}
