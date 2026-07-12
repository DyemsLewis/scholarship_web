<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderExamReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_exam_review_statuses_save_the_expected_applicant_facing_contract(): void
    {
        Mail::fake();
        [$provider, $applicant, $application] = $this->examApplication();

        $cases = [
            [
                'status' => 'exam_qualified',
                'reason' => 'for_exam',
                'note' => 'Applicant passed eligibility screening and is qualified to take the scholarship exam.',
                'label' => 'Qualified for exam',
                'title' => 'Qualified for exam',
                'message' => 'Your application for Exam Workflow Scholarship passed initial screening and is qualified for the scholarship exam.',
            ],
            [
                'status' => 'exam_scheduled',
                'reason' => 'exam_scheduled',
                'note' => 'Scholarship exam is scheduled. Check provider instructions for date, venue, or online exam details.',
                'label' => 'Exam scheduled',
                'title' => 'Scholarship exam scheduled',
                'message' => 'Your scholarship exam for Exam Workflow Scholarship has been scheduled. Check provider notes for instructions.',
            ],
            [
                'status' => 'exam_taken',
                'reason' => 'exam_completed',
                'note' => 'Scholarship exam was marked as taken.',
                'label' => 'Exam taken',
                'title' => 'Exam marked taken',
                'message' => 'Your scholarship exam for Exam Workflow Scholarship was marked as taken.',
            ],
            [
                'status' => 'exam_passed',
                'reason' => 'passed_exam',
                'note' => 'Applicant passed the scholarship exam and may proceed to final award review.',
                'label' => 'Passed exam',
                'title' => 'Exam passed',
                'message' => 'You passed the scholarship exam for Exam Workflow Scholarship. Your application will proceed to final review.',
            ],
            [
                'status' => 'exam_failed',
                'reason' => 'failed_exam',
                'note' => 'Applicant did not pass the scholarship exam.',
                'label' => 'Failed exam',
                'title' => 'Exam not passed',
                'message' => 'Your application for Exam Workflow Scholarship did not pass the scholarship exam. Review the provider note for details. Reason: Failed exam.',
            ],
        ];

        foreach ($cases as $case) {
            $this->actingAs($provider)
                ->patchJson("/provider/applications/{$application->id}/status", [
                    'status' => $case['status'],
                    'decision_reason' => $case['reason'],
                    'review_notes' => $case['note'],
                ])
                ->assertOk()
                ->assertJsonPath('application.status', $case['status'])
                ->assertJsonPath('application.decision_reason', $case['reason'])
                ->assertJsonPath('application.review_notes', $case['note'])
                ->assertJsonPath('application.status_progress.current', $case['status'])
                ->assertJsonPath('application.status_progress.label', $case['label']);

            $this->assertDatabaseHas('scholarship_applications', [
                'id' => $application->id,
                'status' => $case['status'],
                'decision_reason' => $case['reason'],
                'review_notes' => $case['note'],
            ]);
            $this->assertDatabaseHas('application_status_histories', [
                'scholarship_application_id' => $application->id,
                'to_status' => $case['status'],
                'decision_reason' => $case['reason'],
                'review_notes' => $case['note'],
            ]);
            $this->assertDatabaseHas('portal_notifications', [
                'user_id' => $applicant->id,
                'type' => 'application_status',
                'title' => $case['title'],
                'message' => $case['message'],
            ]);
        }
    }

    public function test_failed_exam_requires_a_decision_reason(): void
    {
        Mail::fake();
        [$provider, $_applicant, $application] = $this->examApplication();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'exam_failed',
                'review_notes' => 'Applicant did not pass the scholarship exam.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_reason');

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, PortalNotification::query()->count());
    }

    private function examApplication(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Exam Workflow Scholarship',
            'description' => 'Used to verify exam screening workflow.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return [$provider, $applicant, $application];
    }
}
