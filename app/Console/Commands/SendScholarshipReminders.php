<?php

namespace App\Console\Commands;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use Illuminate\Console\Command;

class SendScholarshipReminders extends Command
{
    protected $signature = 'scholarships:send-reminders';

    protected $description = 'Send idempotent scholarship and formal application deadline reminders';

    public function handle(): int
    {
        $created = 0;

        foreach ([7, 3, 1, 0] as $daysRemaining) {
            Scholarship::query()
                ->with(['provider', 'bookmarks.user', 'applications:id,scholarship_id,applicant_id'])
                ->acceptingApplications()
                ->whereDate('deadline', now()->addDays($daysRemaining)->toDateString())
                ->chunkById(100, function ($scholarships) use ($daysRemaining, &$created): void {
                    foreach ($scholarships as $scholarship) {
                        $applicantIds = $scholarship->applications->pluck('applicant_id');

                        foreach ($scholarship->bookmarks as $bookmark) {
                            if (! $bookmark->user
                                || ! $bookmark->user->isApplicant()
                                || ! $bookmark->user->isActive()
                                || ! $bookmark->user->hasVerifiedEmail()
                                || $applicantIds->contains($bookmark->user_id)) {
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

            ScholarshipApplication::query()
                ->with(['applicant', 'scholarship'])
                ->where('status', 'approved')
                ->whereHas('scholarship', fn ($query) => $query
                    ->whereDate('handoff_deadline', now()->addDays($daysRemaining)->toDateString()))
                ->chunkById(100, function ($applications) use ($daysRemaining, &$created): void {
                    foreach ($applications as $application) {
                        $created += $this->createFormalApplicationReminder($application, $daysRemaining);
                    }
                });
        }

        $this->info("Created {$created} deadline reminder(s).");

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

    private function createFormalApplicationReminder(ScholarshipApplication $application, int $daysRemaining): int
    {
        $applicant = $application->applicant;
        $scholarship = $application->scholarship;

        if (! $applicant
            || ! $scholarship
            || ! $applicant->isApplicant()
            || ! $applicant->isActive()
            || ! $applicant->hasVerifiedEmail()) {
            return 0;
        }

        $when = match ($daysRemaining) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$daysRemaining} days",
        };

        $notification = PortalNotification::firstOrCreate([
            'deduplication_key' => "formal-application:{$application->id}:applicant:{$daysRemaining}",
        ], [
            'user_id' => $applicant->id,
            'type' => 'formal_application_reminder',
            'title' => "Formal application due {$when}: {$scholarship->title}",
            'message' => "You passed portal pre-screening. Complete the provider's formal application steps {$when} to remain under consideration.",
            'action_url' => "/dashboard/applications/{$application->id}",
        ]);

        return $notification->wasRecentlyCreated ? 1 : 0;
    }
}
