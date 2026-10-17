<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/** Company-wide active sessions with immediate revocation. */
class SessionsController extends BaseController
{
    public function index()
    {
        $rows = $this->db->table('user_sessions s')
            ->select('s.*, p.full_name')
            ->join('user_profiles p', 'p.user_id = s.user_id', 'left')
            ->where('s.revoked_at', null)
            ->where('s.last_activity_at >', date('Y-m-d H:i:s', strtotime('-' . (int) config('Session')->expiration . ' seconds')))
            ->orderBy('s.last_activity_at', 'DESC')->get()->getResultArray();

        return $this->render('admin/sessions', [
            'pageTitle' => 'Active sessions', 'breadcrumbs' => [['Users', 'admin/users'], ['Active sessions']],
            'rows' => $rows, 'currentSid' => session_id(),
        ]);
    }

    public function revoke($id)
    {
        $row = $this->db->table('user_sessions')->where('id', $this->idOr404($id))->get()->getRowArray() ?? $this->notFound();
        if ($row['session_id'] === session_id()) {
            return redirect()->back()->with('error', 'Use "Sign out" to end your own current session.');
        }
        $this->db->table('user_sessions')->where('id', $row['id'])->update([
            'revoked_at' => date('Y-m-d H:i:s'), 'revoked_by' => $this->userId, 'revoke_reason' => 'Revoked by administrator',
        ]);
        service('audit')->log('session.revoked', 'user', (int) $row['user_id'], null, null, 'Administrator revoked a session', ['session_row' => (int) $row['id']]);

        return redirect()->back()->with('success', 'Session revoked. That device is signed out on its next request.');
    }
}
