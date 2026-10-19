<?php

namespace App\Console\Commands;

use App\Services\ProviderServiceReminderService;
use Illuminate\Console\Command;

class SendProviderServiceReminders extends Command
{
    protected $signature = 'provider-services:send-reminders';

    protected $description = 'Send role-specific provider service action and meeting reminders';

    public function handle(ProviderServiceReminderService $reminders): int
    {
        $counts = $reminders->send();

        $this->info(
            "Created {$counts['total']} provider service alert(s): "
            ."{$counts['provider']} provider, {$counts['platform']} platform, "
            ."{$counts['meeting']} meeting; {$counts['overdue']} overdue."
        );

        return self::SUCCESS;
    }
}
