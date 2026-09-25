<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Public warranty verification — the only path by which anonymous internet
 * traffic touches mattress data.
 *
 * The answer is built field by field from an explicit allow-list. Nothing is
 * copied wholesale from a row, so adding a column to `mattresses` can never
 * start leaking it here. Deliberately never returned: the customer, the dealer,
 * the price, the invoice, claim history, risk indicators and internal ids.
 *
 * A QR token is an untrusted string, used only as a lookup key. Nothing it
 * claims about the product is believed.
 */
final class VerificationService
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function instance(): self
    {
        return new self(db_connect());
    }

    /**
     * @return array{found:bool, genuine:bool, serial?:string, product?:array, manufactured_on?:string, warranty?:array, note:string}
     */
    public function verify(?string $serial, ?string $qrToken): array
    {
        $serial  = $serial !== null ? strtoupper(trim($serial)) : null;
        $qrToken = $qrToken !== null ? trim($qrToken) : null;

        // Shape checks before any query: cheap, and they keep garbage out of the logs.
        $validSerial = $serial !== null && preg_match('/^[A-Z]{3}[0-9]{8,12}$/', $serial) === 1;
        $validToken  = $qrToken !== null && preg_match('/^[A-Za-z0-9_-]{22,64}$/', $qrToken) === 1;

        if (! $validSerial && ! $validToken) {
            return $this->notFound();
        }

        $builder = $this->db->table('mattresses m')
            ->select('m.serial_number, m.manufactured_at, m.deleted_at, v.size_label, p.name, p.slug, p.category, p.comfort_level')
            ->join('product_variants v', 'v.id = m.product_variant_id')
            ->join('products p', 'p.id = v.product_id');
        $validSerial ? $builder->where('m.serial_number', $serial) : $builder->where('m.qr_token', $qrToken);
        $unit = $builder->get()->getRowArray();

        if ($unit === null || $unit['deleted_at'] !== null) {
            return $this->notFound();
        }

        $warranty = $this->db->table('warranties w')->select('w.status, w.start_date, w.end_date, w.years')
            ->join('mattresses m', 'm.id = w.mattress_id')
            ->where('m.serial_number', $unit['serial_number'])->where('w.deleted_at', null)
            ->orderBy('w.created_at', 'DESC')->get()->getRowArray();

        $result = [
            'found'   => true,
            'genuine' => true,
            'serial'  => $unit['serial_number'],
            'product' => [
                'name'     => $unit['name'],
                'slug'     => $unit['slug'],
                'category' => $unit['category'],
                'size'     => $unit['size_label'],
                'comfort'  => $unit['comfort_level'],
            ],
            'manufactured_on' => substr($unit['manufactured_at'], 0, 10),
        ];

        $brand = brand('name');

        if ($warranty === null) {
            $result['warranty'] = ['status' => 'NOT_ACTIVATED', 'start' => null, 'end' => null, 'years' => null, 'days_remaining' => null];
            $result['note']     = "This is a genuine {$brand} product. Its warranty has not been activated yet — it starts when your dealer records the sale. "
                . 'If you have already bought it, ask your dealer to record the sale against this serial number.';

            return $result;
        }

        $daysRemaining = (int) ceil((strtotime($warranty['end_date'] . ' 00:00:00 UTC') - time()) / 86400);
        // VOID and SUPERSEDED are authoritative as stored; expiry is computed,
        // so the answer is right even before any nightly job has run.
        $status = match ($warranty['status']) {
            'VOID', 'SUPERSEDED' => $warranty['status'],
            default              => $daysRemaining > 0 ? 'ACTIVE' : 'EXPIRED',
        };
        $endHuman = date('d M Y', strtotime($warranty['end_date']));

        $result['warranty'] = [
            'status'         => $status,
            'start'          => $warranty['start_date'],
            'end'            => $warranty['end_date'],
            'years'          => (int) $warranty['years'],
            'days_remaining' => $status === 'ACTIVE' ? $daysRemaining : 0,
        ];
        $result['note'] = match ($status) {
            'ACTIVE'     => "This is a genuine {$brand} product and its warranty is active until {$endHuman}.",
            'EXPIRED'    => "This is a genuine {$brand} product. Its warranty period ended on {$endHuman}.",
            'VOID'       => "This is a genuine {$brand} product, but the warranty on this unit is no longer valid. Contact your dealer for details.",
            'SUPERSEDED' => "This is a genuine {$brand} product. This unit was replaced under warranty, and the cover now sits with the replacement mattress.",
        };

        return $result;
    }

    private function notFound(): array
    {
        return [
            'found'   => false,
            'genuine' => false,
            'note'    => 'We have no record of that serial number. Check the characters on the law label, or contact the dealer you bought from. '
                . 'Serial numbers begin with ' . brand('serialPrefix') . '.',
        ];
    }
}
