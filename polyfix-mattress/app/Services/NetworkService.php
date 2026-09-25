<?php

namespace App\Services;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Libraries\Identifiers;
use App\Libraries\Tx;
use CodeIgniter\Database\BaseConnection;

/**
 * The dealer network: dealerships and the applications that become them.
 *
 * Editing a dealer's details and suspending a dealer are different decisions,
 * so they need different permissions and the second always carries a reason.
 * Every change keeps a point-in-time version of the record it replaced.
 */
final class NetworkService
{
    public const FIELDS = ['business_name', 'owner_name', 'email', 'phone', 'gst_number', 'address_line1', 'address_line2', 'city', 'state', 'pincode', 'public_listed', 'is_showroom', 'notes'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function instance(): self
    {
        return new self(db_connect());
    }

    /** @return array{id:string, code:string} */
    public function createDealer(array $in): array
    {
        return Tx::run(function (BaseConnection $db) use ($in): array {
            $id   = uuid4();
            $code = (new Identifiers($db))->dealerCode();
            $db->table('dealers')->insert($this->columns($in) + [
                'id'           => $id,
                'code'         => $code,
                'status'       => 'ACTIVE',
                'onboarded_at' => utc_now(),
                'created_by'   => service('requestContext')->userId(),
            ]);
            Audit::instance($db)->record('DEALER_CREATED', 'dealer', $id, null, [
                'code' => $code, 'businessName' => $in['business_name'], 'city' => $in['city'], 'state' => $in['state'],
            ]);

            return ['id' => $id, 'code' => $code];
        }, $this->db);
    }

    public function updateDealer(string $id, array $in): array
    {
        return Tx::run(function (BaseConnection $db) use ($id, $in): array {
            $before = $db->table('dealers')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray()
                ?? throw AppException::notFound('dealer');

            $audit = Audit::instance($db);
            $audit->version('dealer', $id, $before, 'dealer details updated');
            $db->table('dealers')->where('id', $id)->update($this->columns($in) + ['updated_by' => service('requestContext')->userId()]);

            $changed = [];
            foreach ($this->columns($in) as $column => $value) {
                if ((string) $before[$column] !== (string) $value) {
                    $changed[$column] = ['from' => $before[$column], 'to' => $value];
                }
            }
            $audit->record('DEALER_UPDATED', 'dealer', $id, null, ['changed' => $changed]);

            return ['code' => $before['code'], 'business_name' => $in['business_name']];
        }, $this->db);
    }

    /** Suspension, reinstatement or termination — always with a reason, and it ends their sessions. */
    public function changeStatus(string $id, string $status, string $reason): array
    {
        if (! in_array($status, ['ACTIVE', 'SUSPENDED', 'TERMINATED'], true)) {
            throw AppException::rule('Choose a valid status.');
        }
        if ($status !== 'ACTIVE' && mb_strlen(trim($reason)) < 5) {
            throw AppException::rule('Give a reason when suspending or terminating a dealer.');
        }

        return Tx::run(function (BaseConnection $db) use ($id, $status, $reason): array {
            $before = $db->table('dealers')->where(['id' => $id, 'deleted_at' => null])->get()->getRowArray()
                ?? throw AppException::notFound('dealer');
            if ($before['status'] === $status) {
                throw AppException::rule('That dealer is already ' . strtolower($status) . '.');
            }

            $db->table('dealers')->where('id', $id)->update(['status' => $status, 'updated_by' => service('requestContext')->userId()]);

            // A dealer who is no longer active cannot keep a live session.
            if ($status !== 'ACTIVE') {
                $userIds = array_column($db->table('dealer_users')->select('user_id')->where('dealer_id', $id)->get()->getResultArray(), 'user_id');
                if ($userIds !== []) {
                    $db->table('sessions')->whereIn('user_id', $userIds)->where('revoked_at', null)
                        ->update(['revoked_at' => utc_now(), 'revoked_reason' => 'dealer ' . strtolower($status)]);
                }
            }

            Audit::instance($db)->record('DEALER_STATUS_CHANGED', 'dealer', $id,
                ['status' => $before['status']], ['status' => $status, 'code' => $before['code']], $reason);

            return ['code' => $before['code'], 'status' => $status];
        }, $this->db);
    }

    /**
     * Approving an application creates the dealership; rejecting records why.
     * The applicant's own details are kept exactly as they submitted them.
     *
     * @return array{status:string, dealer_code:?string, dealer_id:?string}
     */
    public function reviewApplication(string $id, string $decision, string $notes, array $overrides = []): array
    {
        if (! in_array($decision, ['APPROVED', 'REJECTED', 'INFO_REQUESTED'], true)) {
            throw AppException::rule('Choose approve, reject or request information.');
        }

        return Tx::run(function (BaseConnection $db) use ($id, $decision, $notes, $overrides): array {
            $application = $db->query('SELECT * FROM dealer_applications WHERE id = ? FOR UPDATE', [$id])->getRowArray()
                ?? throw AppException::notFound('application');
            if ($application['status'] === 'APPROVED') {
                throw AppException::conflict('This application has already been approved.');
            }

            $dealerId = null;
            $code     = null;
            if ($decision === 'APPROVED') {
                $dealerId = uuid4();
                $code     = (new Identifiers($db))->dealerCode();
                $db->table('dealers')->insert([
                    'id'            => $dealerId,
                    'code'          => $code,
                    'business_name' => $overrides['business_name'] ?? $application['business_name'],
                    'owner_name'    => $overrides['owner_name'] ?? $application['owner_name'],
                    'email'         => $application['email'],
                    'phone'         => $application['mobile'],
                    'gst_number'    => $application['gst_number'],
                    'address_line1' => mb_substr($overrides['address_line1'] ?? $application['address'], 0, 200),
                    'city'          => $overrides['city'] ?? $application['city'],
                    'state'         => $overrides['state'] ?? $application['state'],
                    'pincode'       => $overrides['pincode'] ?? '000000',
                    'status'        => 'ACTIVE',
                    'public_listed' => ! empty($overrides['public_listed']) ? 1 : 0,
                    'is_showroom'   => ! empty($overrides['is_showroom']) ? 1 : 0,
                    'onboarded_at'  => utc_now(),
                    'created_by'    => service('requestContext')->userId(),
                ]);
            }

            $db->table('dealer_applications')->where('id', $id)->update([
                'status'            => $decision,
                'review_notes'      => $notes !== '' ? $notes : null,
                'reviewed_by'       => service('requestContext')->userId(),
                'reviewed_at'       => utc_now(),
                'created_dealer_id' => $dealerId,
            ]);

            Audit::instance($db)->record('DEALER_APPLICATION_REVIEWED', 'dealer_application', $id,
                ['status' => $application['status']], ['status' => $decision, 'dealerCode' => $code], $notes !== '' ? $notes : null);

            return ['status' => $decision, 'dealer_code' => $code, 'dealer_id' => $dealerId];
        }, $this->db);
    }

    /** @return array<string, mixed> only the columns a form may set */
    private function columns(array $in): array
    {
        $row = [];
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $in)) {
                continue;
            }
            $value = $in[$field];
            $row[$field] = match ($field) {
                'public_listed', 'is_showroom' => $value ? 1 : 0,
                'email'                        => ($value ?? '') === '' ? null : strtolower(trim($value)),
                'gst_number'                   => ($value ?? '') === '' ? null : strtoupper(trim($value)),
                'address_line2', 'notes'       => ($value ?? '') === '' ? null : trim($value),
                default                        => is_string($value) ? trim($value) : $value,
            };
        }

        return $row;
    }
}
