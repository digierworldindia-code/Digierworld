<?php

namespace App\Services\Templates;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseConnection;

/**
 * Picks the published template for report type + part + machine.
 *
 * A template applies to its mapped parts (none = all parts) and mapped machines
 * (none = all machines). The most specific match wins:
 * part + machine (3) > part only (2) > machine only (1) > generic (0).
 * Two matches with the same specificity = configuration error (blocked at publish).
 */
class TemplateResolver
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return array<string, mixed>|null the template row, or null when none applies
     *
     * @throws ValidationException when the configuration is ambiguous
     */
    public function resolve(int $reportTypeId, int $partId, int $machineId): ?array
    {
        $candidates = $this->candidates($reportTypeId, $partId, $machineId);
        if ($candidates === []) {
            return null;
        }
        if (isset($candidates[1]) && (int) $candidates[1]['specificity'] === (int) $candidates[0]['specificity']) {
            throw ValidationException::single('template', sprintf(
                'Two published templates apply equally (%s and %s). Ask the QA Admin to fix the template mapping.',
                $candidates[0]['template_code'],
                $candidates[1]['template_code'],
            ));
        }

        return $candidates[0];
    }

    /**
     * @return list<array<string, mixed>> best first
     */
    public function candidates(int $reportTypeId, int $partId, int $machineId): array
    {
        $sql = <<<'SQL'
            SELECT t.*,
                   (EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)) * 2
                 + (EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)) AS specificity
            FROM inspection_templates t
            WHERE t.report_type_id = ? AND t.status = 'PUBLISHED'
              AND (NOT EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id)
                   OR EXISTS (SELECT 1 FROM template_part_map pm WHERE pm.template_id = t.id AND pm.part_id = ?))
              AND (NOT EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id)
                   OR EXISTS (SELECT 1 FROM template_machine_map mm WHERE mm.template_id = t.id AND mm.machine_id = ?))
            ORDER BY specificity DESC, t.id DESC
            LIMIT 2
            SQL;

        return $this->db->query($sql, [$reportTypeId, $partId, $machineId])->getResultArray();
    }

    /**
     * Machines on which at least one published template of the type applies to the part.
     *
     * @return list<array<string, mixed>>
     */
    public function machinesFor(int $reportTypeId, int $partId): array
    {
        $out = [];
        foreach ($this->db->table('machines')->where('is_active', 1)->orderBy('machine_code')->get()->getResultArray() as $machine) {
            $candidates = $this->candidates($reportTypeId, $partId, (int) $machine['id']);
            if ($candidates !== []) {
                $machine['template_code'] = $candidates[0]['template_code'];
                $machine['ambiguous']     = isset($candidates[1]) && (int) $candidates[1]['specificity'] === (int) $candidates[0]['specificity'];
                $out[]                    = $machine;
            }
        }

        return $out;
    }
}
