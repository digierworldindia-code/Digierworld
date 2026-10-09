<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\EmailOutboxModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class Outbox extends BaseCommand
{
    protected $group       = 'BearingCave';
    protected $name        = 'bearingcave:outbox';
    protected $description = 'Deliver queued emails (and retry failed ones up to 5 attempts).';

    public function run(array $params)
    {
        if (! policy('email.delivery_enabled', false)) {
            CLI::write('Email delivery is disabled in platform settings; nothing sent.', 'yellow');

            return EXIT_SUCCESS;
        }
        $rows = model(EmailOutboxModel::class)->groupStart()->where('status', 'queued')->orGroupStart()->where('status', 'failed')->where('attempts <', 5)->groupEnd()->groupEnd()->orderBy('id')->findAll(200);
        $ok = 0;
        foreach ($rows as $r) {
            $ok += service('notifications')->deliver((int) $r['id']) ? 1 : 0;
        }
        CLI::write("Accepted by mail server: {$ok} of " . count($rows));

        return EXIT_SUCCESS;
    }
}
