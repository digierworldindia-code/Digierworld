<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;

/**
 * Small fluent SQL builder for MySQL.
 *
 *   $db->table('inspection_reports r')
 *      ->select('r.id, r.report_no, p.part_number')
 *      ->join('parts p', 'p.id = r.part_id', 'left')
 *      ->where('r.status', 'DRAFT')->where('r.inspection_date >=', $from)
 *      ->groupStart()->like('r.report_no', $q)->orLike('p.part_number', $q)->groupEnd()
 *      ->orderBy('r.id', 'DESC')->get(25, 0)->getResultArray();
 *
 * Table names, column names, joins, ORDER BY and GROUP BY are SQL written in
 * application code. Values always travel as bound parameters (?), so user
 * input can never change the SQL. Column names in insert()/update() data are
 * checked against [A-Za-z_][A-Za-z0-9_]* and quoted.
 */
final class QueryBuilder
{
    /** @var list<string> */
    private array $select = [];

    private bool $distinct = false;

    /** @var list<string> */
    private array $from = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<array{sql: string, binds: list<mixed>, open?: bool}> */
    private array $where = [];

    /** @var list<string> */
    private array $groupBy = [];

    /** @var list<string> */
    private array $orderBy = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<string, array{value: mixed, raw: bool}> */
    private array $set = [];

    public function __construct(private readonly Database $db, string $table)
    {
        if ($table !== '') {
            $this->from[] = trim($table);
        }
    }

    // ------------------------------------------------------------------
    // SELECT parts
    // ------------------------------------------------------------------

    public function select(string $select = '*', ?bool $escape = null): self
    {
        $select = trim($select);
        if ($select !== '') {
            $this->select[] = $select;
        }

        return $this;
    }

    public function selectMax(string $column, string $alias = ''): self
    {
        return $this->aggregate('MAX', $column, $alias);
    }

    public function selectMin(string $column, string $alias = ''): self
    {
        return $this->aggregate('MIN', $column, $alias);
    }

    public function selectSum(string $column, string $alias = ''): self
    {
        return $this->aggregate('SUM', $column, $alias);
    }

    public function selectAvg(string $column, string $alias = ''): self
    {
        return $this->aggregate('AVG', $column, $alias);
    }

    public function selectCount(string $column, string $alias = ''): self
    {
        return $this->aggregate('COUNT', $column, $alias);
    }

    public function distinct(bool $value = true): self
    {
        $this->distinct = $value;

        return $this;
    }

    public function from(string $table): self
    {
        $this->from[] = trim($table);

        return $this;
    }

    public function join(string $table, string $condition, string $type = ''): self
    {
        $type = strtoupper(trim($type));
        if (! in_array($type, ['', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'LEFT OUTER', 'RIGHT OUTER', 'CROSS'], true)) {
            throw new InvalidArgumentException("Unsupported join type: {$type}");
        }
        $this->joins[] = ($type === '' ? '' : $type . ' ') . 'JOIN ' . trim($table) . ' ON ' . $condition;

        return $this;
    }

    // ------------------------------------------------------------------
    // WHERE
    // ------------------------------------------------------------------

    /**
     * where('col', $v)        col = ?
     * where('col >=', $v)     col >= ?
     * where('col', null)      col IS NULL
     * where('col !=', null)   col IS NOT NULL
     * where('a.b IS NOT NULL') raw condition written in code
     * where(['a' => 1, 'b' => 2])
     *
     * @param array<string, mixed>|string $key
     */
    public function where(array|string $key, mixed $value = null, ?bool $escape = null): self
    {
        return $this->addWhere('AND ', $key, $value, $escape);
    }

    /**
     * @param array<string, mixed>|string $key
     */
    public function orWhere(array|string $key, mixed $value = null, ?bool $escape = null): self
    {
        return $this->addWhere('OR ', $key, $value, $escape);
    }

    /**
     * @param list<mixed>|Closure|null $values array, or Closure(QueryBuilder): QueryBuilder for a sub-query
     */
    public function whereIn(string $key, array|Closure|null $values = null): self
    {
        return $this->addWhereIn('AND ', $key, $values, false);
    }

    /**
     * @param list<mixed>|Closure|null $values
     */
    public function orWhereIn(string $key, array|Closure|null $values = null): self
    {
        return $this->addWhereIn('OR ', $key, $values, false);
    }

    /**
     * @param list<mixed>|Closure|null $values
     */
    public function whereNotIn(string $key, array|Closure|null $values = null): self
    {
        return $this->addWhereIn('AND ', $key, $values, true);
    }

    /**
     * @param list<mixed>|Closure|null $values
     */
    public function orWhereNotIn(string $key, array|Closure|null $values = null): self
    {
        return $this->addWhereIn('OR ', $key, $values, true);
    }

    /**
     * LIKE with the wildcards in $match escaped; $side = both | before | after | none.
     *
     * @param array<string, string>|string $field
     */
    public function like(array|string $field, string $match = '', string $side = 'both'): self
    {
        return $this->addLike('AND ', $field, $match, $side, false);
    }

    /**
     * @param array<string, string>|string $field
     */
    public function orLike(array|string $field, string $match = '', string $side = 'both'): self
    {
        return $this->addLike('OR ', $field, $match, $side, false);
    }

    /**
     * @param array<string, string>|string $field
     */
    public function notLike(array|string $field, string $match = '', string $side = 'both'): self
    {
        return $this->addLike('AND ', $field, $match, $side, true);
    }

    /**
     * @param array<string, string>|string $field
     */
    public function orNotLike(array|string $field, string $match = '', string $side = 'both'): self
    {
        return $this->addLike('OR ', $field, $match, $side, true);
    }

    public function groupStart(): self
    {
        return $this->openGroup('AND ', '');
    }

    public function orGroupStart(): self
    {
        return $this->openGroup('OR ', '');
    }

    public function notGroupStart(): self
    {
        return $this->openGroup('AND ', 'NOT ');
    }

    public function orNotGroupStart(): self
    {
        return $this->openGroup('OR ', 'NOT ');
    }

    public function groupEnd(): self
    {
        $this->where[] = ['sql' => ')', 'binds' => []];

        return $this;
    }

    // ------------------------------------------------------------------
    // GROUP / ORDER / LIMIT
    // ------------------------------------------------------------------

    public function groupBy(string $by): self
    {
        $by = trim($by);
        if ($by !== '') {
            $this->groupBy[] = $by;
        }

        return $this;
    }

    /**
     * orderBy('a') / orderBy('a, b', 'DESC') / orderBy('x IS NULL', 'DESC', false).
     * Only ASC and DESC are accepted as direction.
     */
    public function orderBy(string $orderBy, string $direction = '', ?bool $escape = null): self
    {
        $orderBy = trim($orderBy);
        if ($orderBy === '') {
            return $this;
        }
        $direction = strtoupper(trim($direction));
        $direction = in_array($direction, ['ASC', 'DESC'], true) ? ' ' . $direction : '';

        if ($escape === false) {
            $this->orderBy[] = $orderBy . $direction;

            return $this;
        }

        foreach (explode(',', $orderBy) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            if ($direction === '' && preg_match('/^(.*?)\s+(ASC|DESC)$/i', $field, $m)) {
                $this->orderBy[] = $m[1] . ' ' . strtoupper($m[2]);
            } else {
                $this->orderBy[] = $field . $direction;
            }
        }

        return $this;
    }

    public function limit(?int $value = null, ?int $offset = 0): self
    {
        $this->limit = $value === null ? null : max(0, $value);
        if ($offset !== null && $offset !== 0) {
            $this->offset = max(0, $offset);
        }

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    public function get(?int $limit = null, int $offset = 0, bool $reset = true): Result
    {
        if ($limit !== null) {
            $this->limit($limit, $offset);
        }
        [$sql, $binds] = $this->compileSelect();
        $result = $this->db->query($sql, $binds);
        if ($reset) {
            $this->resetQuery();
        }

        return $result;
    }

    /**
     * COUNT(*) of the current query (ORDER BY and LIMIT ignored). Pass false to
     * keep the builder for a following get() (paged lists).
     */
    public function countAllResults(bool $reset = true): int
    {
        if ($this->distinct || $this->groupBy !== []) {
            [$inner, $binds] = $this->compileSelect(false);
            $sql = 'SELECT COUNT(*) AS numrows FROM (' . $inner . ') qms_count';
        } else {
            [$tail, $binds] = $this->compileTail();
            $sql = 'SELECT COUNT(*) AS numrows' . $tail;
        }
        $count = (int) $this->db->query($sql, $binds)->getRow('numrows');
        if ($reset) {
            $this->resetQuery();
        }

        return $count;
    }

    /**
     * @param array<string, mixed>|string $key
     */
    public function set(array|string $key, mixed $value = '', ?bool $escape = null): self
    {
        $pairs = is_array($key) ? $key : [$key => $value];
        foreach ($pairs as $column => $v) {
            $this->set[self::column((string) $column)] = ['value' => $v, 'raw' => $escape === false];
        }

        return $this;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function insert(?array $data = null): bool
    {
        if ($data !== null) {
            $this->set($data);
        }
        if ($this->set === []) {
            throw new InvalidArgumentException('insert() needs data.');
        }

        $columns = [];
        $values  = [];
        $binds   = [];
        foreach ($this->set as $column => $item) {
            $columns[] = '`' . $column . '`';
            if ($item['raw']) {
                $values[] = (string) $item['value'];
            } else {
                $values[] = '?';
                $binds[]  = $item['value'];
            }
        }
        $sql = 'INSERT INTO ' . $this->baseTable() . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';
        $this->db->query($sql, $binds);
        $this->resetQuery();

        return true;
    }

    /**
     * Multi-row insert in chunks of 100 rows; every row needs the same columns.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertBatch(array $rows): int
    {
        $rows = array_values($rows);
        if ($rows === []) {
            return 0;
        }
        $columns = array_map(self::column(...), array_keys($rows[0]));
        $quoted  = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
        $tuple   = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $table   = $this->baseTable();
        $total   = 0;

        foreach (array_chunk($rows, 100) as $chunk) {
            $binds = [];
            foreach ($chunk as $row) {
                if (count($row) !== count($columns)) {
                    throw new InvalidArgumentException('insertBatch() rows must have the same columns.');
                }
                foreach ($columns as $column) {
                    if (! array_key_exists($column, $row)) {
                        throw new InvalidArgumentException("insertBatch() row is missing column {$column}.");
                    }
                    $binds[] = $row[$column];
                }
            }
            $sql = 'INSERT INTO ' . $table . ' (' . $quoted . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $tuple));
            $this->db->query($sql, $binds);
            $total += count($chunk);
        }
        $this->resetQuery();

        return $total;
    }

    /**
     * UPDATE … SET … WHERE …; refuses to run without a WHERE clause.
     *
     * @param array<string, mixed>|null $data
     */
    public function update(?array $data = null): bool
    {
        if ($data !== null) {
            $this->set($data);
        }
        if ($this->set === []) {
            throw new InvalidArgumentException('update() needs data.');
        }
        if ($this->where === []) {
            throw new DatabaseException('Refusing to UPDATE without a WHERE clause.');
        }

        $assignments = [];
        $binds       = [];
        foreach ($this->set as $column => $item) {
            if ($item['raw']) {
                $assignments[] = '`' . $column . '` = ' . (string) $item['value'];
            } else {
                $assignments[] = '`' . $column . '` = ?';
                $binds[]       = $item['value'];
            }
        }
        [$where, $whereBinds] = $this->compileWhere();
        $sql = 'UPDATE ' . $this->baseTable() . ' SET ' . implode(', ', $assignments) . $where;
        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        $this->db->query($sql, array_merge($binds, $whereBinds));
        $this->resetQuery();

        return true;
    }

    /**
     * DELETE … WHERE …; refuses to run without a WHERE clause.
     */
    public function delete(): bool
    {
        if ($this->where === []) {
            throw new DatabaseException('Refusing to DELETE without a WHERE clause.');
        }
        [$where, $binds] = $this->compileWhere();
        $sql = 'DELETE FROM ' . $this->baseTable() . $where;
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        $this->db->query($sql, $binds);
        $this->resetQuery();

        return true;
    }

    /**
     * SQL text and binds of the SELECT (tests and sub-queries).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileSelect(bool $withOrderAndLimit = true): array
    {
        $columns = $this->select === [] ? '*' : implode(', ', $this->select);
        [$tail, $binds] = $this->compileTail();
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $columns . $tail;

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($withOrderAndLimit) {
            if ($this->orderBy !== []) {
                $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
            }
            if ($this->limit !== null) {
                $sql .= ' LIMIT ' . $this->limit;
                if ($this->offset !== null && $this->offset > 0) {
                    $sql .= ' OFFSET ' . $this->offset;
                }
            } elseif ($this->offset !== null && $this->offset > 0) {
                $sql .= ' LIMIT 18446744073709551615 OFFSET ' . $this->offset;
            }
        }

        return [$sql, $binds];
    }

    public function resetQuery(): self
    {
        $this->select   = [];
        $this->distinct = false;
        $this->joins    = [];
        $this->where    = [];
        $this->groupBy  = [];
        $this->orderBy  = [];
        $this->limit    = null;
        $this->offset   = null;
        $this->set      = [];

        return $this;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>|string $key
     */
    private function addWhere(string $type, array|string $key, mixed $value, ?bool $escape): self
    {
        $pairs = is_array($key) ? $key : [$key => $value];

        foreach ($pairs as $k => $v) {
            $k      = trim((string) $k);
            $prefix = $this->prefix($type);

            if ($v instanceof \BackedEnum) {
                $v = $v->value;
            }

            if ($v === null) {
                if (! self::hasOperator($k)) {
                    $sql = $k . ' IS NULL';
                } elseif (preg_match('/\s*(!?=|<>|IS(?:\s+NOT)?)\s*$/i', $k, $m, PREG_OFFSET_CAPTURE)) {
                    $sql = substr($k, 0, $m[0][1]) . ($m[1][0] === '=' ? ' IS NULL' : ' IS NOT NULL');
                } else {
                    $sql = $k; // complete condition written in code
                }
                $this->where[] = ['sql' => $prefix . $sql, 'binds' => []];

                continue;
            }

            [$column, $operator] = self::splitOperator($k);

            if ($v instanceof Closure) {
                [$subSql, $subBinds] = $this->subquery($v);
                $this->where[] = ['sql' => $prefix . $column . ' ' . $operator . ' (' . $subSql . ')', 'binds' => $subBinds];

                continue;
            }
            if (is_array($v)) {
                throw new InvalidArgumentException("where({$k}) got an array; use whereIn().");
            }
            if ($escape === false) {
                $this->where[] = ['sql' => $prefix . $column . ' ' . $operator . ' ' . (string) $v, 'binds' => []];

                continue;
            }

            $this->where[] = ['sql' => $prefix . $column . ' ' . $operator . ' ?', 'binds' => [$v]];
        }

        return $this;
    }

    /**
     * @param list<mixed>|Closure|null $values
     */
    private function addWhereIn(string $type, string $key, array|Closure|null $values, bool $not): self
    {
        $prefix = $this->prefix($type);
        $key    = trim($key);

        if ($values instanceof Closure) {
            [$subSql, $subBinds] = $this->subquery($values);
            $this->where[] = ['sql' => $prefix . $key . ($not ? ' NOT' : '') . ' IN (' . $subSql . ')', 'binds' => $subBinds];

            return $this;
        }

        $values = array_values(array_map(static fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, (array) $values));
        if ($values === []) {
            // IN () is invalid SQL: an empty list matches nothing (NOT IN: everything).
            $this->where[] = ['sql' => $prefix . ($not ? '1 = 1' : '1 = 0'), 'binds' => []];

            return $this;
        }

        $placeholders  = implode(', ', array_fill(0, count($values), '?'));
        $this->where[] = ['sql' => $prefix . $key . ($not ? ' NOT' : '') . ' IN (' . $placeholders . ')', 'binds' => $values];

        return $this;
    }

    /**
     * @param array<string, string>|string $field
     */
    private function addLike(string $type, array|string $field, string $match, string $side, bool $not): self
    {
        $pairs = is_array($field) ? $field : [$field => $match];

        foreach ($pairs as $column => $value) {
            $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $value);
            $value = match (strtolower($side)) {
                'none'   => $value,
                'before' => '%' . $value,
                'after'  => $value . '%',
                default  => '%' . $value . '%',
            };
            $this->where[] = [
                'sql'   => $this->prefix($type) . trim((string) $column) . ($not ? ' NOT' : '') . " LIKE ? ESCAPE '!'",
                'binds' => [$value],
            ];
        }

        return $this;
    }

    private function openGroup(string $type, string $not): self
    {
        $this->where[] = ['sql' => $this->prefix($type) . $not . '(', 'binds' => [], 'open' => true];

        return $this;
    }

    /** AND/OR connector, omitted for the first condition and right after "(". */
    private function prefix(string $type): string
    {
        if ($this->where === []) {
            return '';
        }
        $last = $this->where[array_key_last($this->where)];

        return ! empty($last['open']) ? '' : $type;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function subquery(Closure $build): array
    {
        $builder = $build($this->db->newQuery());
        if (! $builder instanceof self) {
            throw new InvalidArgumentException('A sub-query closure must return the builder.');
        }

        return $builder->compileSelect();
    }

    /**
     * FROM … JOIN … WHERE … with binds.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileTail(): array
    {
        if ($this->from === []) {
            throw new InvalidArgumentException('No table selected.');
        }
        $sql = ' FROM ' . implode(', ', $this->from);
        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }
        [$where, $binds] = $this->compileWhere();

        return [$sql . $where, $binds];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileWhere(): array
    {
        if ($this->where === []) {
            return ['', []];
        }
        $parts = [];
        $binds = [];
        foreach ($this->where as $item) {
            $parts[] = $item['sql'];
            foreach ($item['binds'] as $bind) {
                $binds[] = $bind;
            }
        }

        return [' WHERE ' . implode(' ', $parts), $binds];
    }

    private function aggregate(string $function, string $column, string $alias): self
    {
        $column = trim($column);
        $alias  = $alias !== '' ? $alias : (str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column);
        $this->select[] = $function . '(' . $column . ') AS ' . self::column($alias);

        return $this;
    }

    /** Table for INSERT/UPDATE/DELETE (the first table, alias removed). */
    private function baseTable(): string
    {
        if ($this->from === []) {
            throw new InvalidArgumentException('No table selected.');
        }
        $table = preg_split('/\s+/', $this->from[0])[0];
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }

        return '`' . $table . '`';
    }

    private static function column(string $column): string
    {
        $column = trim($column);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }

        return $column;
    }

    private static function hasOperator(string $key): bool
    {
        return (bool) preg_match('/(<|>|!|=|\sIS NULL|\sIS NOT NULL|\sEXISTS|\sBETWEEN|\sLIKE|\sIN\s*\(|\s)/i', trim($key));
    }

    /**
     * 'col >=' → ['col', '>=']; 'col' → ['col', '='].
     *
     * @return array{0: string, 1: string}
     */
    private static function splitOperator(string $key): array
    {
        if (preg_match('/^(.*?)\s*(<=|>=|<>|!=|=|<|>|\bNOT\s+LIKE|\bLIKE)$/i', $key, $m) && trim($m[1]) !== '') {
            return [trim($m[1]), strtoupper(preg_replace('/\s+/', ' ', $m[2]))];
        }

        return [$key, '='];
    }
}
