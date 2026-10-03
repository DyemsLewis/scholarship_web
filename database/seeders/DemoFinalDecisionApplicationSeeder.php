<?php

namespace Database\Seeders;

use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoFinalDecisionApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoInterviewStageApplicationSeeder::class);

        $sourceScholarship = Scholarship::query()
            ->where('title', 'Tulay Aral Leadership Interview Grant')
            ->with(['benefits', 'events'])
            ->sole();
        $sourceApplication = ScholarshipApplication::query()
            ->where('scholarship_id', $sourceScholarship->id)
            ->with(['applicant', 'documents'])
            ->sole();
        $provider = $sourceScholarship->provider;
        $applicant = $sourceApplication->applicant;
        $programTitle = 'Tulay Aral Finalist Excellence Grant';
        $submittedAt = now()->subDays(20);
        $examAt = now()->subDays(9)->setTime(9, 0);
        $interviewAt = now()->subDays(3)->setTime(13, 30);

        $scholarship = Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('title', $programTitle)
            ->first();

        if (! $scholarship) {
            $scholarship = $sourceScholarship->replicate();
            $scholarship->title = $programTitle;
        }

        $scholarship->fill([
            'provider_id' => $provider->id,
            'title' => $programTitle,
            'description' => 'A fictional local testing scholarship with one finalist awaiting the provider final award decision.',
            'application_opens_at' => now()->subDays(30)->toDateString(),
            'deadline' => now()->subDay()->toDateString(),
            'expected_results_at' => now()->addDays(7)->toDateString(),
            'support_starts_at' => now()->addDays(21)->toDateString(),
            'support_ends_at' => now()->addYear()->toDateString(),
            'handoff_deadline' => now()->subDays(12)->toDateString(),
            'status' => 'published',
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
        ]);
        $scholarship->save();

        $scholarship->benefits()->delete();
        $scholarship->benefits()->createMany($sourceScholarship->benefits
            ->map(fn ($benefit): array => $benefit->only([
                'type',
                'title',
                'amount',
                'coverage',
                'frequency',
                'duration',
                'description',
                'sort_order',
            ]))
            ->all());

        $scholarship->events()->delete();
        $scholarship->events()->createMany([
            [
                'type' => 'exam',
                'title' => 'Finalist Excellence Qualifying Exam',
                'scheduled_at' => $examAt,
                'mode' => 'onsite',
                'venue' => 'Tulay Aral Community Desk',
                'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                'instructions' => 'Completed fictional qualifying exam.',
                'status' => 'completed',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
            [
                'type' => 'interview',
                'title' => 'Finalist Excellence Interview',
                'scheduled_at' => $interviewAt,
                'mode' => 'online',
                'online_url' => 'https://meet.google.com/demo-finalist-room',
                'instructions' => 'Completed fictional finalist interview.',
                'status' => 'completed',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
        ]);

        $applicationFields = $sourceApplication->only([
            'document_checklist',
            'optional_document_checklist',
            'eligibility_score',
            'eligibility_breakdown',
            'review_rubric_snapshot',
            'application_answers',
            'notes',
        ]);
        $application = ScholarshipApplication::query()->updateOrCreate([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
        ], [
            ...$applicationFields,
            'status' => 'submitted',
            'workflow_version' => 2,
            'application_state' => 'submitted',
            'workflow_stage' => 'screening',
            'final_outcome' => null,
            'submission_snapshot' => null,
            'rubric_scores' => null,
            'rubric_total_score' => null,
            'notes' => 'Fictional application prepared for final-decision workflow testing.',
            'review_notes' => 'All verification and provider-managed stages are complete. Record the final award outcome.',
            'correction_status' => null,
            'correction_message' => null,
            'correction_targets' => null,
            'correction_response' => null,
            'decision_reason' => null,
            'final_outcome' => null,
            'outcome_notes' => null,
            'outcome_at' => null,
            'reviewed_by' => $provider->id,
            'assigned_reviewer_id' => null,
            'reviewed_at' => now()->subDay(),
            'submitted_at' => $submittedAt,
            'terms_accepted_at' => $submittedAt,
            'terms_version' => Terms::VERSION,
        ]);

        $application->documents()->delete();
        foreach ($sourceApplication->documents as $sourceDocument) {
            $contents = Storage::disk('local')->get($sourceDocument->path);
            $path = 'application-documents/'.$application->id.'/final-decision-'.$sourceDocument->original_name;
            Storage::disk('local')->put($path, $contents);
            $application->documents()->create([
                'uploaded_by' => $applicant->id,
                'document_name' => $sourceDocument->document_name,
                'original_name' => $sourceDocument->original_name,
                'path' => $path,
                'mime_type' => $sourceDocument->mime_type,
                'size' => strlen($contents),
                'status' => 'accepted',
                'review_notes' => 'Accepted for the fictional final-decision demonstration.',
                'reviewed_by' => $provider->id,
                'reviewed_at' => now()->subDays(15),
                'uploaded_at' => $submittedAt,
                'terms_accepted_at' => $submittedAt,
                'terms_version' => Terms::VERSION,
            ]);
        }

        $application->schedules()->delete();
        $application->stageProgresses()->delete();
        $application->statusHistories()->delete();
        $application->dssSnapshots()->delete();
        PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('action_url', route('dashboard.applications.show', $application, false))
            ->delete();

        ApplicationStatusHistory::query()->create([
            'scholarship_application_id' => $application->id,
            'changed_by' => $applicant->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'review_notes' => 'Application submitted by applicant.',
            'changed_at' => $submittedAt,
        ]);

        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application->fresh());
        foreach ([
            'screening' => 'Eligibility and required online files verified.',
            'formal_application' => 'Original-document handoff recorded as complete.',
            'exam' => 'Applicant passed the qualifying exam.',
            'interview' => 'Applicant passed the finalist interview.',
        ] as $stage => $notes) {
            $application = $workflow->recordStageResult(
                $application,
                $stage,
                'passed',
                $provider,
                $notes,
            );
        }

        foreach ([
            ['exam', 'Finalist Excellence Qualifying Exam', $examAt, 'onsite', 'Tulay Aral Community Desk', null],
            ['interview', 'Finalist Excellence Interview', $interviewAt, 'online', null, 'https://meet.google.com/demo-finalist-room'],
        ] as [$type, $title, $scheduledAt, $mode, $venue, $onlineUrl]) {
            $application->schedules()->create([
                'type' => $type,
                'title' => $title,
                'scheduled_at' => $scheduledAt,
                'mode' => $mode,
                'venue' => $venue,
                'location_address' => $venue ? 'Barangay San Isidro, Antipolo City, Rizal' : null,
                'online_url' => $onlineUrl,
                'instructions' => 'Completed fictional selection activity.',
                'status' => 'completed',
                'attendance_status' => 'attended',
                'applicant_acknowledged_at' => $scheduledAt->copy()->subDay(),
                'completed_at' => $scheduledAt->copy()->addMinutes(90),
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ]);
        }

        app(DecisionSupportService::class)->syncApplication(
            $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']),
            'demo_final_decision_seed',
        );

        $application = $application->fresh(['scholarship', 'applicant', 'documents', 'stageProgresses', 'schedules']);
        $this->command?->info(sprintf(
            'Final-decision demo ready: %s | %s | %s',
            $application->scholarship->title,
            $application->applicant->email,
            $application->workflow_stage,
        ));
    }
}
