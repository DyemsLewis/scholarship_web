<?php

namespace App\Console\Commands;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use Illuminate\Console\Command;

class SendScholarshipReminders extends Command
{
    protected $signature = 'scholarships:send-reminders';

    protected $description = 'Send idempotent scholarship deadline reminders to applicants and providers';

    public function handle(): int
    {
        $created = 0;

        foreach ([7, 3, 1, 0] as $daysRemaining) {
            Scholarship::query()
                ->with(['provider', 'bookmarks.user', 'applications:id,scholarship_id,applicant_id'])
                ->where('status', 'published')
                ->whereDate('deadline', now()->addDays($daysRemaining)->toDateString())
                ->chunkById(100, function ($scholarships) use ($daysRemaining, &$created): void {
                    foreach ($scholarships as $scholarship) {
                        $applicantIds = $scholarship->applications->pluck('applicant_id');

                        foreach ($scholarship->bookmarks as $bookmark) {
                            if (! $bookmark->user || $applicantIds->contains($bookmark->user_id)) {
                                continue;
                            }

                            $created += $this->createReminder(
                                $bookmark->user_id,
                                $scholarship,
                                $daysRemaining,
                                "/dashboard/scholarships/{$scholarship->id}",
                                'applicant'
                            );
                        }

                        if ($scholarship->provider) {
                            $created += $this->createReminder(
                                $scholarship->provider_id,
                                $scholarship,
                                $daysRemaining,
                                "/provider/programs/{$scholarship->id}/edit",
                                'provider'
                            );
                        }
                    }
                });
        }

        $this->info("Created {$created} scholarship deadline reminder(s).");

        return self::SUCCESS;
    }

    private function createReminder(int $userId, Scholarship $scholarship, int $daysRemaining, string $actionUrl, string $audience): int
    {
        $when = match ($daysRemaining) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$daysRemaining} days",
        };

        $notification = PortalNotification::firstOrCreate([
            'deduplication_key' => "deadline:{$scholarship->id}:{$audience}:{$userId}:{$daysRemaining}",
        ], [
            'user_id' => $userId,
            'type' => 'deadline_reminder',
            'title' => "Deadline {$when}: {$scholarship->title}",
            'message' => $audience === 'provider'
                ? "Your published scholarship closes {$when}. Review pending applications and keep the listing current."
                : "A scholarship you saved closes {$when}. Review your match and requirements before applying.",
            'action_url' => $actionUrl,
        ]);

        return $notification->wasRecentlyCreated ? 1 : 0;
    }
}
