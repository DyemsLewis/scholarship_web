<?php

namespace App\Console\Commands;

use App\Services\RecipientMonitoringReminderService;
use Illuminate\Console\Command;

class SendMonitoringReminders extends Command
{
    protected $signature = 'monitoring:send-reminders';

    protected $description = 'Send idempotent recipient monitoring reminders and overdue escalations';

    public function handle(RecipientMonitoringReminderService $reminders): int
    {
        $counts = $reminders->send();

        $this->info(
            "Created {$counts['total']} monitoring alert(s): "
            ."{$counts['applicant']} recipient, {$counts['provider']} provider, {$counts['overdue']} overdue."
        );

        return self::SUCCESS;
    }
}
