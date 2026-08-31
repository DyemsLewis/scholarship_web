<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

class PrepareDemoData extends Command
{
    protected $signature = 'demo:prepare {--force : Refresh the built-in fictional demo records without prompting}';

    protected $description = 'Prepare the local fictional accounts, programs, applications, and service examples for a demonstration';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo preparation is available only in local or testing environments.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Refresh the built-in fictional demo records? Normal user accounts are not removed.',
        )) {
            $this->comment('Demo preparation cancelled.');

            return self::SUCCESS;
        }

        $this->call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]);

        $this->newLine();
        $this->info('Demo data is ready. The fictional admin, providers, applicant, programs, completed application, and service examples have been refreshed.');
        $this->line('Use the configured DEMO_PASSWORD or the default local password: password123');

        return self::SUCCESS;
    }
}
