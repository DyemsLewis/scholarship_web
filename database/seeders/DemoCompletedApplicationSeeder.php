<?php

namespace Database\Seeders;

use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ApplicationWorkflowService;
use App\Services\ScholarshipEligibilityService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoCompletedApplicationSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()
            ->where('email', env('STUDENT_EMAIL', 'student@scholarship.test'))
            ->first();
        $scholarship = Scholarship::query()
            ->with('provider')
            ->where('title', 'Tulay Aral Senior High Support Grant')
            ->first();

        if (! $student || ! $scholarship || ! $scholarship->provider) {
            $this->command?->warn('Demo student or Tulay Aral program is missing. Completed application was not seeded.');

            return;
        }

        $student->studentProfile?->update([
            'income_bracket' => $scholarship->income_requirement ?: 'PHP 10,000 - 20,000',
        ]);

        $eligibilityService = app(ScholarshipEligibilityService::class);
        $requirements = $eligibilityService->documentRequirements($scholarship);
        $optionalRequirements = $eligibilityService->optionalDocumentRequirements($scholarship);
        $submittedAt = now()->subDays(4);
        $reviewStartedAt = now()->subDays(2);
        $reviewedAt = now()->subDay();

        DB::transaction(function () use (
            $student,
            $scholarship,
            $eligibilityService,
            $requirements,
            $optionalRequirements,
            $submittedAt,
            $reviewStartedAt,
            $reviewedAt,
        ): void {
            $application = ScholarshipApplication::query()->updateOrCreate([
                'scholarship_id' => $scholarship->id,
                'applicant_id' => $student->id,
            ], [
                'status' => 'submitted',
                'document_checklist' => $requirements,
                'optional_document_checklist' => $optionalRequirements,
                'review_rubric_snapshot' => $scholarship->review_rubric ?: ReviewRubric::DEFAULT,
                'notes' => 'Submitted with the required documents for portal pre-screening.',
                'review_notes' => 'Portal pre-screening completed. The applicant meets the program criteria and may continue with the provider.',
                'correction_status' => null,
                'correction_message' => null,
                'correction_response' => null,
                'decision_reason' => null,
                'awarded_amount' => null,
                'outcome_notes' => null,
                'outcome_at' => null,
                'distribution_scheduled_for' => null,
                'distribution_instructions' => null,
                'reviewed_by' => $scholarship->provider_id,
                'assigned_reviewer_id' => $scholarship->provider_id,
                'reviewed_at' => $reviewedAt,
                'submitted_at' => $submittedAt,
                'terms_accepted_at' => $submittedAt,
                'terms_version' => Terms::VERSION,
            ]);

            $application->documents()
                ->whereNotIn('document_name', $requirements)
                ->delete();

            foreach ($requirements as $requirement) {
                $preparedDocument = $this->ensurePreparedDocument($student, $requirement, $submittedAt);
                $existingApplicationDocument = $application->documents()
                    ->where('document_name', $requirement)
                    ->first();
                $applicationPath = $existingApplicationDocument?->path;

                if (! $applicationPath || ! Storage::disk('local')->exists($applicationPath)) {
                    $extension = pathinfo($preparedDocument->path, PATHINFO_EXTENSION) ?: 'svg';
                    $applicationPath = 'application-documents/'.$application->id.'/demo-'.Str::slug($requirement).'.'.$extension;
                    Storage::disk('local')->copy($preparedDocument->path, $applicationPath);
                }

                $application->documents()->updateOrCreate([
                    'document_name' => $requirement,
                ], [
                    'uploaded_by' => $student->id,
                    'original_name' => $existingApplicationDocument?->original_name ?: $preparedDocument->original_name,
                    'path' => $applicationPath,
                    'mime_type' => $existingApplicationDocument?->mime_type ?: $preparedDocument->mime_type,
                    'size' => Storage::disk('local')->size($applicationPath),
                    'status' => 'accepted',
                    'review_notes' => 'Readable and accepted for portal pre-screening.',
                    'reviewed_by' => $scholarship->provider_id,
                    'reviewed_at' => $reviewStartedAt,
                    'uploaded_at' => $submittedAt,
                    'terms_accepted_at' => $submittedAt,
                    'terms_version' => Terms::VERSION,
                ]);
            }

            $student->unsetRelation('studentDocuments');
            $student->unsetRelation('studentProfile');
            $eligibility = $eligibilityService->evaluate($scholarship, $student->fresh());
            $rubric = $scholarship->review_rubric ?: ReviewRubric::DEFAULT;
            $scores = collect($rubric)->mapWithKeys(fn (array $criterion): array => [
                $criterion['key'] => match ($criterion['key']) {
                    'eligibility_fit' => 96,
                    'academic_merit' => 92,
                    'financial_need' => 94,
                    'document_quality' => 95,
                    default => 90,
                },
            ])->all();
            $rubricResult = ReviewRubric::result($rubric, $scores);

            $application->update([
                'eligibility_score' => $eligibility['score'],
                'eligibility_breakdown' => $eligibility,
                'rubric_scores' => $scores,
                'rubric_total_score' => $rubricResult['total_score'],
                'rubric_scored_by' => $scholarship->provider_id,
                'rubric_scored_at' => $reviewedAt,
            ]);

            $application->schedules()->delete();
            $application->stageProgresses()->delete();
            $application->statusHistories()->delete();
            ApplicationStatusHistory::query()->insert([
                [
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $student->id,
                    'from_status' => null,
                    'to_status' => 'submitted',
                    'decision_reason' => null,
                    'review_notes' => 'Application submitted by applicant.',
                    'changed_at' => $submittedAt,
                    'created_at' => $submittedAt,
                    'updated_at' => $submittedAt,
                ],
            ]);

            $workflowService = app(ApplicationWorkflowService::class);
            $application = $workflowService->start($application->fresh());
            $application = $workflowService->recordStageResult(
                $application,
                'screening',
                'passed',
                $scholarship->provider,
                'Portal pre-screening passed. Continue with the provider using the formal application instructions.',
                'passed_prescreening',
            );

            app(DecisionSupportService::class)->syncApplication(
                $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']),
                'demo_completed_application_seed',
            );

            PortalNotification::query()->updateOrCreate([
                'deduplication_key' => 'demo-completed-application-'.$application->id,
            ], [
                'user_id' => $student->id,
                'type' => 'application_status',
                'title' => 'Pre-screening passed',
                'message' => 'Your Tulay Aral application passed portal pre-screening. Open the application to review the provider handoff requirements.',
                'action_url' => route('dashboard.applications.show', $application, false),
                'read_at' => null,
            ]);
        });

        $this->command?->info('Completed Tulay Aral demo application is ready for the student account.');
    }

    private function ensurePreparedDocument(User $student, string $requirement, mixed $uploadedAt): StudentDocument
    {
        $document = $student->studentDocuments()
            ->where('document_name', $requirement)
            ->first();

        if ($document && Storage::disk('local')->exists($document->path)) {
            return $document;
        }

        $path = 'student-documents/'.$student->id.'/demo-'.Str::slug($requirement).'.svg';
        Storage::disk('local')->put($path, $this->documentPreview($student, $requirement));

        return $student->studentDocuments()->updateOrCreate([
            'document_name' => $requirement,
        ], [
            'original_name' => Str::slug($requirement).'.svg',
            'path' => $path,
            'mime_type' => 'image/svg+xml',
            'size' => Storage::disk('local')->size($path),
            'uploaded_at' => $uploadedAt,
            'terms_accepted_at' => $uploadedAt,
            'terms_version' => Terms::VERSION,
        ]);
    }

    private function documentPreview(User $student, string $requirement): string
    {
        $profile = $student->studentProfile;
        $applicantName = trim(($profile?->first_name ?? 'Alex').' '.($profile?->last_name ?? 'Santos'));
        $safeRequirement = htmlspecialchars($requirement, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeApplicantName = htmlspecialchars($applicantName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">
  <rect width="1200" height="800" fill="#f8fafc"/>
  <rect width="1200" height="128" fill="#020617"/>
  <rect x="64" y="48" width="32" height="32" rx="6" fill="#facc15"/>
  <text x="116" y="74" fill="#ffffff" font-family="Georgia, serif" font-size="28" font-weight="700">Scholarship Finder</text>
  <text x="64" y="240" fill="#b45309" font-family="Arial, sans-serif" font-size="18" font-weight="700" letter-spacing="3">SUPPORTING DOCUMENT</text>
  <text x="64" y="310" fill="#020617" font-family="Georgia, serif" font-size="44" font-weight="700">{$safeRequirement}</text>
  <line x1="64" y1="352" x2="1136" y2="352" stroke="#cbd5e1" stroke-width="2"/>
  <text x="64" y="430" fill="#475569" font-family="Arial, sans-serif" font-size="21">Applicant</text>
  <text x="64" y="472" fill="#0f172a" font-family="Arial, sans-serif" font-size="28" font-weight="700">{$safeApplicantName}</text>
  <text x="64" y="560" fill="#475569" font-family="Arial, sans-serif" font-size="21">Prepared for portal pre-screening</text>
  <rect x="64" y="626" width="1072" height="92" rx="8" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
  <text x="96" y="681" fill="#475569" font-family="Arial, sans-serif" font-size="20">Demonstration record for the fictional Tulay Aral Community Foundation program.</text>
</svg>
SVG;
    }
}
