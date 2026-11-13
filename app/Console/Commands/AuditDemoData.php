<?php

namespace App\Console\Commands;

use Database\Seeders\Support\WorkflowDemoAudit;
use Illuminate\Console\Command;
use RuntimeException;

final class AuditDemoData extends Command
{
    protected $signature = 'demo:audit';

    protected $description = 'Read-only check of a fresh demo candidate, including dates, bookings, and stage readiness';

    public function handle(): int
    {
        try {
            $report = WorkflowDemoAudit::inspect();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $timeline = $report['timeline'];
        $this->info("Demo audit passed ({$timeline['timezone']}). No data was changed.");
        $this->line("Checked {$report['users']} accounts, {$timeline['rounds']} rounds, {$timeline['applications']} applications, {$timeline['sessions']} sessions, {$timeline['check_ins']} check-ins, and {$timeline['releases']} releases.");

        return self::SUCCESS;
    }
}
