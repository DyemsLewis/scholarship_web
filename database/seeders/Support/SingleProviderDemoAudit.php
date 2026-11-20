<?php

namespace Database\Seeders\Support;

use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientSupportSettlementService;
use App\Services\ScholarshipEligibilityService;
use App\Services\SelectionEvaluationService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SingleProviderDemoAudit
{
    public static function inspect(string $catalog = SingleProviderDemoCatalog::class): array
    {
        $check = static function (bool $condition, string $message): void {
            if (!$condition) throw new RuntimeException($message);
        };
        $check(User::find(1)?->username === $catalog::ROOT, 'The super admin must start at account ID 1.');
        $owners = User::where('role', 'provider')->whereNull('parent_account_id')->get();
        $check($owners->count() === 1 && $owners->sole()->username === $catalog::PROVIDER, 'Expected exactly one new provider organization.');
        $programs = $catalog::programs();
        $check(Scholarship::count() === count($programs) && Scholarship::where('provider_id', '!=', $owners->sole()->id)->doesntExist(), 'Every scholarship must belong to the one provider.');
        $check(User::where('role', 'applicant')->count() === 6, 'Expected four reusable applicants and two profile workflow accounts.');
        $check(User::where('email', 'not like', '%@portal-demo.test')->doesntExist(), 'Unexpected non-fictional email domain.');
        $check(ScholarshipApplication::count() === count($catalog::scenarios()), 'An expected application scenario is missing.');
        $check(ScholarshipApplication::whereIn('final_outcome', ['not_selected', 'waitlisted'])->doesntExist(), 'Negative final outcomes must be left to the tester.');
        $check(ScholarshipApplication::whereIn('status', ['rejected', 'exam_failed', 'interview_failed', 'withdrawn', 'benefits_terminated'])->doesntExist(), 'Unexpected failed workflow state.');
        $avatar = hash_file('sha256', public_path('images/demo/default-applicant-avatar.jpg'));
        foreach (User::where('role', 'applicant')->with('studentProfile')->get() as $user) {
            $check(str_starts_with($user->studentProfile->profile_photo_path, $catalog::DIRECTORY.'/'), 'Applicant assets must use the new private namespace.');
            $check(hash('sha256', Storage::disk('local')->get($user->studentProfile->profile_photo_path)) === $avatar, 'Applicants must use neutral profile images.');
            if (in_array($user->username, array_map(fn ($name) => 'learner.'.$name, $catalog::LEARNERS), true)) {
                $check($user->applicantProfileReadiness()['verified'], 'A shared applicant profile is not verified.');
                $check(ScholarshipApplication::where('applicant_id', $user->id)->count() >= 5, 'A shared applicant has too few process examples.');
            }
        }
        $states = [];
        foreach ($catalog::scenarios() as [$round, $scenario, $learner, $next]) {
            $user = User::where('username', 'learner.'.$learner)->sole();
            $title = $programs[$round][0];
            $application = ScholarshipApplication::with(['documents', 'stageProgresses', 'stageEvaluations.recorder', 'scholarship'])
                ->where('applicant_id', $user->id)->whereHas('scholarship', fn ($q) => $q->where('title', $title))->sole();
            $score = app(SelectionEvaluationService::class)->summary($application);
            $check($score['policy_frozen'] && $score['state'] !== 'legacy', 'Every application must have a fixed DSS policy.');
            foreach ($application->documents as $document) $check(Storage::disk('local')->exists($document->path), 'Application proof is missing.');
            $states[] = [$user->username, $title, $application->id, $application->workflow_stage, $application->final_outcome, $application->student_response_status, $next];
            if ($round === 'decision') {
                $check($application->final_outcome === null && $application->workflow_stage === 'decision', 'Final selection must remain a tester decision.');
                $check($score['ready_for_comparison'] === ($learner !== $catalog::LEARNERS[3]), 'The DSS ready/missing-evaluation cases are inconsistent.');
            }
            if ($scenario === 'support.decision' || $scenario === 'onetime.complete') {
                $check(app(RecipientSupportSettlementService::class)->summary($application)['can_complete'], 'A completion example still has unsettled benefits.');
            }
        }
        $check(RecipientMonitoringCase::where('status', 'ready_to_close')->exists(), 'No check-in is ready for closure.');
        $open = Scholarship::where('title', $programs['intake'][0])->sole();
        $check($open->isAcceptingApplications(), 'The fresh-application scholarship must be open.');
        $check(app(ScholarshipEligibilityService::class)->evaluate($open, User::where('username', 'learner.'.$catalog::LEARNERS[0])->sole())['is_eligible'], 'The main applicant cannot start a new application.');
        $oneTime = Scholarship::where('title', $programs['one_time'][0])->sole();
        $check($oneTime->monitoring_policy === 'none' && !$oneTime->monitoringCycles()->exists(), 'One-time support must not require recurring check-ins.');
        $timeline = WorkflowDemoTimelineAudit::assertValid();
        $accounts = User::orderBy('id')->get()->map(fn ($user) => [$user->id, $user->username, $user->role, $user->parent_account_id, $user->assigned_program_ids])->all();
        return ['catalog' => $catalog::DIRECTORY, 'users' => count($accounts), 'provider_organizations' => 1,
            'applicants' => 6, 'programs' => count($programs), 'applications' => count($states), 'neutral_avatars' => true, 'timeline' => $timeline,
            'scenario_states' => $states, 'catalog_signature' => hash('sha256', json_encode([$accounts, $states], JSON_THROW_ON_ERROR))];
    }
}
