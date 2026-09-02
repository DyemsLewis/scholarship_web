<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditPlatformReadiness extends Command
{
    protected $signature = 'platform:readiness
        {--strict : Treat deployment warnings as failures}';

    protected $description = 'Audit deployment-critical platform configuration without changing application data';

    public function handle(Migrator $migrator): int
    {
        $checks = [
            $this->checkAppKey(),
            $this->checkDatabase(),
            $this->checkMigrations($migrator),
            $this->checkStorage(),
            $this->checkOpenSsl(),
            $this->checkEnvironment(),
            $this->checkDebugMode(),
            $this->checkHttps(),
            $this->checkQueue(),
            $this->checkMail(),
            $this->checkPrivacyContact(),
            $this->checkBackups(),
            $this->checkRetention(),
            $this->checkBilling(),
            $this->checkScheduler(),
        ];

        $this->components->info('Scholarship platform readiness audit');
        $this->table(
            ['Check', 'Status', 'Details'],
            collect($checks)->map(fn (array $check): array => [
                $check['label'],
                strtoupper($check['status']),
                $check['details'],
            ]),
        );

        $failures = collect($checks)->where('status', 'fail')->count();
        $warnings = collect($checks)->where('status', 'warn')->count();

        if ($failures > 0) {
            $this->error("{$failures} blocking readiness check(s) failed.");

            return self::FAILURE;
        }

        if ($warnings > 0) {
            $this->warn("{$warnings} deployment warning(s) remain. Local development can continue.");

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $this->info('All readiness checks passed.');

        return self::SUCCESS;
    }

    private function checkAppKey(): array
    {
        $configured = filled(config('app.key'));

        return $this->result(
            'Application key',
            $configured ? 'pass' : 'fail',
            $configured ? 'Encryption key is configured.' : 'Set APP_KEY before running the platform.',
        );
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->result('Database connection', 'pass', 'The configured database is reachable.');
        } catch (Throwable $error) {
            return $this->result('Database connection', 'fail', 'Database connection failed: '.$error->getMessage());
        }
    }

    private function checkMigrations(Migrator $migrator): array
    {
        try {
            if (! $migrator->repositoryExists()) {
                return $this->result('Database migrations', 'fail', 'The migrations table does not exist. Run php artisan migrate.');
            }

            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

            return $this->result(
                'Database migrations',
                $pending === [] ? 'pass' : 'fail',
                $pending === []
                    ? 'All migration files have been applied.'
                    : count($pending).' migration(s) are pending. Run php artisan migrate --force during deployment.',
            );
        } catch (Throwable $error) {
            return $this->result('Database migrations', 'fail', 'Migration status could not be checked: '.$error->getMessage());
        }
    }

    private function checkStorage(): array
    {
        $paths = [storage_path(), storage_path('framework'), storage_path('logs')];
        $writable = collect($paths)->every(fn (string $path): bool => is_dir($path) && is_writable($path));

        return $this->result(
            'Writable storage',
            $writable ? 'pass' : 'fail',
            $writable ? 'Storage, framework cache, and log paths are writable.' : 'Grant the web process write access to storage and bootstrap/cache.',
        );
    }

    private function checkOpenSsl(): array
    {
        $loaded = extension_loaded('openssl');

        return $this->result(
            'OpenSSL extension',
            $loaded ? 'pass' : 'fail',
            $loaded ? 'TLS support is available for mail and payment requests.' : 'Enable the PHP OpenSSL extension.',
        );
    }

    private function checkEnvironment(): array
    {
        $production = app()->environment('production');

        return $this->result(
            'Application environment',
            $production ? 'pass' : 'warn',
            $production ? 'APP_ENV is production.' : 'APP_ENV is '.app()->environment().'; use production on the hosted server.',
        );
    }

    private function checkDebugMode(): array
    {
        $disabled = ! (bool) config('app.debug');

        return $this->result(
            'Debug mode',
            $disabled ? 'pass' : 'warn',
            $disabled ? 'APP_DEBUG is disabled.' : 'Disable APP_DEBUG before public hosting.',
        );
    }

    private function checkHttps(): array
    {
        $url = (string) config('app.url');
        $secure = str_starts_with(strtolower($url), 'https://');

        return $this->result(
            'Public HTTPS URL',
            $secure ? 'pass' : 'warn',
            $secure ? "APP_URL uses HTTPS ({$url})." : 'Set APP_URL to the final HTTPS domain before hosting.',
        );
    }

    private function checkQueue(): array
    {
        $connection = (string) config('queue.default');
        $async = ! in_array($connection, ['', 'sync'], true);

        return $this->result(
            'Background queue',
            $async ? 'pass' : 'warn',
            $async ? "Queue connection is {$connection}; keep a queue worker running." : 'Use database, Redis, or another asynchronous queue for email notifications.',
        );
    }

    private function checkMail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $deliverable = ! in_array($mailer, ['', 'log', 'array'], true) && filled($from);

        return $this->result(
            'Email delivery',
            $deliverable ? 'pass' : 'warn',
            $deliverable ? "Mailer {$mailer} is configured from {$from}." : 'Configure a real mail transport and sender address before hosting.',
        );
    }

    private function checkPrivacyContact(): array
    {
        $email = (string) config('privacy.contact_email');
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

        return $this->result(
            'Privacy contact',
            $valid ? 'pass' : 'warn',
            $valid ? "Privacy requests are directed to {$email}." : 'Set PRIVACY_CONTACT_EMAIL to a monitored address.',
        );
    }

    private function checkBackups(): array
    {
        $enabled = (bool) config('platform.backup.enabled');
        $path = trim((string) config('platform.backup.path'));
        $outsidePublic = $path !== '' && ! str_starts_with(
            strtolower(str_replace('\\', '/', $path)),
            strtolower(str_replace('\\', '/', public_path())),
        );
        $retention = (int) config('platform.backup.retention_days');

        if (! $enabled) {
            return $this->result(
                'Automated backups',
                app()->environment('production') ? 'fail' : 'warn',
                'Enable PLATFORM_BACKUP_ENABLED on the hosted server and run platform:backup once to verify it.',
            );
        }

        $valid = $outsidePublic && $retention > 0 && class_exists(\ZipArchive::class);

        return $this->result(
            'Automated backups',
            $valid ? 'pass' : 'fail',
            $valid
                ? "Daily verified backups are configured for {$path} with {$retention}-day retention."
                : 'Use a positive retention period, enable PHP zip, and keep PLATFORM_BACKUP_PATH outside public/.',
        );
    }

    private function checkRetention(): array
    {
        $notificationDays = (int) config('privacy.retention.read_notifications_days');
        $activityDays = (int) config('privacy.retention.activity_logs_days');
        $valid = $notificationDays > 0 && $activityDays > 0;

        return $this->result(
            'Data retention',
            $valid ? 'pass' : 'fail',
            $valid
                ? "Read notifications are retained for {$notificationDays} days and activity logs for {$activityDays} days."
                : 'Configure positive retention periods for read notifications and activity logs.',
        );
    }

    private function checkBilling(): array
    {
        if (! config('billing.enabled')) {
            return $this->result('Provider billing', 'pass', 'Provider billing is disabled; payment credentials are not required.');
        }

        $configured = filled(config('billing.paymongo.secret_key'))
            && filled(config('billing.paymongo.webhook_secret'));

        return $this->result(
            'Provider billing',
            $configured ? 'pass' : 'fail',
            $configured ? 'PayMongo secret and webhook verification are configured.' : 'Billing is enabled but PAYMONGO_SECRET_KEY or PAYMONGO_WEBHOOK_SECRET is missing.',
        );
    }

    private function checkScheduler(): array
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php')) ?: '';
        $configured = collect([
            'scholarships:send-reminders',
            'platform:backup',
            'platform:prune-data',
        ])->every(fn (string $command): bool => str_contains($consoleRoutes, $command));

        return $this->result(
            'Scheduled operations',
            $configured ? 'pass' : 'warn',
            $configured
                ? 'Reminders, backups, and retention cleanup are scheduled; configure one server cron entry for schedule:run.'
                : 'Configure reminder, backup, and retention schedules before hosting.',
        );
    }

    private function result(string $label, string $status, string $details): array
    {
        return compact('label', 'status', 'details');
    }
}
