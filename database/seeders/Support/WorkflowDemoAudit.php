<?php

namespace Database\Seeders\Support;

use App\Models\ApplicantVerificationDocument;
use App\Models\ProviderServicePurchase;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class WorkflowDemoAudit
{
    public static function inspect(): array
    {
        $assert = function (bool $condition, string $message): void {
            if (! $condition) throw new RuntimeException($message);
        };
        $assert(User::findOrFail(1)->username === 'superadmin', 'The first account is not the super admin.');
        $assert(User::whereIn('username', ['aralbridge', 'futureweave', 'miracelesteandrada'])->doesntExist(), 'Old demo identities are present.');
        $assert(User::count() === 37 + count(WorkflowDemoCatalog::applicants()), 'The role/account catalog is incomplete.');
        $assert(Scholarship::count() === 7 && Scholarship::distinct()->count('image_path') === 7, 'The programs or distinct logos are incomplete.');
        $assert(ScholarshipApplication::whereIn('final_outcome', ['not_selected', 'waitlisted'])->doesntExist(), 'Unexpected final outcome in the demo.');
        $assert(ScholarshipApplication::whereIn('status', ['rejected', 'exam_failed', 'interview_failed', 'withdrawn', 'benefits_terminated'])->doesntExist(), 'An unwanted negative state was seeded.');
        $states = [];
        $avatarHash = hash_file('sha256', public_path('images/demo/default-applicant-avatar.jpg'));
        foreach (WorkflowDemoCatalog::applicants() as $record) {
            $user = User::with('studentProfile')->where('username', $record['username'])->sole();
            $profile = $user->studentProfile;
            $assert(hash('sha256', Storage::disk('local')->get($profile->profile_photo_path)) === $avatarHash, 'An applicant is not using the neutral avatar.');
            $readiness = $user->applicantProfileReadiness();
            if (! in_array($record['scenario'], ['profile', 'verification'], true)) {
                $assert($readiness['complete'] && $readiness['verified'], 'Profile cannot proceed: '.$user->username);
                $assert(ApplicantVerificationDocument::missingRequiredTypesFor($user) === [], 'Profile evidence is missing: '.$user->username);
            }
            $application = ScholarshipApplication::where('applicant_id', $user->id)->first();
            if ($application) {
                foreach ($application->documents as $document) {
                    $assert(Storage::disk('local')->exists($document->path), 'Submitted application proof is missing.');
                    $assert($document->mime_type === 'application/pdf', 'Unexpected proof format.');
                }
            }
            $states[$record['username']] = [$user->id, $application?->id, $application?->workflow_stage, $application?->final_outcome, $application?->student_response_status];
        }
        $expectations = ['screening' => 'screening', 'formal' => 'formal_application', 'exam.assign' => 'exam', 'exam.confirm' => 'exam',
            'exam.complete' => 'exam', 'exam.result' => 'exam', 'interview.assign' => 'interview', 'interview.confirm' => 'interview',
            'interview.result' => 'interview', 'decision' => 'decision'];
        foreach ($expectations as $key => $stage) {
            $state = $states['learner.'.$key];
            $assert($state[2] === $stage && $state[3] === null, 'The tester decision has already been recorded: '.$key);
        }
        foreach (['exam.result', 'interview.result'] as $key) {
            $application = ScholarshipApplication::findOrFail($states['learner.'.$key][1]);
            $assignment = $application->stageAssignments()->whereHas('session', fn ($q) => $q->where('stage_key', $application->workflow_stage))->sole();
            $assert($assignment->session->status === 'completed' && $assignment->attendance?->status === 'present', 'Result prerequisites are incomplete.');
            $assert($assignment->session->evidence()->where('is_current', true)->exists(), 'Provider stage proof is missing.');
        }
        $ready = User::where('username', 'learner.apply')->sole();
        $program = Scholarship::where('title', 'Marilag Continuing Learners Scholarship')->sole();
        $match = app(ScholarshipEligibilityService::class)->evaluate($program, $ready);
        $assert($match['is_eligible'] && count($match['preferred_mismatches']) === 1, 'The ready-to-apply preference example is not usable.');
        $assert(RecipientMonitoringCase::where('status', 'ready_to_close')->count() === 1, 'No case is ready for a closure decision.');
        $assert(ProviderServicePurchase::where('status', 'failed')->doesntExist(), 'A failed service purchase was seeded.');
        $oneTime = Scholarship::where('title', 'Marilag School Start Award')->sole();
        $assert($oneTime->monitoring_policy === 'none' && ! $oneTime->monitoringCycles()->exists(), 'One-time support unexpectedly requires recurring monitoring.');
        $accounts = User::orderBy('id')->get()->map(fn ($user) => [$user->id, $user->username, $user->role, $user->parent_account_id, $user->assigned_program_ids])->all();

        return [
            'catalog' => WorkflowDemoCatalog::DIRECTORY,
            'users' => User::count(), 'applicants' => User::where('role', 'applicant')->count(),
            'programs' => Scholarship::count(), 'applications' => ScholarshipApplication::count(),
            'unwanted_outcomes' => 0, 'neutral_avatars' => true, 'evidence_files_checked' => true,
            'catalog_signature' => hash('sha256', json_encode([$accounts, $states], JSON_THROW_ON_ERROR)),
            'scenario_states' => $states,
        ];
    }
}
