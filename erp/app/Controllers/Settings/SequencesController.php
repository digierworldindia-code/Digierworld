<?php

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Services\Billing\DocumentNumberAllocator;

/**
 * Document series per financial year (Setup Wizard step 5). Owners can
 * continue an existing paper/Excel book by entering the last number used;
 * a series can never be moved back below a number already issued.
 */
class SequencesController extends BaseController
{
    public function index()
    {
        $fyService = service('fy');
        $current   = $fyService->current();
        $fyId      = (int) ($this->request->getGet('fy') ?: $current['id']);
        $fyService->ensureSequences($fyId);
        $alloc = new DocumentNumberAllocator($this->db, $fyService);
        $rows  = $this->db->table('document_sequences')->where('financial_year_id', $fyId)->orderBy('is_statutory', 'DESC')->orderBy('id')->get()->getResultArray();
        foreach ($rows as &$r) {
            $r['max_used']   = $alloc->maxUsed((int) $r['id']);
            $r['next_label'] = DocumentNumberAllocator::format($r, (int) $r['next_number']);
        }
        unset($r);

        return $this->render('settings/sequences', [
            'pageTitle'   => 'Document series',
            'breadcrumbs' => [['Settings'], ['Document series']],
            'rows'        => $rows,
            'fys'         => $this->db->table('financial_years')->orderBy('start_date', 'DESC')->get()->getResultArray(),
            'fyId'        => $fyId,
            'wizard'      => $this->request->getGet('wizard') !== null,
        ]);
    }

    public function update($id)
    {
        $seq = $this->db->table('document_sequences')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        $alloc   = new DocumentNumberAllocator($this->db, service('fy'));
        $prefix  = trim((string) $this->request->getPost('prefix'));
        $suffix  = trim((string) $this->request->getPost('suffix'));
        $padding = (int) $this->request->getPost('padding');
        $lastUsed = $this->request->getPost('last_used');
        $next    = $lastUsed !== null && $lastUsed !== '' ? (int) $lastUsed + 1 : (int) $seq['next_number'];
        $maxUsed = $alloc->maxUsed((int) $seq['id']);

        $errors = DocumentNumberAllocator::validateFormat($prefix, $suffix, $padding, max($next, 1), (bool) $seq['is_statutory']);
        if ($prefix === '' && (bool) $seq['is_statutory']) {
            $errors[] = 'A prefix is required so each series is distinguishable.';
        }
        if ($next < 1) {
            $errors[] = 'The next number must be 1 or more.';
        }
        if ($next <= $maxUsed) {
            $errors[] = "Number {$maxUsed} has already been issued in this series — the next number must be at least " . ($maxUsed + 1) . '. Numbers are never reused.';
        }
        $issued = $this->db->table('billing_documents')->where('sequence_id', $seq['id'])->countAllResults() + $this->db->table('payment_receipts')->where('sequence_id', $seq['id'])->countAllResults();
        if ($issued > 0 && ($prefix !== $seq['prefix'] || $suffix !== $seq['suffix'])) {
            $errors[] = 'Documents have already been issued in this series this year; the prefix/suffix cannot change mid-year (create continuity issues). Change it from the next financial year.';
        }
        $dupe = $this->db->table('document_sequences')->where(['financial_year_id' => $seq['financial_year_id'], 'prefix' => $prefix, 'suffix' => $suffix])->where('id !=', $seq['id'])->countAllResults();
        if ($dupe > 0) {
            $errors[] = 'Another series in this financial year already uses this prefix — each series must be unique.';
        }
        if ($errors !== []) {
            return redirect()->back()->with('error', implode(' ', $errors));
        }

        $data = ['prefix' => $prefix, 'suffix' => $suffix, 'padding' => $padding, 'next_number' => $next, 'name' => trim((string) $this->request->getPost('name')) ?: $seq['name'], 'updated_by' => $this->userId];
        $this->db->table('document_sequences')->where('id', $seq['id'])->update($data);
        service('audit')->log('sequence.updated', 'document_sequence', (int) $seq['id'], $seq, $data, 'Series ' . $seq['series_code'] . ' → next ' . DocumentNumberAllocator::format($data, $next));
        service('settingsStore')->markSetupStep('sequences');

        return redirect()->back()->with('success', 'Series saved. Next number will be ' . DocumentNumberAllocator::format($data, $next) . '.');
    }
}
