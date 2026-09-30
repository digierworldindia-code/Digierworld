<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Generates the three secrets a new installation needs.
 *
 *   php spark polyfix:keys              print them, change nothing
 *   php spark polyfix:keys --write      write them into .env in place
 *
 * This exists because the old instructions asked whoever was deploying to run
 * three different one-liners and paste the output into .env by hand. Miss one
 * and the application refused to serve, which on a production environment
 * meant an error page and no explanation.
 *
 * --write only ever fills in a secret that is missing or still a placeholder.
 * A key that is already set is left alone and reported, because overwriting
 * polyfix.encryptionKey makes every encrypted customer record unreadable and
 * overwriting polyfix.signingSecret breaks duplicate-phone detection.
 */
class Keys extends BaseCommand
{
    protected $group       = 'POLYFIX';
    protected $name        = 'polyfix:keys';
    protected $description = 'Generate the secrets a new installation needs, and optionally write them into .env.';
    protected $usage       = 'polyfix:keys [--write]';
    protected $options     = ['--write' => 'Fill the missing secrets into .env (never overwrites one already set)'];

    public function run(array $params)
    {
        $secrets = [
            'polyfix.encryptionKey' => base64_encode(random_bytes(32)),
            'polyfix.signingSecret' => bin2hex(random_bytes(32)),
            'encryption.key'        => 'hex2bin:' . bin2hex(random_bytes(32)),
        ];

        if (CLI::getOption('write') === null && ! isset($params['write'])) {
            CLI::write('Paste these into .env. Keep them secret, and keep a copy somewhere safe:', 'yellow');
            CLI::newLine();
            foreach ($secrets as $key => $value) {
                CLI::write($key . ' = ' . $value);
            }
            CLI::newLine();
            CLI::write('Losing polyfix.encryptionKey loses every encrypted customer record. Back it up.', 'yellow');
            CLI::newLine();
            CLI::write('Run again with --write to have them put into .env for you.');

            return EXIT_SUCCESS;
        }

        $path = ROOTPATH . '.env';
        if (! is_file($path)) {
            CLI::error('There is no .env file. Copy .env.example to .env first:');
            CLI::write('  cp .env.example .env');

            return EXIT_ERROR;
        }

        if (! is_writable($path)) {
            CLI::error('.env is not writable. Fix its permissions, or run without --write and paste the values in.');

            return EXIT_ERROR;
        }

        $env     = file_get_contents($path);
        $written = [];
        $kept    = [];

        foreach ($secrets as $key => $value) {
            $quoted  = preg_quote($key, '/');
            $pattern = '/^[#\s]*' . $quoted . '\s*=.*$/m';

            if (preg_match($pattern, $env, $found) !== 1) {
                // Not in the file at all: append rather than silently skip.
                $env .= PHP_EOL . $key . ' = ' . $value . PHP_EOL;
                $written[] = $key;
                continue;
            }

            if (! $this->needsFilling($found[0])) {
                $kept[] = $key;
                continue;
            }

            $env = preg_replace($pattern, $key . ' = ' . $value, $env, 1);
            $written[] = $key;
        }

        if ($written !== [] && file_put_contents($path, $env) === false) {
            CLI::error('Could not write .env.');

            return EXIT_ERROR;
        }

        @chmod($path, 0600);

        foreach ($written as $key) {
            CLI::write('set      ' . $key, 'green');
        }
        foreach ($kept as $key) {
            CLI::write('kept     ' . $key . ' (already set — not touched)', 'yellow');
        }

        CLI::newLine();
        if ($written !== []) {
            CLI::write('Back up polyfix.encryptionKey now. Without it, encrypted customer data cannot be read.', 'yellow');
        }

        // Read the file back rather than ask config(): the Polyfix instance was
        // built from the old .env when this process started, so it would report
        // the very values we have just replaced as still missing.
        $outstanding = $this->outstandingSecrets($path);
        CLI::write(
            $outstanding === []
                ? 'Secrets are complete. Next: php spark polyfix:doctor'
                : 'Still outstanding: ' . implode(', ', $outstanding),
            $outstanding === [] ? 'green' : 'yellow',
        );

        return EXIT_SUCCESS;
    }

    /**
     * Which of the two application secrets .env still lacks, read from disk.
     *
     * @return list<string>
     */
    private function outstandingSecrets(string $path): array
    {
        $env         = (string) file_get_contents($path);
        $outstanding = [];

        foreach (['polyfix.encryptionKey', 'polyfix.signingSecret'] as $key) {
            $pattern = '/^[#\s]*' . preg_quote($key, '/') . '\s*=.*$/m';
            if (preg_match($pattern, $env, $found) !== 1 || $this->needsFilling($found[0])) {
                $outstanding[] = $key;
            }
        }

        return $outstanding;
    }

    /** True when the line holds no real value: empty, commented out, or a placeholder. */
    private function needsFilling(string $line): bool
    {
        if (str_starts_with(ltrim($line), '#')) {
            return true;
        }

        $value = trim((string) substr($line, (int) strpos($line, '=') + 1));
        $value = trim($value, "'\"");

        return $value === '' || stripos($value, 'CHANGE_ME') === 0;
    }
}
