<?php

namespace Tests\Feature;

use App\Mail\PortalNotificationMail;
use App\Models\ApplicationSchedule;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Support\StageResultInquiry;
use Database\Seeders\Support\DemoStageScores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StageResultInquiryNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_passed_stage_includes_the_program_contact_without_changing_its_next_step(): void
    {
        Mail::fake();
        [$provider, $application] = $this->application();
        foreach (['screening', 'formal_application', 'exam', 'interview'] as $stage) {
            $application->refresh();
            $payload = ['result' => 'passed'];
            if (in_array($stage, ['exam', 'interview'], true)) {
                ApplicationSchedule::create(['scholarship_application_id' => $application->id, 'type' => $stage,
                    'title' => 'Completed assessment', 'scheduled_at' => now()->subHour(), 'mode' => 'onsite',
                    'status' => 'completed', 'completed_at' => now(), 'created_by' => $provider->id]);
                $payload['evaluation'] = ['criteria_scores' => DemoStageScores::atPercentage($application->selection_scoring_snapshot, $stage, 90),
                    'expected_stage_revision' => 0, 'expected_revision' => 0];
            }
            $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/stages/{$stage}/result", $payload)->assertOk();
            $notification = PortalNotification::where('user_id', $application->applicant_id)->latest('id')->firstOrFail();
            $this->assertStringContainsString('Your next step is', $notification->message);
            $this->assertStringContainsString('For more information about this result, email the provider at program@example.org.', $notification->message);
            $this->assertStringNotContainsString($provider->email, $notification->message);
            $this->assertStringContainsString('/dashboard/applications/', $notification->action_url);
        }
        $this->assertSame('decision', $application->fresh()->workflow_stage);
        $this->assertNull($application->fresh()->final_outcome);
        Mail::assertQueued(PortalNotificationMail::class, fn ($mail) => str_contains($mail->notificationMessage, 'program@example.org'));
    }

    public function test_missing_program_email_uses_the_organization_public_email(): void
    {
        [, $application] = $this->application();
        $application->scholarship->update(['contact_email' => null]);
        $this->assertStringContainsString('organization@example.org', StageResultInquiry::message($application->fresh()));
    }

    public function test_invalid_or_missing_public_emails_do_not_expose_private_account_email(): void
    {
        [$provider, $application] = $this->application();
        $application->scholarship->update(['contact_email' => 'invalid-address']);
        $provider->providerProfile()->update(['provider_contact_email' => null]);
        $message = StageResultInquiry::message($application->fresh());
        $this->assertStringContainsString('contact details in your application', $message);
        $this->assertStringNotContainsString($provider->email, $message);
        $this->assertStringNotContainsString('invalid-address', $message);
    }

    public function test_failed_stage_notification_does_not_say_the_applicant_passed(): void
    {
        Mail::fake();
        [$provider, $application] = $this->application();
        $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/stages/screening/result", [
            'result' => 'not_passed', 'decision_reason' => 'other', 'notes' => 'The recorded eligibility review was not satisfied.',
        ])->assertOk();
        $notification = PortalNotification::where('user_id', $application->applicant_id)->latest('id')->firstOrFail();
        $this->assertSame('Pre-screening not passed', $notification->title);
        $this->assertStringNotContainsString('For more information about this result', $notification->message);
    }

    private function application(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'email' => 'private-owner@example.org']);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'provider_contact_email' => 'organization@example.org']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Notification test scholarship',
            'description' => 'Stage notification test.', 'status' => 'published', 'review_rubric' => [],
            'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'], 'contact_email' => 'program@example.org']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => User::factory()->create()->id,
            'status' => 'submitted', 'submitted_at' => now()]);

        return [$provider, app(ApplicationWorkflowService::class)->start($application)];
    }
}
