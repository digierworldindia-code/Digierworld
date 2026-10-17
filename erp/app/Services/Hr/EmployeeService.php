<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessRuleException;
use App\Services\Settings\SettingsStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use Throwable;

/**
 * Employee master. Basic directory fields are visible with
 * hr.employee.view_basic; salary, bank details and private documents each
 * need their own explicit permission and are never part of the basic record.
 */
class EmployeeService
{
    /** Directory fields shown to anyone with hr.employee.view_basic. */
    public const BASIC_FIELDS = ['id', 'employee_code', 'full_name', 'work_phone', 'work_email', 'department_id', 'designation_id', 'reporting_manager_id', 'employment_status', 'work_location', 'user_id'];

    public const DOC_KINDS = [
        'offer_letter' => 'Offer letter', 'contract' => 'Contract / appointment letter', 'id_proof' => 'ID proof',
        'address_proof' => 'Address proof', 'education' => 'Education certificate', 'experience' => 'Experience / relieving letter', 'other' => 'Other',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    private function uid(): ?int
    {
        return service('authz')->currentUserId();
    }

    private function tx(callable $fn): mixed
    {
        $this->db->transBegin();
        try {
            $r = $fn();
            $this->db->transCommit();

            return $r;
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('employees')->where('id', $id)->get()->getRowArray();
    }

    /** @return array<string, mixed>|null employee linked to a login */
    public function forUser(int $userId): ?array
    {
        return $this->db->table('employees')->where('user_id', $userId)->get()->getRowArray();
    }

    public function nextCode(): string
    {
        $prefix = (string) service('settingsStore')->get('hr.employee_code_prefix', 'DGR-E');
        $max    = $this->db->query('SELECT MAX(CAST(SUBSTRING(employee_code, ?) AS UNSIGNED)) m FROM employees WHERE employee_code LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->getRow()->m ?? 0;

        return $prefix . str_pad((string) ((int) $max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public function normalize(array $in, ?int $excludeId = null): array
    {
        $errors = [];
        $name   = trim((string) ($in['full_name'] ?? ''));
        if ($name === '') {
            $errors['full_name'] = 'Enter the employee name.';
        }
        $doj = ymd((string) ($in['date_of_joining'] ?? ''));
        if ($doj === null) {
            $errors['date_of_joining'] = 'Enter the date of joining.';
        }
        foreach (['work_email', 'personal_email'] as $f) {
            $v = trim((string) ($in[$f] ?? ''));
            if ($v !== '' && ! filter_var($v, FILTER_VALIDATE_EMAIL)) {
                $errors[$f] = 'Email address looks invalid.';
            }
        }
        $userId = (int) ($in['user_id'] ?? 0) ?: null;
        if ($userId !== null) {
            $taken = $this->db->table('employees')->where('user_id', $userId)->where('id !=', $excludeId ?? 0)->countAllResults();
            if ($taken > 0) {
                $errors['user_id'] = 'This login is already linked to another employee.';
            }
        }
        $mgr = (int) ($in['reporting_manager_id'] ?? 0) ?: null;
        if ($mgr !== null && $excludeId !== null && $mgr === $excludeId) {
            $errors['reporting_manager_id'] = 'An employee cannot report to themselves.';
        }
        $exit = ymd((string) ($in['exit_date'] ?? ''));
        if ($exit !== null && $doj !== null && $exit < $doj) {
            $errors['exit_date'] = 'Exit date is before the joining date.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the highlighted fields.', $errors);
        }
        $s = static fn (string $k, int $max = 150) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        return [
            'full_name' => mb_substr($name, 0, 150), 'work_phone' => $s('work_phone', 20), 'work_email' => $s('work_email'),
            'personal_phone' => $s('personal_phone', 20), 'personal_email' => $s('personal_email'),
            'department_id' => (int) ($in['department_id'] ?? 0) ?: null, 'designation_id' => (int) ($in['designation_id'] ?? 0) ?: null,
            'reporting_manager_id' => $mgr, 'date_of_joining' => $doj, 'user_id' => $userId,
            'employment_type' => in_array($in['employment_type'] ?? '', ['full_time', 'part_time', 'intern', 'contract'], true) ? $in['employment_type'] : 'full_time',
            'employment_status' => in_array($in['employment_status'] ?? '', ['probation', 'active', 'notice', 'exited'], true) ? $in['employment_status'] : 'active',
            'work_location' => $s('work_location', 100),
            'attendance_mode' => ($in['attendance_mode'] ?? '') === 'self_checkin' ? 'self_checkin' : 'manual',
            'incentive_eligible' => empty($in['incentive_eligible']) ? 0 : 1,
            'emergency_contact_name' => $s('emergency_contact_name'), 'emergency_contact_phone' => $s('emergency_contact_phone', 20),
            'address' => $s('address', 300), 'exit_date' => $exit, 'exit_reason' => $s('exit_reason', 500),
        ];
    }

    /** @param array<string, mixed> $in */
    public function create(array $in, ?int $candidateId = null): int
    {
        $data = $this->normalize($in);

        return $this->tx(function () use ($data, $candidateId): int {
            $this->db->table('employees')->insert($data + ['employee_code' => $this->nextCode(), 'candidate_id' => $candidateId, 'created_by' => $this->uid()]);
            $id = (int) $this->db->insertID();
            $this->linkUser($id, $data['user_id']);
            service('leaves')->ensureBalances($id, date('Y-m-d'));
            service('audit')->log('employee.created', 'employee', $id, null, array_intersect_key($data, array_flip(self::BASIC_FIELDS)), 'Employee created: ' . $data['full_name']);

            return $id;
        });
    }

    /** @param array<string, mixed> $in */
    public function update(int $id, array $in): void
    {
        $before = $this->find($id) ?? throw new BusinessRuleException('Employee not found.');
        $data   = $this->normalize($in, $id);
        if ($data['employment_status'] === 'exited' && $data['exit_date'] === null) {
            throw new BusinessRuleException('Enter the exit date for an exited employee.', ['exit_date' => 'Required when status is Exited.']);
        }
        $this->tx(function () use ($id, $before, $data): void {
            $this->db->table('employees')->where('id', $id)->update($data + ['updated_by' => $this->uid()]);
            if ((int) $before['user_id'] !== (int) $data['user_id']) {
                if ($before['user_id']) {
                    $this->db->table('user_profiles')->where('user_id', $before['user_id'])->update(['employee_id' => null]);
                }
                $this->linkUser($id, $data['user_id']);
            }
            $changed = array_keys(array_diff_assoc(array_map('strval', array_intersect_key($data, $before)), array_map('strval', array_intersect_key($before, $data))));
            service('audit')->log('employee.updated', 'employee', $id, array_intersect_key($before, array_flip($changed)), array_intersect_key($data, array_flip($changed)), 'Employee updated: ' . $data['full_name']);
        });
    }

    private function linkUser(int $employeeId, ?int $userId): void
    {
        if ($userId !== null) {
            $this->db->table('user_profiles')->where('user_id', $userId)->update(['employee_id' => $employeeId]);
        }
    }

    // ---- bank details (encrypted; hr.employee.bank_view to see/edit) ------

    /** @return array<string, mixed>|null masked bank row (no plain account number) */
    public function bank(int $employeeId): ?array
    {
        $b = $this->db->table('employee_bank_details')->where('employee_id', $employeeId)->get()->getRowArray();
        if ($b !== null) {
            unset($b['account_number_enc']);
        }

        return $b;
    }

    /** @param array<string, mixed> $in */
    public function saveBank(int $employeeId, array $in): void
    {
        $acct = preg_replace('/\s+/', '', (string) ($in['account_number'] ?? ''));
        $ifsc = strtoupper(trim((string) ($in['ifsc'] ?? '')));
        $upi  = trim((string) ($in['upi_id'] ?? ''));
        $errors = [];
        if ($acct !== '' && ! preg_match('/^\d{6,20}$/', (string) $acct)) {
            $errors['account_number'] = 'Account number should be 6–20 digits.';
        }
        if ($ifsc !== '' && ! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            $errors['ifsc'] = 'IFSC should look like SBIN0001234.';
        }
        if ($upi !== '' && ! preg_match('/^[\w.\-]{2,}@[a-zA-Z]{2,}$/', $upi)) {
            $errors['upi_id'] = 'UPI ID should look like name@bank.';
        }
        if ($errors !== []) {
            throw new BusinessRuleException('Please correct the bank details.', $errors);
        }
        $existing = $this->db->table('employee_bank_details')->where('employee_id', $employeeId)->get()->getRowArray();
        $row = [
            'bank_name' => mb_substr(trim((string) ($in['bank_name'] ?? '')), 0, 120) ?: null,
            'account_holder' => mb_substr(trim((string) ($in['account_holder'] ?? '')), 0, 150) ?: null,
            'ifsc' => $ifsc ?: null, 'upi_id' => $upi ?: null, 'updated_by' => $this->uid(),
        ];
        if ($acct !== '') {
            $row['account_number_enc'] = SettingsStore::encrypt((string) $acct);
            $row['account_last4']      = substr((string) $acct, -4);
        }
        if ($existing === null) {
            $this->db->table('employee_bank_details')->insert($row + ['employee_id' => $employeeId, 'created_by' => $this->uid()]);
        } else {
            $this->db->table('employee_bank_details')->where('employee_id', $employeeId)->update($row);
        }
        service('audit')->log('employee.bank_updated', 'employee', $employeeId, null, ['bank_name' => $row['bank_name'], 'account_last4' => $row['account_last4'] ?? $existing['account_last4'] ?? null, 'ifsc' => $row['ifsc']], 'Employee bank details updated');
    }

    public function revealAccount(int $employeeId): string
    {
        $enc = $this->db->table('employee_bank_details')->select('account_number_enc')->where('employee_id', $employeeId)->get()->getRow()->account_number_enc ?? null;
        service('audit')->log('employee.bank_revealed', 'employee', $employeeId, null, null, 'Revealed employee bank account number', ['field' => 'account_number']);

        return (string) SettingsStore::decrypt($enc);
    }

    // ---- private documents (hr.employee.private_docs) ---------------------

    public function addDocument(int $employeeId, UploadedFile $file, string $kind, string $label): int
    {
        if (! isset(self::DOC_KINDS[$kind])) {
            throw new BusinessRuleException('Choose the document type.');
        }
        $fileId = service('files')->storeUpload($file, 'doc', 'employee', 'employee', $employeeId);
        $this->db->table('employee_documents')->insert(['employee_id' => $employeeId, 'doc_kind' => $kind, 'stored_file_id' => $fileId, 'label' => mb_substr($label, 0, 150) ?: null, 'created_by' => $this->uid()]);
        $id = (int) $this->db->insertID();
        service('audit')->log('employee.document_added', 'employee', $employeeId, null, ['kind' => $kind, 'file_id' => $fileId], 'Private document uploaded');

        return $id;
    }

    /** @return list<array<string, mixed>> active employees (id, code, name) for pickers */
    public function picker(bool $includeExited = false): array
    {
        $b = $this->db->table('employees')->select('id, employee_code, full_name, user_id')->orderBy('full_name');
        if (! $includeExited) {
            $b->where('employment_status !=', 'exited');
        }

        return $b->get()->getResultArray();
    }

    /** Is the employee employed on this date (joined, not yet exited)? */
    public static function employedOn(array $emp, string $date): bool
    {
        return $emp['date_of_joining'] <= $date && ($emp['exit_date'] === null || $emp['exit_date'] >= $date);
    }
}
