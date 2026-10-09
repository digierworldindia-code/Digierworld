<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ComplianceCertificationModel;
use App\Models\DocumentModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Scheduled maintenance. Run once a day from cron:
 *   15 2 * * * cd /var/www/bearingcave && php spark bearingcave:daily >> writable/logs/cron.log 2>&1
 */
class Daily extends BaseCommand
{
    protected $group       = 'BearingCave';
    protected $name        = 'bearingcave:daily';
    protected $description = 'Expire subscriptions, send renewal/document reminders, expire quotes, recompute scores, flush the email outbox.';

    public function run(array $params)
    {
        $expired = service('membership')->expireDue();
        $reminded = service('membership')->sendRenewalReminders();
        CLI::write("Subscriptions expired: {$expired}; renewal reminders: {$reminded}");

        // Certifications past their expiry date.
        $certs = model(ComplianceCertificationModel::class)->where('status', 'verified')->where('expires_on <', date('Y-m-d'))->findAll();
        foreach ($certs as $c) {
            model(ComplianceCertificationModel::class)->update($c['id'], ['status' => 'expired']);
            $co = db_connect()->table('companies')->where('id', $c['company_id'])->get()->getRowArray();
            service('notifications')->notifyCompany((int) $c['company_id'], 'document.expired', $c['cert_type'] . ' certification expired', 'Upload the renewed certificate to keep your verification current.', ($co['company_type'] ?? 'supplier') . '/verification#compliance');
        }
        CLI::write('Certifications expired: ' . count($certs));

        // Document expiry reminders.
        $sent = 0;
        foreach ((array) policy('verification.document_reminder_days', [30, 7]) as $d) {
            $docs = model(DocumentModel::class)->where('is_current', 1)->where('expires_on', date('Y-m-d', strtotime('+' . (int) $d . ' days')))->where('company_id IS NOT NULL', null, false)->findAll();
            foreach ($docs as $doc) {
                $co = db_connect()->table('companies')->where('id', $doc['company_id'])->get()->getRowArray();
                service('notifications')->notifyCompany((int) $doc['company_id'], 'document.expiring', 'Document expiring in ' . (int) $d . ' days: ' . $doc['title'], 'Please upload a renewed version.', ($co['company_type'] ?? 'buyer') . '/verification#documents');
                model(DocumentModel::class)->update($doc['id'], ['expiry_reminder_sent_at' => date('Y-m-d H:i:s')]);
                $sent++;
            }
        }
        CLI::write("Document expiry reminders: {$sent}");

        // Expire stale quotations.
        $db = db_connect();
        $db->table('quotations')->whereIn('status', ['submitted', 'shortlisted'])->where('valid_until <', date('Y-m-d'))->update(['status' => 'expired']);
        CLI::write('Quotations expired: ' . $db->affectedRows());

        // Recompute performance & buyer scores from stored records.
        $n = 0;
        foreach ($db->table('companies')->select('id, company_type')->where('deleted_at', null)->get()->getResultArray() as $c) {
            $c['company_type'] === 'supplier' ? service('performance')->computeSupplier((int) $c['id']) : service('performance')->computeBuyer((int) $c['id']);
            $n++;
        }
        CLI::write("Scores recomputed: {$n}");

        $this->call('bearingcave:outbox');

        return EXIT_SUCCESS;
    }
}
