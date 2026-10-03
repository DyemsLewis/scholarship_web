<?php

namespace Database\Seeders;

use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Services\ScholarshipEventService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoExamStageApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $provider = User::query()
            ->where('email', env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'))
            ->where('role', 'provider')
            ->firstOrFail();
        $applicant = User::query()
            ->where('email', env('AGREEMENT_DEMO_EMAIL', 'recipientdemo@scholarship.test'))
            ->where('role', 'applicant')
            ->firstOrFail();
        $source = Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('status', 'published')
            ->with('benefits')
            ->firstOrFail();

        $requirements = [
            'Certificate of enrollment',
            'Latest report card or grades',
            'Recent school ID',
            'Proof of income',
        ];
        $submittedAt = now()->subDays(4);
        $deadline = now()->addDays(7)->startOfDay();
        $examAt = now()->subDay()->setTime(9, 0);
        $programTitle = 'Tulay Aral Future Scholars Exam Grant';

        $scholarship = Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('title', $programTitle)
            ->first();

        if (! $scholarship) {
            $scholarship = $source->replicate();
            $scholarship->title = $programTitle;
        }

        $scholarship->fill([
            'provider_id' => $provider->id,
            'title' => $programTitle,
            'category' => 'Academic support',
            'program_cycle' => 'School Year 2026-2027',
            'description' => 'A fictional local testing scholarship with document review, a qualifying exam, an interview, and a final award decision.',
            'eligibility' => 'Open to enrolled Grade 11 or Grade 12 learners with at least an 85% general average and demonstrated financial need.',
            'requirements' => implode("\n", $requirements),
            'optional_requirements' => null,
            'post_qualification_requirements' => implode("\n", [
                'Original certificate of enrollment',
                'Original latest report card',
                'Recent school ID',
            ]),
            'application_mode' => 'online',
            'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'],
            'exam_duration_minutes' => 90,
            'exam_passing_score' => 75,
            'minimum_gwa' => 85,
            'minimum_grade_scale' => 'percentage',
            'slots_available' => 10,
            'application_limit' => 50,
            'application_opens_at' => now()->subDays(10)->toDateString(),
            'deadline' => $deadline->toDateString(),
            'expected_results_at' => $examAt->copy()->addDays(21)->toDateString(),
            'support_starts_at' => $examAt->copy()->addDays(30)->toDateString(),
            'support_ends_at' => $examAt->copy()->addYear()->toDateString(),
            'handoff_mode' => 'onsite',
            'handoff_deadline' => now()->addDays(5)->toDateString(),
            'handoff_instructions' => 'Bring the original enrollment record, report card, and school ID for final checking before taking the qualifying exam.',
            'status' => 'published',
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
            'review_rubric' => ReviewRubric::DEFAULT,
        ]);
        $scholarship->save();

        $scholarship->benefits()->delete();
        $scholarship->benefits()->createMany([
            [
                'type' => 'cash_grant',
                'title' => 'Education allowance',
                'amount' => 12000,
                'coverage' => 'fixed',
                'frequency' => 'one_time',
                'duration' => 'School Year 2026-2027',
                'description' => 'One-time support for transportation, learning materials, and connectivity.',
                'sort_order' => 0,
            ],
            [
                'type' => 'mentorship',
                'title' => 'College preparation mentoring',
                'frequency' => 'per_term',
                'duration' => 'School Year 2026-2027',
                'description' => 'Guidance for college preparation and educational planning.',
                'sort_order' => 1,
            ],
        ]);

        $scholarship->events()->delete();
        $scholarship->events()->create([
            'type' => 'exam',
            'title' => 'Future Scholars Qualifying Exam',
            'scheduled_at' => $examAt,
            'mode' => 'onsite',
            'venue' => 'Tulay Aral Community Desk',
            'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'instructions' => 'Bring a recent school ID, two pencils, and the exam schedule notice. Arrive 20 minutes early.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        $eligibility = app(ScholarshipEligibilityService::class)->evaluate(
            $scholarship->fresh(),
            $applicant->fresh(),
        );
        $application = ScholarshipApplication::query()->updateOrCreate([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
        ], [
            'status' => 'submitted',
            'workflow_version' => 2,
            'application_state' => 'submitted',
            'workflow_stage' => 'screening',
            'final_outcome' => null,
            'submission_snapshot' => null,
            'document_checklist' => $requirements,
            'optional_document_checklist' => [],
            'eligibility_score' => $eligibility['score'],
            'eligibility_breakdown' => $eligibility,
            'review_rubric_snapshot' => ReviewRubric::DEFAULT,
            'application_answers' => [[
                'question_id' => 'study_goal',
                'prompt' => 'How will this scholarship support your studies?',
                'answer' => 'It will help with transportation, learning materials, and preparation for college entrance requirements.',
            ]],
            'rubric_scores' => null,
            'rubric_total_score' => null,
            'notes' => 'Fictional application prepared for exam-stage workflow testing.',
            'review_notes' => 'Profile and required files were reviewed before the applicant advanced to the exam.',
            'correction_status' => null,
            'correction_message' => null,
            'correction_targets' => null,
            'correction_response' => null,
            'decision_reason' => null,
            'reviewed_by' => $provider->id,
            'assigned_reviewer_id' => null,
            'reviewed_at' => now()->subDays(2),
            'submitted_at' => $submittedAt,
            'terms_accepted_at' => $submittedAt,
            'terms_version' => Terms::VERSION,
        ]);

        $application->documents()->delete();
        foreach ($requirements as $requirement) {
            $contents = $this->demoDocument($applicant->name, $requirement, $programTitle);
            $path = 'application-documents/'.$application->id.'/exam-demo-'.Str::slug($requirement).'.svg';
            Storage::disk('local')->put($path, $contents);
            $application->documents()->create([
                'uploaded_by' => $applicant->id,
                'document_name' => $requirement,
                'original_name' => Str::slug($requirement).'.svg',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'size' => strlen($contents),
                'status' => 'accepted',
                'review_notes' => 'Accepted for the fictional exam-stage demonstration.',
                'reviewed_by' => $provider->id,
                'reviewed_at' => now()->subDays(2),
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
        $application = $workflow->recordStageResult(
            $application,
            'screening',
            'passed',
            $provider,
            'Eligibility and required online files verified.',
        );
        $application = $workflow->recordStageResult(
            $application,
            'formal_application',
            'passed',
            $provider,
            'Original-document handoff recorded as complete for the fictional demo.',
        );

        app(DecisionSupportService::class)->syncApplication(
            $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']),
            'demo_exam_stage_seed',
        );
        app(ScholarshipEventService::class)->syncApplication($application->fresh());

        $application->schedules()
            ->where('type', 'exam')
            ->where('status', 'scheduled')
            ->update([
                'status' => 'completed',
                'attendance_status' => 'not_required',
                'attendance_notes' => 'Exam activity completed. The provider still needs to record the exam result.',
                'completed_at' => now(),
                'updated_by' => $provider->id,
            ]);

        $application = $application->fresh(['scholarship', 'applicant', 'documents', 'stageProgresses', 'schedules']);
        $this->command?->info(sprintf(
            'Exam-stage demo ready: %s | %s | %s',
            $application->scholarship->title,
            $application->applicant->email,
            $application->status,
        ));
    }

    private function demoDocument(string $applicantName, string $documentName, string $programTitle): string
    {
        $applicantName = htmlspecialchars($applicantName, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $documentName = htmlspecialchars($documentName, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $programTitle = htmlspecialchars($programTitle, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="700" viewBox="0 0 1000 700">
  <rect width="1000" height="700" fill="#f8fafc"/>
  <rect x="55" y="55" width="890" height="590" rx="14" fill="#ffffff" stroke="#cbd5e1" stroke-width="3"/>
  <rect x="55" y="55" width="890" height="90" rx="14" fill="#0f172a"/>
  <text x="95" y="112" fill="#fbbf24" font-family="Arial, sans-serif" font-size="25" font-weight="700">EXAM-STAGE DEMO RECORD</text>
  <text x="95" y="220" fill="#0f172a" font-family="Arial, sans-serif" font-size="36" font-weight="700">{$documentName}</text>
  <text x="95" y="285" fill="#334155" font-family="Arial, sans-serif" font-size="24">Applicant: {$applicantName}</text>
  <text x="95" y="335" fill="#334155" font-family="Arial, sans-serif" font-size="22">Program: {$programTitle}</text>
  <line x1="95" y1="385" x2="905" y2="385" stroke="#e2e8f0" stroke-width="3"/>
  <text x="95" y="450" fill="#475569" font-family="Arial, sans-serif" font-size="22">Fictional accepted document for local workflow testing.</text>
  <text x="95" y="585" fill="#b45309" font-family="Arial, sans-serif" font-size="18" font-weight="700">DEMO ONLY - NOT AN OFFICIAL RECORD</text>
</svg>
SVG;
    }
}
