<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Crypto;
use App\Libraries\DealerScope;
use App\Libraries\RequestContext;
use App\Libraries\Settings;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sales and warranties.
 *
 * Recording a sale does four things atomically: matches or creates the
 * customer, records the sale, starts the warranty, and moves the mattress to
 * SOLD. There is no state in which a mattress is sold without a warranty.
 *
 * The warranty is calculated here, never entered. Its start is the verified
 * sale date and its length is the product's warranty term; a dealer has no way
 * to set either. Only staff holding warranty:void may change a warranty after
 * the fact, and only with a written reason that goes into the audit trail.
 */
final class SalesService
{
    private Lifecycle $lifecycle;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly RequestContext $context,
        private readonly Crypto $crypto,
    ) {
        $this->lifecycle = new Lifecycle($db);
    }

    public static function instance(): self
    {
        return new self(db_connect(), service('requestContext'), Crypto::instance());
    }

    /**
     * Dealer records a sale of a unit they hold.
     *
     * @param array{serial:string, invoice_number:string, sold_on:string, sale_price:string|float,
     *              payment_mode:string, remarks?:?string,
     *              customer: array{full_name:string, phone:string, email?:?string, address_line?:?string,
     *                              city?:?string, state?:?string, pincode?:?string}} $input
     */
    public function recordSale(array $input): array
    {
        $scope = DealerScope::current();

        return Tx::run(function (BaseConnection $db) use ($scope, $input): array {
            $mattress = $db->query(
                'SELECT m.id, m.serial_number, m.current_status, m.current_dealer_id, m.received_at, p.warranty_years, p.name AS product_name
                   FROM mattresses m
                   JOIN product_variants v ON v.id = m.product_variant_id
                   JOIN products p ON p.id = v.product_id
                  WHERE m.serial_number = ? AND m.deleted_at IS NULL FOR UPDATE',
                [$input['serial']],
            )->getRowArray();

            // Another dealer's unit reads as "not found": the dealer learns
            // nothing about stock that is not theirs.
            $mattress = $scope->assertOwns('mattresses', $mattress, 'mattress');

            if ($mattress['current_status'] !== 'DEALER_RECEIVED') {
                throw AppException::rule($mattress['current_status'] === 'SOLD'
                    ? 'This mattress has already been sold.'
                    : 'This mattress cannot be sold while it is ' . strtolower(humanise($mattress['current_status'])) . '.');
            }

            $soldOn = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input['sold_on'], new DateTimeZone('UTC'));
            if ($soldOn === false) {
                throw AppException::rule('The sale date is not a valid date.');
            }
            // A day of tolerance for time zones, but never a future date.
            if ($soldOn->getTimestamp() > time() + 86400) {
                throw AppException::rule('The sale date cannot be in the future.');
            }
            if ($mattress['received_at'] !== null && $soldOn->getTimestamp() < strtotime(substr($mattress['received_at'], 0, 10) . ' UTC') - 86400) {
                throw AppException::rule('The sale date cannot be before the date this mattress was received.');
            }

            $invoice = trim($input['invoice_number']);
            $dup     = $db->table('sales')->where(['dealer_id' => $scope->dealerId, 'invoice_number' => $invoice, 'deleted_at' => null])->countAllResults();
            if ($dup > 0) {
                throw AppException::conflict('That invoice number has already been used for another sale.');
            }

            $customerId = $this->upsertCustomer($db, $scope->dealerId, $input['customer']);
            $saleId     = uuid4();
            $soldAt     = $soldOn->format('Y-m-d') . ' 00:00:00.000000';
            $actor      = $this->context->userId();

            $db->table('sales')->insert([
                'id' => $saleId, 'dealer_id' => $scope->dealerId, 'mattress_id' => $mattress['id'], 'customer_id' => $customerId,
                'invoice_number' => $invoice, 'sold_at' => $soldAt, 'sale_price' => number_format((float) $input['sale_price'], 2, '.', ''),
                'payment_mode' => $input['payment_mode'], 'sold_by_user_id' => $actor,
                'remarks' => ($input['remarks'] ?? '') !== '' ? $input['remarks'] : null, 'created_by' => $actor,
            ]);

            $years = (int) $mattress['warranty_years'];
            [$start, $end] = self::warrantyPeriod($soldOn, $years);
            $warrantyId = uuid4();
            $db->table('warranties')->insert([
                'id' => $warrantyId, 'mattress_id' => $mattress['id'], 'sale_id' => $saleId, 'dealer_id' => $scope->dealerId,
                'customer_id' => $customerId, 'start_date' => $start, 'end_date' => $end, 'years' => $years,
                'status' => 'ACTIVE', 'terms_version' => (string) Settings::get('warranty.terms_version', 'v1.0'), 'created_by' => $actor,
            ]);

            $this->lifecycle->transition($mattress['id'], 'DEALER_RECEIVED', 'SOLD', 'SOLD', $scope->dealerId, $actor,
                ['sold_at' => $soldAt], ['invoiceNumber' => $invoice, 'warrantyYears' => $years]);

            Audit::instance($db)->record('SALE_RECORDED', 'sale', $saleId, null, [
                'serialNumber' => $mattress['serial_number'], 'invoiceNumber' => $invoice, 'soldAt' => $soldOn->format('Y-m-d'),
                'salePrice' => (float) $input['sale_price'], 'warrantyEnd' => $end,
            ]);

            return [
                'sale_id' => $saleId, 'warranty_id' => $warrantyId, 'serial' => $mattress['serial_number'],
                'product' => $mattress['product_name'], 'warranty_start' => $start, 'warranty_end' => $end, 'warranty_years' => $years,
            ];
        }, $this->db);
    }

    /** @return array{0:string, 1:string} start and end dates, Y-m-d */
    public static function warrantyPeriod(DateTimeImmutable $start, int $years): array
    {
        return [$start->format('Y-m-d'), $start->modify("+{$years} years")->format('Y-m-d')];
    }

    /**
     * The dealer's existing record for this phone number, or a new one. Matched
     * by blind index, so no contact detail is ever decrypted to find a customer.
     *
     * @param array{full_name:string, phone:string, email?:?string, address_line?:?string, city?:?string, state?:?string, pincode?:?string} $c
     */
    private function upsertCustomer(BaseConnection $db, string $dealerId, array $c): string
    {
        $phoneHash = $this->crypto->blindIndex(Crypto::normalisePhone($c['phone']));
        $existing  = $db->table('customers')->select('id, full_name, city, state, pincode')
            ->where(['dealer_id' => $dealerId, 'phone_hash' => $phoneHash, 'deleted_at' => null])->get()->getRowArray();

        $audit = Audit::instance($db);

        if ($existing !== null) {
            // A different name is a change worth keeping, not a silent overwrite.
            if ($existing['full_name'] !== $c['full_name']) {
                $audit->version('customer', $existing['id'], $existing, 'name updated during sale entry');
                $db->table('customers')->where('id', $existing['id'])->update(['full_name' => $c['full_name'], 'updated_by' => $this->context->userId()]);
                $audit->record('CUSTOMER_UPDATED', 'customer', $existing['id'], ['fullName' => $existing['full_name']],
                    ['fullName' => $c['full_name']], 'name differed from stored record at point of sale');
            }

            return $existing['id'];
        }

        $id      = uuid4();
        $email   = ($c['email'] ?? '') !== '' ? strtolower(trim($c['email'])) : null;
        $address = ($c['address_line'] ?? '') !== '' ? trim($c['address_line']) : null;

        $db->table('customers')->insert([
            'id'              => $id,
            'dealer_id'       => $dealerId,
            'full_name'       => $c['full_name'],
            'phone_encrypted' => $this->crypto->encrypt($c['phone']),
            'phone_hash'      => $phoneHash,
            'email_encrypted' => $email === null ? null : $this->crypto->encrypt($email),
            'email_hash'      => $email === null ? null : $this->crypto->blindIndex($email),
            'address_line'    => $address,
            'address_hash'    => $address === null ? null : $this->crypto->blindIndex($address),
            'city'            => ($c['city'] ?? '') ?: null,
            'state'           => ($c['state'] ?? '') ?: null,
            'pincode'         => ($c['pincode'] ?? '') ?: null,
            'created_by'      => $this->context->userId(),
        ]);

        // The audit trail records that a customer exists, not how to reach them.
        $audit->record('CUSTOMER_CREATED', 'customer', $id, null, ['fullName' => $c['full_name'], 'city' => $c['city'] ?? null]);

        return $id;
    }

    // =========================================================================
    // Staff actions on warranties
    // =========================================================================

    public function voidWarranty(string $warrantyId, string $reason): void
    {
        $this->requireReason($reason);

        Tx::run(function (BaseConnection $db) use ($warrantyId, $reason): void {
            $warranty = $db->query('SELECT * FROM warranties WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$warrantyId])->getRowArray();
            if ($warranty === null) {
                throw AppException::notFound('warranty');
            }
            if ($warranty['status'] !== 'ACTIVE') {
                throw AppException::rule('Only an active warranty can be voided. This one is ' . strtolower(humanise($warranty['status'])) . '.');
            }

            $db->table('warranties')->where('id', $warrantyId)->update([
                'status' => 'VOID', 'void_reason' => $reason, 'updated_by' => $this->context->userId(),
            ]);
            Audit::instance($db)->record('WARRANTY_VOIDED', 'warranty', $warrantyId, ['status' => 'ACTIVE'], ['status' => 'VOID'], $reason);
        }, $this->db);
    }

    /**
     * Admin correction of warranty dates — for a sale entered with the wrong
     * date, for example. Requires warranty:void, a written reason, and leaves
     * the before and after values in the audit trail and record_versions.
     */
    public function overrideWarrantyDates(string $warrantyId, string $startDate, string $endDate, string $reason): void
    {
        $this->requireReason($reason);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $end   = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($start === false || $end === false) {
            throw AppException::rule('Enter both dates as valid dates.');
        }
        if ($end <= $start) {
            throw AppException::rule('The warranty must end after it starts.');
        }

        Tx::run(function (BaseConnection $db) use ($warrantyId, $start, $end, $reason): void {
            $warranty = $db->query('SELECT * FROM warranties WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$warrantyId])->getRowArray();
            if ($warranty === null) {
                throw AppException::notFound('warranty');
            }

            $audit = Audit::instance($db);
            $audit->version('warranty', $warrantyId, $warranty, $reason);
            $db->table('warranties')->where('id', $warrantyId)->update([
                'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'), 'updated_by' => $this->context->userId(),
            ]);
            $audit->record('WARRANTY_DATES_OVERRIDDEN', 'warranty', $warrantyId,
                ['startDate' => $warranty['start_date'], 'endDate' => $warranty['end_date']],
                ['startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d')], $reason);
        }, $this->db);
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw AppException::rule('Give a reason of at least a few words. It is kept in the audit trail.');
        }
    }
}
