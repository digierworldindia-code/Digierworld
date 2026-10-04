<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Core\UploadedFile;
use App\Exceptions\ValidationException;
use App\Services\Settings\SettingsValidator;
use Tests\Support\DatabaseTestCase;
use Tests\Support\HttpTestTrait;

/**
 * Master data, gauges / calibrations and settings: rule validation, storage
 * and the matching forms.
 *
 * @internal
 */
final class MasterDataGaugeSettingsTest extends DatabaseTestCase
{
    use HttpTestTrait;

    /**
     * @param callable(): mixed $action
     *
     * @return array<string, string>
     */
    private function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected a validation error.');
    }

    public function testMasterDataRulesAndStorage(): void
    {
        $masters = service('masterData');
        $id      = $masters->create('machines', ['machine_code' => 'vmc-07', 'machine_name' => 'VMC 7', 'pm_due_date' => '2026-12-31', 'department_id' => ''], $this->admin);
        $row     = $this->db->table('machines')->where('id', $id)->get(1)->getRowArray();
        $this->assertSame('VMC-07', $row['machine_code'], 'codes are stored in capitals');
        $this->assertNull($row['department_id']);

        $this->assertArrayHasKey('machine_code', $this->errorsOf(fn () => $masters->create('machines', ['machine_code' => 'VMC-07', 'machine_name' => 'Again'], $this->admin)));
        $errors = $this->errorsOf(fn () => $masters->create('machines', ['machine_code' => '-bad code', 'machine_name' => '', 'pm_due_date' => '31-12-2026'], $this->admin));
        $this->assertSame('The Machine number field is not in the correct format.', $errors['machine_code']);
        $this->assertSame('The Machine name field is required.', $errors['machine_name']);
        $this->assertSame('The PM due date field must contain a valid date.', $errors['pm_due_date']);

        $errors = $this->errorsOf(fn () => $masters->create('shifts', ['code' => 'D', 'name' => 'Day', 'start_time' => '08:00', 'end_time' => '08:00', 'sort_order' => '4'], $this->admin));
        $this->assertSame('The End time field must differ from the Start time field.', $errors['end_time']);
        $shift = $masters->create('shifts', ['code' => 'g', 'name' => 'General', 'start_time' => '09:00', 'end_time' => '17:30', 'sort_order' => '4'], $this->admin);
        $this->assertSame('17:30:00', $this->db->table('shifts')->where('id', $shift)->get(1)->getRow('end_time'));

        $errors = $this->errorsOf(fn () => $masters->create('employees', ['employee_code' => 'E9', 'full_name' => 'X', 'email' => 'not-an-email', 'skill_level' => '7', 'phone' => 'abc'], $this->admin));
        $this->assertSame(['skill_level', 'phone', 'email'], array_keys($errors));
        $this->assertStringContainsString('must be one of: 0,1,2,3,4', $errors['skill_level']);
    }

    public function testGaugeRegistrationAndCalibrationWithCertificate(): void
    {
        $gauges = service('gauges');
        $type   = $this->id('gauge_types', 'code', 'DVC');
        $today  = $this->today();

        $errors = $this->errorsOf(fn () => $gauges->create(['gauge_code' => 'X1', 'gauge_name' => 'Caliper', 'gauge_type_id' => (string) $type, 'least_count' => '0,01', 'calibration_frequency_days' => '5000'], $this->admin));
        $this->assertSame(['least_count', 'calibration_frequency_days'], array_keys($errors));

        $id = $gauges->create([
            'gauge_code' => 'dvc-099', 'gauge_name' => 'Digital caliper 0-200', 'gauge_type_id' => (string) $type, 'least_count' => '0.01',
            'calibration_frequency_days' => '365', 'calibration_date' => $today, 'due_date' => date('Y-m-d', strtotime($today . ' +365 days')),
        ], $this->admin);
        $this->assertSame('DVC-099', $this->db->table('gauges')->where('id', $id)->get(1)->getRow('gauge_code'));
        $this->assertSame(1, $this->db->table('gauge_calibrations')->where('gauge_id', $id)->countAllResults(), 'initial calibration recorded');

        $errors = $this->errorsOf(fn () => $gauges->recordCalibration($id, ['calibration_date' => $today, 'due_date' => $today, 'result' => 'PASS'], null, $this->admin));
        $this->assertStringContainsString('must be one of: ACCEPTED,ADJUSTED,REJECTED', $errors['result']);

        $pdf = tempnam(sys_get_temp_dir(), 'qms');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile(['name' => 'cert.pdf', 'type' => 'application/pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK, 'size' => filesize($pdf)]);
        $due  = date('Y-m-d', strtotime($today . ' +180 days'));
        $gauges->recordCalibration($id, ['calibration_date' => $today, 'due_date' => $due, 'result' => 'ACCEPTED', 'agency' => 'NABL lab', 'certificate_no' => 'C-1'], $file, $this->admin);

        $this->assertSame($due, $this->db->table('gauges')->where('id', $id)->get(1)->getRow('calibration_due_date'));
        $calibration = (int) $this->db->table('gauge_calibrations')->where('gauge_id', $id)->selectMax('id', 'm')->get()->getRow('m');
        $certificate = $gauges->certificate($calibration);
        $this->assertSame('application/pdf', $certificate['mime']);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($certificate['path']));
        @unlink($certificate['path']);
        @unlink($pdf);
    }

    public function testSettingsValidation(): void
    {
        $validator = new SettingsValidator();
        $keys      = array_column(service('settings')->group('security'), 'setting_key');

        $values = [];
        foreach ($keys as $key) {
            $values[str_replace('.', '__', $key)] = (string) service('settings')->get($key);
        }
        $values['security__lockout_minutes'] = '5000';
        $values['security__max_failed_logins'] = 'ten';
        try {
            $validator->validate($values, $keys);
            $this->fail('Invalid settings were accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('The security.lockout_minutes field must contain a number less than or equal to 1440.', $e->errors()['security.lockout_minutes']);
            $this->assertSame('The security.max_failed_logins field must contain an integer.', $e->errors()['security.max_failed_logins']);
        }

        $values['security__lockout_minutes']   = '30';
        $values['security__max_failed_logins'] = '6';
        $clean = $validator->validate($values, $keys);
        $this->assertSame('6', $clean['security.max_failed_logins']);
    }

    public function testFormsShowValidationErrorsAndSaveSettings(): void
    {
        $session = ['qms_uid' => $this->admin['id'], 'qms_stamp' => $this->admin['security_stamp'], 'qms_login_at' => time(), 'qms_last_seen' => time()];

        $this->withSession($session)->post('masters/machines', ['machine_code' => '', 'machine_name' => ''], true)->assertStatus(303);
        $this->assertSame('The Machine number field is required.', session()->getFlashdata('errors')['machine_code'] ?? null);

        $this->withSession($session + $_SESSION)->post('admin/settings/company', ['company__name' => 'Precision Fittings Ltd', 'company__address' => 'Plot 7, MIDC'], true)
            ->assertRedirectTo(site_url('admin/settings?tab=company'));
        service('settings')->refresh();
        $this->assertSame('Precision Fittings Ltd', service('settings')->string('company.name'));
        $this->assertSame(1, $this->db->table('audit_logs')->where('module', 'settings')->where('action', 'UPDATE')->countAllResults());
    }
}
