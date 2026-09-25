<?php

namespace App\Controllers\Admin;

use App\Exceptions\AppException;
use App\Libraries\Audit;
use App\Services\PublicFormService;
use CodeIgniter\HTTP\RedirectResponse;

/** Enquiries sent from the website's contact form. */
class Leads extends AdminController
{
    private const STATUSES = ['NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED'];

    public function index(): string
    {
        $status  = $this->oneOf('status', self::STATUSES);
        $need    = $this->oneOf('requirement', array_keys(PublicFormService::REQUIREMENTS));
        $builder = db_connect()->table('contact_submissions l')
            ->select('l.*, u.full_name assigned_to')->join('users u', 'u.id = l.assigned_to_user_id', 'left')
            ->orderBy('l.created_at', 'DESC');
        if ($status) {
            $builder->where('l.status', $status);
        }
        if ($need) {
            $builder->where('l.requirement', $need);
        }

        return $this->render('admin/leads/index', 'Leads', 'admin/leads', [
            'list' => $this->paginate($builder), 'statuses' => self::STATUSES, 'f' => compact('status', 'need'),
        ]);
    }

    public function update(string $id): RedirectResponse
    {
        $status = (string) $this->request->getPost('status');
        $notes  = $this->post('internal_notes');

        return $this->act(function () use ($id, $status, $notes): void {
            if (! in_array($status, self::STATUSES, true)) {
                throw AppException::rule('Choose a valid status.');
            }
            $db     = db_connect();
            $before = $db->table('contact_submissions')->where('id', $id)->get()->getRowArray() ?? throw AppException::notFound('lead');

            $db->table('contact_submissions')->where('id', $id)->update([
                'status'             => $status,
                'internal_notes'     => $notes !== '' ? $notes : null,
                'assigned_to_user_id' => $this->ctx->userId(),
                'responded_at'       => $before['responded_at'] ?? ($status === 'NEW' ? null : utc_now()),
            ]);
            Audit::instance()->record('LEAD_UPDATED', 'contact_submission', $id, ['status' => $before['status']], ['status' => $status]);
        }, 'Lead updated.', site_url('admin/leads'));
    }
}
