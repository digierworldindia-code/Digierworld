<?php

namespace App\Services;

use App\Exceptions\AppException;
use CodeIgniter\Database\BaseConnection;

/**
 * Reports.
 *
 * Every report is a definition, not ad-hoc SQL: a title, the filters it
 * accepts, the columns it returns and one parameterised query. Nothing a user
 * types reaches the SQL text — filters are bound, and the sort column has to
 * be one this definition names.
 *
 * The same definition serves the screen, the CSV and the Excel file, so an
 * export always matches what was on screen.
 */
final class ReportService
{
    /** Filters a report may accept. */
    public const FILTERS = ['from', 'to', 'dealer', 'product', 'status'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function instance(): self
    {
        return new self(db_connect());
    }

    /** @return array<string, array{title:string, description:string, filters:list<string>, columns:array<string,string>, money?:list<string>}> */
    public static function catalogue(): array
    {
        return [
            'sales' => [
                'title'       => 'Sales',
                'description' => 'Every sale, with its dealer, product and warranty status.',
                'filters'     => ['from', 'to', 'dealer', 'product'],
                'columns'     => [
                    'sold_on' => 'Sold on', 'invoice_number' => 'Invoice', 'serial_number' => 'Serial', 'product' => 'Product',
                    'size_label' => 'Size', 'dealer' => 'Dealer', 'city' => 'City', 'customer_city' => 'Customer city',
                    'payment_mode' => 'Payment', 'sale_price' => 'Sale price', 'warranty_status' => 'Warranty',
                ],
                'money' => ['sale_price'],
            ],
            'sales-by-month' => [
                'title'       => 'Sales by month',
                'description' => 'Units sold and value per calendar month (IST).',
                'filters'     => ['from', 'to', 'dealer'],
                'columns'     => ['month' => 'Month', 'units' => 'Units', 'value' => 'Value', 'dealers' => 'Dealers selling', 'average_price' => 'Average price'],
                'money'       => ['value', 'average_price'],
            ],
            'inventory' => [
                'title'       => 'Inventory',
                'description' => 'Where every unit is: warehouse, in transit, at a dealer or sold.',
                'filters'     => ['product', 'dealer', 'status'],
                'columns'     => ['product' => 'Product', 'size_label' => 'Size', 'status' => 'Status', 'location' => 'Location', 'units' => 'Units'],
            ],
            'production' => [
                'title'       => 'Production',
                'description' => 'Units serialised per batch, with the plant and the dates.',
                'filters'     => ['from', 'to'],
                'columns'     => ['batch_code' => 'Batch', 'manufactured_on' => 'Made on', 'warehouse' => 'Plant', 'planned' => 'Planned',
                    'serialised' => 'Serialised', 'dispatched' => 'Dispatched', 'sold' => 'Sold', 'line_supervisor' => 'Supervisor'],
            ],
            'dispatches' => [
                'title'       => 'Dispatches',
                'description' => 'Consignments, what was received and what was reported damaged or missing.',
                'filters'     => ['from', 'to', 'dealer', 'status'],
                'columns'     => ['dispatch_code' => 'Dispatch', 'dispatched_on' => 'Sent', 'dealer' => 'Dealer', 'city' => 'City',
                    'status' => 'Status', 'units' => 'Units', 'received' => 'Received OK', 'damaged' => 'Damaged', 'missing' => 'Missing'],
            ],
            'claims' => [
                'title'       => 'Warranty claims',
                'description' => 'Claims with their issue, decision, resolution and risk level.',
                'filters'     => ['from', 'to', 'dealer', 'status'],
                'columns'     => ['claim_number' => 'Claim', 'submitted_on' => 'Submitted', 'serial_number' => 'Serial', 'product' => 'Product',
                    'dealer' => 'Dealer', 'issue_category' => 'Issue', 'status' => 'Status', 'resolution' => 'Resolution',
                    'risk_level' => 'Risk', 'risk_score' => 'Score', 'days_open' => 'Days open'],
            ],
            'dealer-performance' => [
                'title'       => 'Dealer performance',
                'description' => 'Stock, sales and claim ratio per dealer. A high ratio is a prompt to look, not a verdict.',
                'filters'     => ['from', 'to'],
                'columns'     => ['dealer' => 'Dealer', 'code' => 'Code', 'city' => 'City', 'state' => 'State', 'status' => 'Status',
                    'in_stock' => 'In stock', 'sales' => 'Sales', 'value' => 'Sales value', 'claims' => 'Claims', 'claim_rate' => 'Claims per 100 sales'],
                'money' => ['value'],
            ],
            'warranty-expiry' => [
                'title'       => 'Warranty expiry',
                'description' => 'Active warranties by the month they end.',
                'filters'     => ['from', 'to', 'dealer'],
                'columns'     => ['month' => 'Ends in', 'warranties' => 'Warranties', 'dealers' => 'Dealers'],
            ],
            'replacements' => [
                'title'       => 'Replacements',
                'description' => 'Units issued against approved claims.',
                'filters'     => ['from', 'to', 'dealer'],
                'columns'     => ['issued_on' => 'Issued', 'claim_number' => 'Claim', 'original' => 'Original serial',
                    'replacement' => 'Replacement serial', 'product' => 'Product', 'dealer' => 'Dealer', 'approved_by' => 'Approved by'],
            ],
        ];
    }

    public static function definition(string $key): array
    {
        return self::catalogue()[$key] ?? throw AppException::notFound('report');
    }

    /**
     * Runs a report.
     *
     * @param array{from?:?string, to?:?string, dealer?:?string, product?:?string, status?:?string} $filters
     *
     * @return array{rows:list<array<string,mixed>>, total:int, truncated:bool}
     */
    public function run(string $key, array $filters, int $limit = 500, int $offset = 0): array
    {
        $definition = self::definition($key);
        [$sql, $binds] = $this->sql($key, $filters);

        $counted = $this->db->query("SELECT COUNT(*) n FROM ({$sql}) counted", $binds)->getRow()->n;
        $rows    = $this->db->query($sql . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $binds)->getResultArray();

        // Keep only the columns the definition names, in its order.
        $columns = array_keys($definition['columns']);
        $rows    = array_map(static function (array $row) use ($columns): array {
            $out = [];
            foreach ($columns as $column) {
                $out[$column] = $row[$column] ?? null;
            }

            return $out;
        }, $rows);

        return ['rows' => $rows, 'total' => (int) $counted, 'truncated' => (int) $counted > $offset + count($rows)];
    }

    /** Streams every row of a report, in chunks, for an export. */
    public function each(string $key, array $filters, int $chunk = 1000): \Generator
    {
        $offset = 0;
        do {
            $page = $this->run($key, $filters, $chunk, $offset);
            foreach ($page['rows'] as $row) {
                yield $row;
            }
            $offset += $chunk;
        } while ($page['rows'] !== [] && $offset < $page['total']);
    }

    /**
     * The one place a report's SQL lives. Filters are bound, never interpolated.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function sql(string $key, array $filters): array
    {
        $binds = [];
        $from  = $this->date($filters['from'] ?? null);
        $to    = $this->date($filters['to'] ?? null);
        $dealer = ($filters['dealer'] ?? null) !== null && is_uuid((string) $filters['dealer']) ? $filters['dealer'] : null;
        $product = ($filters['product'] ?? null) !== null && is_uuid((string) $filters['product']) ? $filters['product'] : null;
        $status  = ($filters['status'] ?? null) !== null && preg_match('/^[A-Z_]{2,30}$/', (string) $filters['status']) === 1 ? $filters['status'] : null;

        // Every fragment below adds its own bind in the same order it appears.
        $window = static function (string $column) use (&$binds, $from, $to): string {
            $sql = '';
            if ($from !== null) {
                $sql .= " AND {$column} >= ?";
                $binds[] = $from . ' 00:00:00';
            }
            if ($to !== null) {
                $sql .= " AND {$column} <= ?";
                $binds[] = $to . ' 23:59:59.999999';
            }

            return $sql;
        };
        $equals = static function (string $column, ?string $value) use (&$binds): string {
            if ($value === null) {
                return '';
            }
            $binds[] = $value;

            return " AND {$column} = ?";
        };

        switch ($key) {
            case 'sales':
                $sql = "SELECT DATE(CONVERT_TZ(s.sold_at, '+00:00', '+05:30')) sold_on, s.invoice_number, m.serial_number, p.name product,
                               v.size_label, d.business_name dealer, d.city, c.city customer_city, s.payment_mode, s.sale_price,
                               COALESCE(w.status, 'NONE') warranty_status
                          FROM sales s
                          JOIN mattresses m ON m.id = s.mattress_id
                          JOIN product_variants v ON v.id = m.product_variant_id
                          JOIN products p ON p.id = v.product_id
                          JOIN dealers d ON d.id = s.dealer_id
                          JOIN customers c ON c.id = s.customer_id
                          LEFT JOIN warranties w ON w.sale_id = s.id AND w.deleted_at IS NULL
                         WHERE s.deleted_at IS NULL" . $window('s.sold_at') . $equals('s.dealer_id', $dealer) . $equals('p.id', $product)
                    . ' ORDER BY s.sold_at DESC';
                break;

            case 'sales-by-month':
                $sql = "SELECT DATE_FORMAT(CONVERT_TZ(s.sold_at, '+00:00', '+05:30'), '%Y-%m') month, COUNT(*) units,
                               SUM(s.sale_price) value, COUNT(DISTINCT s.dealer_id) dealers, ROUND(AVG(s.sale_price), 2) average_price
                          FROM sales s WHERE s.deleted_at IS NULL" . $window('s.sold_at') . $equals('s.dealer_id', $dealer)
                    . ' GROUP BY month ORDER BY month DESC';
                break;

            case 'inventory':
                $sql = "SELECT p.name product, v.size_label, m.current_status status,
                               COALESCE(d.business_name, w.name, 'Unassigned') location, COUNT(*) units
                          FROM mattresses m
                          JOIN product_variants v ON v.id = m.product_variant_id
                          JOIN products p ON p.id = v.product_id
                          LEFT JOIN dealers d ON d.id = m.current_dealer_id
                          LEFT JOIN warehouses w ON w.id = m.current_warehouse_id
                         WHERE m.deleted_at IS NULL" . $equals('p.id', $product) . $equals('m.current_dealer_id', $dealer) . $equals('m.current_status', $status)
                    . ' GROUP BY p.name, v.size_label, m.current_status, location ORDER BY p.name, v.size_label, m.current_status';
                break;

            case 'production':
                $sql = "SELECT b.batch_code, b.manufactured_on, w.name warehouse, b.planned_quantity planned,
                               COUNT(m.id) serialised,
                               SUM(m.current_status IN ('DISPATCHED','DEALER_RECEIVED','SOLD','CLAIM_OPEN','REPLACED')) dispatched,
                               SUM(m.current_status IN ('SOLD','CLAIM_OPEN','REPLACED')) sold, b.line_supervisor
                          FROM manufacturing_batches b
                          JOIN warehouses w ON w.id = b.warehouse_id
                          LEFT JOIN mattresses m ON m.batch_id = b.id AND m.deleted_at IS NULL
                         WHERE 1 = 1" . $window('b.manufactured_on')
                    . ' GROUP BY b.id, b.batch_code, b.manufactured_on, w.name, b.planned_quantity, b.line_supervisor
                       ORDER BY b.manufactured_on DESC, b.batch_code DESC';
                break;

            case 'dispatches':
                $sql = "SELECT x.dispatch_code, DATE(CONVERT_TZ(x.dispatched_at, '+00:00', '+05:30')) dispatched_on,
                               d.business_name dealer, d.city, x.status,
                               (SELECT COUNT(*) FROM dispatch_items i WHERE i.dispatch_id = x.id) units,
                               (SELECT COUNT(*) FROM dealer_receipt_items ri JOIN dealer_receipts r ON r.id = ri.receipt_id
                                 WHERE r.dispatch_id = x.id AND ri.condition = 'OK') received,
                               (SELECT COUNT(*) FROM dealer_receipt_items ri JOIN dealer_receipts r ON r.id = ri.receipt_id
                                 WHERE r.dispatch_id = x.id AND ri.condition = 'DAMAGED') damaged,
                               (SELECT COUNT(*) FROM dealer_receipt_items ri JOIN dealer_receipts r ON r.id = ri.receipt_id
                                 WHERE r.dispatch_id = x.id AND ri.condition = 'MISSING') missing
                          FROM dispatches x JOIN dealers d ON d.id = x.dealer_id
                         WHERE x.deleted_at IS NULL" . $window('x.created_at') . $equals('x.dealer_id', $dealer) . $equals('x.status', $status)
                    . ' ORDER BY x.created_at DESC';
                break;

            case 'claims':
                $sql = "SELECT c.claim_number, DATE(CONVERT_TZ(c.submitted_at, '+00:00', '+05:30')) submitted_on, m.serial_number,
                               p.name product, d.business_name dealer, c.issue_category, c.status, c.resolution, c.risk_level, c.risk_score,
                               DATEDIFF(COALESCE(c.closed_at, UTC_TIMESTAMP()), c.submitted_at) days_open
                          FROM warranty_claims c
                          JOIN mattresses m ON m.id = c.mattress_id
                          JOIN product_variants v ON v.id = m.product_variant_id
                          JOIN products p ON p.id = v.product_id
                          JOIN dealers d ON d.id = c.dealer_id
                         WHERE c.deleted_at IS NULL" . $window('c.submitted_at') . $equals('c.dealer_id', $dealer) . $equals('c.status', $status)
                    . ' ORDER BY c.submitted_at DESC';
                break;

            case 'dealer-performance':
                // The sale window applies to the sales and claims counted, not to the dealer list.
                $salesWindow  = $window('s.sold_at');
                $claimsWindow = $window('k.submitted_at');
                $sql = "SELECT d.business_name dealer, d.code, d.city, d.state, d.status,
                               (SELECT COUNT(*) FROM mattresses m WHERE m.current_dealer_id = d.id AND m.current_status = 'DEALER_RECEIVED' AND m.deleted_at IS NULL) in_stock,
                               (SELECT COUNT(*) FROM sales s WHERE s.dealer_id = d.id AND s.deleted_at IS NULL{$salesWindow}) sales,
                               (SELECT COALESCE(SUM(s.sale_price), 0) FROM sales s WHERE s.dealer_id = d.id AND s.deleted_at IS NULL{$salesWindow}) value,
                               (SELECT COUNT(*) FROM warranty_claims k WHERE k.dealer_id = d.id AND k.deleted_at IS NULL{$claimsWindow}) claims,
                               ROUND(
                                 (SELECT COUNT(*) FROM warranty_claims k WHERE k.dealer_id = d.id AND k.deleted_at IS NULL{$claimsWindow}) * 100
                                 / NULLIF((SELECT COUNT(*) FROM sales s WHERE s.dealer_id = d.id AND s.deleted_at IS NULL{$salesWindow}), 0), 1) claim_rate
                          FROM dealers d WHERE d.deleted_at IS NULL
                         ORDER BY sales DESC, d.business_name";
                // The window binds appear three times each in the order above.
                $binds = array_merge(
                    $this->windowBinds($from, $to),
                    $this->windowBinds($from, $to),
                    $this->windowBinds($from, $to),
                    $this->windowBinds($from, $to),
                    $this->windowBinds($from, $to),
                );
                break;

            case 'warranty-expiry':
                $sql = "SELECT DATE_FORMAT(w.end_date, '%Y-%m') month, COUNT(*) warranties, COUNT(DISTINCT w.dealer_id) dealers
                          FROM warranties w
                         WHERE w.deleted_at IS NULL AND w.status = 'ACTIVE'" . $window('w.end_date') . $equals('w.dealer_id', $dealer)
                    . ' GROUP BY month ORDER BY month';
                break;

            case 'replacements':
                $sql = "SELECT DATE(CONVERT_TZ(r.issued_at, '+00:00', '+05:30')) issued_on, c.claim_number, o.serial_number original,
                               n.serial_number replacement, p.name product, d.business_name dealer, u.full_name approved_by
                          FROM replacements r
                          JOIN warranty_claims c ON c.id = r.claim_id
                          JOIN mattresses o ON o.id = r.original_mattress_id
                          JOIN mattresses n ON n.id = r.replacement_mattress_id
                          JOIN product_variants v ON v.id = n.product_variant_id
                          JOIN products p ON p.id = v.product_id
                          JOIN dealers d ON d.id = r.dealer_id
                          LEFT JOIN users u ON u.id = r.approved_by_user_id
                         WHERE 1 = 1" . $window('r.issued_at') . $equals('r.dealer_id', $dealer)
                    . ' ORDER BY r.issued_at DESC';
                break;

            default:
                throw AppException::notFound('report');
        }

        return [$sql, $binds];
    }

    /** @return list<string> */
    private function windowBinds(?string $from, ?string $to): array
    {
        $binds = [];
        if ($from !== null) {
            $binds[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $binds[] = $to . ' 23:59:59.999999';
        }

        return $binds;
    }

    private function date(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
