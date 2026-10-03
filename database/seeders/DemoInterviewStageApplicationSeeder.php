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

class DemoInterviewStageApplicationSeeder extends Seeder
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
        $submittedAt = now()->subDays(12);
        $examAt = now()->subDays(2)->setTime(9, 0);
        $interviewAt = now()->subDay()->setTime(13, 30);
        $programTitle = 'Tulay Aral Leadership Interview Grant';

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
            'category' => 'Leadership scholarship',
            'program_cycle' => 'School Year 2026-2027',
            'description' => 'A fictional local testing scholarship with document review, a qualifying exam, a finalist interview, and a final award decision.',
            'eligibility' => 'Open to enrolled Grade 11 or Grade 12 learners with at least an 85% general average, financial need, and community involvement.',
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
            'slots_available' => 8,
            'application_limit' => 40,
            'application_opens_at' => now()->subDays(20)->toDateString(),
            'deadline' => now()->addDays(3)->toDateString(),
            'expected_results_at' => $interviewAt->copy()->addDays(14)->toDateString(),
            'support_starts_at' => $interviewAt->copy()->addDays(30)->toDateString(),
            'support_ends_at' => $interviewAt->copy()->addYear()->toDateString(),
            'handoff_mode' => 'onsite',
            'handoff_deadline' => now()->subDays(4)->toDateString(),
            'handoff_instructions' => 'Bring the original enrollment record, report card, and school ID before participating in the provider selection activities.',
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
                'title' => 'Leadership education grant',
                'amount' => 18000,
                'coverage' => 'fixed',
                'frequency' => 'one_time',
                'duration' => 'School Year 2026-2027',
                'description' => 'One-time support for school expenses, transportation, and learning materials.',
                'sort_order' => 0,
            ],
            [
                'type' => 'mentorship',
                'title' => 'Leadership mentoring',
                'frequency' => 'per_term',
                'duration' => 'School Year 2026-2027',
                'description' => 'Mentoring focused on leadership, educational planning, and community involvement.',
                'sort_order' => 1,
            ],
        ]);

        $scholarship->events()->delete();
        $scholarship->events()->createMany([
            [
                'type' => 'exam',
                'title' => 'Leadership Grant Qualifying Exam',
                'scheduled_at' => $examAt,
                'mode' => 'onsite',
                'venue' => 'Tulay Aral Community Desk',
                'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                'instructions' => 'Bring a recent school ID and exam notice.',
                'status' => 'completed',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
            [
                'type' => 'interview',
                'title' => 'Leadership Grant Finalist Interview',
                'scheduled_at' => $interviewAt,
                'mode' => 'online',
                'online_url' => 'https://meet.google.com/demo-interview-room',
                'instructions' => 'Join 10 minutes early and be ready to discuss your educational goals, financial need, and community involvement.',
                'status' => 'scheduled',
                'created_by' => $provider->id,
                'updated_by' => $provider->id,
            ],
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
                'question_id' => 'leadership_goal',
                'prompt' => 'How have you contributed to your school or community?',
                'answer' => 'I participate in school activities, help younger learners, and assist with responsibilities at home.',
            ]],
            'rubric_scores' => null,
            'rubric_total_score' => null,
            'notes' => 'Fictional application prepared for interview-stage workflow testing.',
            'review_notes' => 'Required files and prior selection stages were completed before the finalist interview.',
            'correction_status' => null,
            'correction_message' => null,
            'correction_targets' => null,
            'correction_response' => null,
            'decision_reason' => null,
            'reviewed_by' => $provider->id,
            'assigned_reviewer_id' => null,
            'reviewed_at' => now()->subDay(),
            'submitted_at' => $submittedAt,
            'terms_accepted_at' => $submittedAt,
            'terms_version' => Terms::VERSION,
        ]);

        $application->documents()->delete();
        foreach ($requirements as $requirement) {
            $contents = $this->demoDocument($applicant->name, $requirement, $programTitle);
            $path = 'application-documents/'.$application->id.'/interview-demo-'.Str::slug($requirement).'.svg';
            Storage::disk('local')->put($path, $contents);
            $application->documents()->create([
                'uploaded_by' => $applicant->id,
                'document_name' => $requirement,
                'original_name' => Str::slug($requirement).'.svg',
                'path' => $path,
                'mime_type' => 'image/svg+xml',
                'size' => strlen($contents),
                'status' => 'accepted',
                'review_notes' => 'Accepted for the fictional interview-stage demonstration.',
                'reviewed_by' => $provider->id,
                'reviewed_at' => now()->subDays(6),
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
            'formal_application' => 'Original-document handoff recorded as complete for the fictional demo.',
            'exam' => 'Applicant passed the fictional qualifying exam.',
        ] as $stage => $notes) {
            $application = $workflow->recordStageResult(
                $application,
                $stage,
                'passed',
                $provider,
                $notes,
            );
        }

        $application->schedules()->create([
            'type' => 'exam',
            'title' => 'Leadership Grant Qualifying Exam',
            'scheduled_at' => $examAt,
            'mode' => 'onsite',
            'venue' => 'Tulay Aral Community Desk',
            'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
            'instructions' => 'Completed fictional qualifying exam.',
            'status' => 'completed',
            'attendance_status' => 'attended',
            'applicant_acknowledged_at' => $examAt->copy()->subDay(),
            'completed_at' => $examAt->copy()->addMinutes(90),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        app(DecisionSupportService::class)->syncApplication(
            $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']),
            'demo_interview_stage_seed',
        );
        app(ScholarshipEventService::class)->syncApplication($application->fresh());

        $application->schedules()
            ->where('type', 'interview')
            ->where('status', 'scheduled')
            ->update([
                'status' => 'completed',
                'attendance_status' => 'not_required',
                'attendance_notes' => 'Interview activity completed. The provider still needs to record the interview result.',
                'completed_at' => now(),
                'updated_by' => $provider->id,
            ]);

        $application = $application->fresh(['scholarship', 'applicant', 'documents', 'stageProgresses', 'schedules']);
        $this->command?->info(sprintf(
            'Interview-stage demo ready: %s | %s | %s',
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
  <text x="95" y="112" fill="#fbbf24" font-family="Arial, sans-serif" font-size="25" font-weight="700">INTERVIEW-STAGE DEMO RECORD</text>
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
