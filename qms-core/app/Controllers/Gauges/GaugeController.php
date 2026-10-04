<?php

namespace App\Controllers\Gauges;

use App\Controllers\BaseController;
use App\Libraries\Paging;
use App\Services\Gauges\GaugeService;
use Throwable;

class GaugeController extends BaseController
{
    private const FIELDS = ['gauge_code', 'gauge_name', 'gauge_type_id', 'make', 'serial_no', 'measuring_range', 'least_count',
        'unit_id', 'location', 'calibration_frequency_days', 'status', 'calibration_date', 'due_date', 'certificate_no', 'agency'];

    public function index(): string
    {
        $filters = array_map('strval', (array) $this->request->getGet(['q', 'type', 'status', 'due']));
        $paging  = Paging::fromRequest($this->request, 30);

        return $this->render('gauges/index', [
            'title'    => 'Gauges',
            'gauges'   => service('gauges')->list($filters, $paging),
            'types'    => service('masterData')->lookup('gauge-types'),
            'statuses' => GaugeService::STATUSES,
            'filters'  => $filters,
            'paging'   => $paging,
        ]);
    }

    public function new(): string
    {
        return $this->form(null);
    }

    public function create()
    {
        try {
            $id = service('gauges')->create((array) $this->request->getPost(self::FIELDS), $this->currentUser());
        } catch (Throwable $e) {
            return $this->backWithError($e);
        }

        return redirect()->to(site_url('gauges/' . $id))->with('success', 'Gauge registered.');
    }

    public function show(int $id): string
    {
        $service = service('gauges');
        $gauge   = $service->find($id);
        $today   = service('productionCalendar')->current()['date'];

        return $this->render('gauges/show', [
            'title'        => 'Gauge ' . $gauge['gauge_code'],
            'gauge'        => $gauge,
            'availability' => $service->availability($gauge, $today),
            'calibrations' => $service->calibrations($id),
            'usage'        => $service->whereUsed($id, null, 50),
            'errors'       => session()->getFlashdata('errors') ?? [],
            'today'        => $today,
        ]);
    }

    public function edit(int $id): string
    {
        return $this->form(service('gauges')->find($id));
    }

    public function update(int $id)
    {
        return $this->perform(
            fn () => service('gauges')->update($id, (array) $this->request->getPost(self::FIELDS), $this->currentUser()),
            site_url('gauges/' . $id),
            'Gauge saved.',
        );
    }

    public function calibrate(int $id)
    {
        $file = $this->request->getFile('certificate');
        if ($file !== null && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            $max = config('Qms')->certificateMaxKb;
            if (! $this->validate(['certificate' => "uploaded[certificate]|max_size[certificate,{$max}]|ext_in[certificate,pdf,png,jpg,jpeg]|mime_in[certificate,application/pdf,image/png,image/jpeg]"])) {
                return redirect()->back()->withInput()->with('error', implode(' ', $this->validator->getErrors()));
            }
        } else {
            $file = null;
        }

        return $this->perform(
            fn () => service('gauges')->recordCalibration($id, (array) $this->request->getPost(['calibration_date', 'due_date', 'result', 'agency', 'certificate_no', 'remarks']), $file, $this->currentUser()),
            site_url('gauges/' . $id),
            'Calibration recorded.',
        );
    }

    public function certificate(int $calibrationId)
    {
        $file = service('gauges')->certificate($calibrationId);
        service('audit')->log('DOWNLOAD', 'gauge_certificate', $calibrationId, null, null, $file['name']);

        return $this->response
            ->setHeader('Content-Type', $file['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody((string) file_get_contents($file['path']));
    }

    /**
     * @param array<string, mixed>|null $gauge
     */
    private function form(?array $gauge): string
    {
        $masters = service('masterData');

        return $this->render('gauges/form', [
            'title'    => $gauge === null ? 'Register gauge' : 'Edit gauge ' . $gauge['gauge_code'],
            'gauge'    => $gauge,
            'types'    => $masters->lookup('gauge-types'),
            'units'    => $masters->lookup('units'),
            'statuses' => GaugeService::STATUSES,
            'errors'   => session()->getFlashdata('errors') ?? [],
        ]);
    }
}
