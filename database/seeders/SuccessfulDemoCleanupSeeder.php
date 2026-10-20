<?php

namespace Database\Seeders;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\ApplicationStageSession;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringCycle;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\RecipientBenefitEntitlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SuccessfulDemoCleanupSeeder extends Seeder
{
    private const PRODUCTION_CONFIRMATION = 'BUILD_COMPLETE_DEMONSTRATION';

    private const PRIMARY_PROVIDER_USERNAME = 'harborlight';

    private const SUCCESSFUL_APPLICANT_USERNAME = 'vincentrafaeltorres';

    private const ACTIVE_MONITORING_APPLICANT_USERNAME = 'nicoleandreabautista';

    /**
     * Keep one positive record at each meaningful workflow point. Failed,
     * rejected, correction, overdue, and issue scenarios are still removed.
     */
    private const RETAINED_APPLICANT_USERNAMES = [
        'alyssamaereyes',
        'biancalouisenavarro',
        'markangelodelacruz',
        'carlojaviermendoza',
        'sofiamarievillanueva',
        'gabrielluisramos',
        'trishaannegarcia',
        'danielpaoloaquino',
        self::ACTIVE_MONITORING_APPLICANT_USERNAME,
        self::SUCCESSFUL_APPLICANT_USERNAME,
    ];

    private const REMOVED_PROVIDER_USERNAMES = [
        'provider.pending',
        'provider.revision',
    ];

    private const REMOVED_PROGRAM_TITLES = [
        'Commute Forward Student Grant',
        'Connected Classrooms Access Grant',
        'Saturday Learning Support Award',
    ];

    public function run(): void
    {
        if (app()->environment('production')
            && getenv('DEMO_SEED_CONFIRM') !== self::PRODUCTION_CONFIRMATION) {
            throw new RuntimeException('Production demo cleanup requires explicit one-time confirmation.');
        }

        $provider = User::query()
            ->where('username', self::PRIMARY_PROVIDER_USERNAME)
            ->where('email', 'programs@harborlight.test')
            ->firstOrFail();
        $successfulApplicant = User::query()
            ->where('username', self::SUCCESSFUL_APPLICANT_USERNAME)
            ->where('email', 'vincent-rafaeltorres@demo.test')
            ->firstOrFail();
        $activeMonitoringApplicant = User::query()
            ->where('username', self::ACTIVE_MONITORING_APPLICANT_USERNAME)
            ->where('email', 'nicole-andreabautista@demo.test')
            ->firstOrFail();
        $program = Scholarship::query()
            ->whereBelongsTo($provider, 'provider')
            ->where('title', 'Harborlight Senior Scholars Program')
            ->firstOrFail();
        $successfulApplication = ScholarshipApplication::query()
            ->whereBelongsTo($program)
            ->whereBelongsTo($successfulApplicant, 'applicant')
            ->firstOrFail();
        $activeMonitoringApplication = ScholarshipApplication::query()
            ->whereBelongsTo($program)
            ->whereBelongsTo($activeMonitoringApplicant, 'applicant')
            ->firstOrFail();

        $removedApplicants = User::query()
            ->where('role', 'applicant')
            ->where('email', 'like', '%@demo.test')
            ->whereNotIn('username', self::RETAINED_APPLICANT_USERNAMES)
            ->get(['id']);
        $removedApplicationIds = ScholarshipApplication::query()
            ->whereIn('applicant_id', $removedApplicants->pluck('id'))
            ->pluck('id');
        $removedProviderIds = User::query()
            ->whereIn('username', self::REMOVED_PROVIDER_USERNAMES)
            ->pluck('id');
        $retainedApplicationIds = ScholarshipApplication::query()
            ->whereIn('applicant_id', User::query()
                ->whereIn('username', self::RETAINED_APPLICANT_USERNAMES)
                ->select('id'))
            ->pluck('id');
        $removedSessionIds = ApplicationStageSession::query()
            ->where('scholarship_id', $program->id)
            ->whereDoesntHave('assignments', fn ($query) => $query
                ->whereIn('scholarship_application_id', $retainedApplicationIds))
            ->pluck('id');
        $cycleIds = RecipientMonitoringCycle::query()
            ->where('scholarship_id', $program->id)
            ->pluck('id');

        DB::transaction(function () use (
            $provider,
            $successfulApplicant,
            $successfulApplication,
            $activeMonitoringApplicant,
            $activeMonitoringApplication,
            $program,
            $removedApplicants,
            $removedSessionIds,
        ): void {
            ProviderServiceRequest::query()
                ->where('provider_id', $provider->id)
                ->delete();
            ProviderServicePurchase::query()
                ->where('provider_id', $provider->id)
                ->where(function ($query): void {
                    $query->where('status', '!=', 'paid')
                        ->orWhere('fulfillment_status', '!=', 'completed');
                })
                ->delete();

            SupportReport::query()
                ->where('provider_id', $provider->id)
                ->where('status', '!=', 'resolved')
                ->delete();

            ApplicationStageSession::query()
                ->whereIn('id', $removedSessionIds)
                ->delete();

            User::query()
                ->whereIn('id', $removedApplicants->pluck('id'))
                ->delete();
            User::query()
                ->whereIn('username', self::REMOVED_PROVIDER_USERNAMES)
                ->delete();

            Scholarship::query()
                ->where('provider_id', $provider->id)
                ->whereIn('title', self::REMOVED_PROGRAM_TITLES)
                ->delete();

            RecipientMonitoringCycle::query()
                ->where('scholarship_id', $program->id)
                ->where('title', '!=', 'First semester progress check')
                ->delete();

            $cycle = RecipientMonitoringCycle::query()
                ->where('scholarship_id', $program->id)
                ->where('title', 'First semester progress check')
                ->firstOrFail();
            $cycle->update([
                'status' => 'open',
                'closed_by' => null,
                'closed_at' => null,
            ]);

            RecipientMonitoringCase::query()->updateOrCreate(
                [
                    'recipient_monitoring_cycle_id' => $cycle->id,
                    'scholarship_application_id' => $activeMonitoringApplication->id,
                ],
                [
                    'applicant_id' => $activeMonitoringApplicant->id,
                    'status' => RecipientSupportCatalog::CASE_UNDER_REVIEW,
                    'due_status' => 'open',
                    'effective_due_at' => $cycle->due_at,
                    'outcome' => null,
                    'status_reason' => 'An academic progress record is waiting for provider review.',
                    'next_action_role' => 'provider',
                    'next_action' => 'review_requirements',
                    'opened_at' => $cycle->published_at ?? $cycle->created_at,
                    'submitted_at' => now()->subDays(5),
                    'review_started_at' => now()->subDays(4),
                    'ready_to_close_at' => null,
                    'closed_by' => null,
                    'closed_at' => null,
                ],
            );

            RecipientMonitoringCase::query()->updateOrCreate(
                [
                    'recipient_monitoring_cycle_id' => $cycle->id,
                    'scholarship_application_id' => $successfulApplication->id,
                ],
                [
                    'applicant_id' => $successfulApplicant->id,
                    'status' => RecipientSupportCatalog::CASE_CLOSED,
                    'due_status' => 'completed',
                    'outcome' => RecipientSupportCatalog::OUTCOME_COMPLETED,
                    'status_reason' => 'All requirements were approved and the monitoring period was completed.',
                    'next_action_role' => null,
                    'next_action' => null,
                    'opened_at' => $cycle->published_at ?? $cycle->created_at,
                    'submitted_at' => now()->subDays(5),
                    'review_started_at' => now()->subDays(4),
                    'ready_to_close_at' => now()->subDays(3),
                    'closed_by' => $provider->id,
                    'closed_at' => now()->subDays(3),
                ],
            );

            RecipientBenefitRelease::query()
                ->where('scholarship_id', $program->id)
                ->where('title', 'Academic standing release review')
                ->delete();

            $secondRelease = RecipientBenefitRelease::query()
                ->where('scholarship_id', $program->id)
                ->where('title', 'Second semester education allowance')
                ->firstOrFail();
            $secondRelease->update([
                'release_at' => now()->addDays(30)->setTime(10, 0),
                'status' => 'scheduled',
            ]);

            $completionRelease = RecipientBenefitRelease::query()->updateOrCreate(
                [
                    'scholarship_id' => $program->id,
                    'title' => 'Program completion allowance',
                ],
                [
                    'created_by' => $provider->id,
                    'release_at' => now()->subDays(2)->setTime(10, 0),
                    'benefit_description' => 'Final education allowance for the completed recipient lifecycle.',
                    'amount' => 9000,
                    'release_method' => 'bank_transfer',
                    'instructions' => 'Confirm the final allowance against the provider release register.',
                    'requires_original_verification' => false,
                    'status' => 'completed',
                    'published_at' => now()->subDays(10),
                ],
            );
            $releaseRecord = RecipientBenefitReleaseRecord::query()->updateOrCreate(
                [
                    'recipient_benefit_release_id' => $completionRelease->id,
                    'scholarship_application_id' => $successfulApplication->id,
                ],
                [
                    'applicant_id' => $successfulApplicant->id,
                    'status' => 'released',
                    'originals_verified' => true,
                    'notes' => 'The second education allowance was released and confirmed by the recipient.',
                    'recorded_by' => $provider->id,
                    'recorded_at' => now()->subDays(2),
                    'released_at' => now()->subDays(2),
                ],
            );
            RecipientBenefitReceiptResponse::query()->updateOrCreate(
                ['recipient_benefit_release_record_id' => $releaseRecord->id],
                [
                    'scholarship_application_id' => $successfulApplication->id,
                    'applicant_id' => $successfulApplicant->id,
                    'response_type' => 'confirmed',
                    'received_on' => now()->subDays(2)->toDateString(),
                    'recipient_note' => 'I received the full second education allowance.',
                    'status' => 'confirmed',
                    'responded_at' => now()->subDay(),
                ],
            );

            app(RecipientBenefitEntitlementService::class)
                ->ensureForApplication($successfulApplication);
            RecipientBenefitEntitlement::query()
                ->where('scholarship_application_id', $successfulApplication->id)
                ->update([
                    'status' => RecipientSupportCatalog::ENTITLEMENT_FULFILLED,
                    'fulfilled_at' => now()->subDay(),
                    'withheld_at' => null,
                    'cancelled_at' => null,
                ]);

            app(RecipientBenefitEntitlementService::class)
                ->ensureForApplication($activeMonitoringApplication);
        });

        foreach ($removedApplicants as $applicant) {
            Storage::disk('local')->deleteDirectory("demo-showcase/profile-photos/{$applicant->id}");
            Storage::disk('local')->deleteDirectory("demo-showcase/applicant-evidence/{$applicant->id}");
        }
        foreach ($removedApplicationIds as $applicationId) {
            Storage::disk('local')->deleteDirectory("demo-showcase/application-documents/{$applicationId}");
            Storage::disk('local')->deleteDirectory("demo-showcase/benefit-receipts/{$applicationId}");
            Storage::disk('local')->deleteDirectory("demo-showcase/support-decisions/{$applicationId}");
            foreach ($cycleIds as $cycleId) {
                Storage::disk('local')->deleteDirectory("demo-showcase/monitoring/{$cycleId}/{$applicationId}");
            }
        }
        foreach ($removedProviderIds as $providerId) {
            Storage::disk('local')->deleteDirectory("demo-showcase/provider-verification/{$providerId}");
        }
        foreach ($removedSessionIds as $sessionId) {
            Storage::disk('local')->deleteDirectory("demo-showcase/application-stage-evidence/{$sessionId}");
        }

        $this->command?->info('Demo cleanup complete: positive workflow stages and successful recipient records remain.');
    }
}
