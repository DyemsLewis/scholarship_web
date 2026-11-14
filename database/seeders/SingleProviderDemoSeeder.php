<?php

namespace Database\Seeders;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\ApplicationStageSession;
use App\Models\PortalNotification;
use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\RecipientBenefitEntitlementService;
use App\Services\RecipientBenefitPayoutService;
use App\Services\SelectionEvaluationService;
use Database\Seeders\Support\SingleProviderDemoAudit;
use Database\Seeders\Support\SingleProviderDemoCatalog as Catalog;
use Database\Seeders\Support\WorkflowDemoTimeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class SingleProviderDemoSeeder extends FreshWorkflowDemoSeeder
{
    protected string $assetDirectory = Catalog::DIRECTORY;
    protected string $platformPrefix = Catalog::ADMIN;
    private array $shiftedSessions = [];
    private int $renewalProgramId = 0;
    private int $futureReleaseSlot = 0;

    public function run(): void
    {
        if (User::exists()) throw new RuntimeException('The single-provider seed requires an empty candidate database.');
        if (app()->environment('production') && getenv('SINGLE_PROVIDER_DEMO_CONFIRM') !== Catalog::CONFIRMATION) {
            throw new RuntimeException('A verified hosting backup and explicit reset confirmation are required.');
        }
        $this->timeline = new WorkflowDemoTimeline;
        $mailer = config('mail.default');
        config()->set('mail.default', 'array');
        try {
            DB::transaction(fn () => $this->prepare());
        } finally {
            config()->set('mail.default', $mailer);
        }
        $this->command?->info('Single-provider demo prepared with reusable applicant accounts.');
    }

    private function prepare(): void
    {
        $root = $this->account(Catalog::ADMIN.'.root', 'admin');
        $root->adminProfile()->create(['first_name' => 'Platform', 'last_name' => 'Administrator', 'display_name' => 'Platform Administrator', 'contact_number' => '09000001000']);
        $this->staff[$root->username] = $root;
        foreach (AdminRoleCatalog::definitions() as $key => $definition) {
            if ($key === AdminRoleCatalog::SUPER_ADMIN) continue;
            $suffix = ['account_manager' => 'accounts', 'review_officer' => 'reviews', 'support_officer' => 'support', 'billing_officer' => 'billing', 'finance_officer' => 'finance', 'records_officer' => 'records', 'portal_manager' => 'manager'][$key];
            $user = $this->account(Catalog::ADMIN.'.'.$suffix, 'admin', $root, $definition['title'], $definition['permissions']);
            $user->adminProfile()->create(['first_name' => 'Platform', 'last_name' => Str::headline($suffix), 'display_name' => $definition['title'], 'contact_number' => '09000001001']);
            $this->staff[$user->username] = $user;
        }
        $provider = $this->provider(Catalog::PROVIDER, 'Orivanta Learning Foundation', 'OLF', $this->staff[Catalog::ADMIN.'.reviews']);
        $provider->providerProfile->update(['first_name' => 'Orivanta', 'last_name' => 'Representative', 'provider_description' => 'Education support through learning allowances, school essentials, and accountable student support.', 'legal_name' => 'Orivanta Learning Foundation']);
        $provider->refresh();
        $definitions = [
            'intake' => ['Orivanta Open Learning Grant', 'OLG', 'intake', 'published'],
            'screening' => ['Orivanta Campus Access Scholarship', 'OCA', 'screening', 'published'],
            'exam' => ['Orivanta Applied Science Scholarship', 'OAS', 'exam', 'published'],
            'interview' => ['Orivanta Leadership Scholarship', 'OLS', 'interview', 'published'],
            'decision' => ['Orivanta Academic Merit Scholarship', 'OAM', 'decision', 'published'],
            'support' => ['Orivanta Continuing Learners Grant', 'OCL', 'support', 'published'],
            'renewal' => ['Orivanta Term Assistance Grant', 'OTA', 'support', 'published'],
            'one_time' => ['Orivanta School Essentials Award', 'OSE', 'one_time', 'published'],
            'draft' => ['Orivanta Digital Learning Grant', 'ODL', 'draft', 'draft'],
            'review' => ['Orivanta College Pathways Award', 'OCP', 'review', 'pending_review'],
        ];
        foreach ($definitions as $key => [$title, $mark, $round, $status]) {
            $this->programs[$key] = $this->program($provider, $round, $title, $mark, count($this->programs), $status);
        }
        $this->renewalProgramId = $this->programs['renewal']->id;
        $this->team($provider, $this->programs['draft']->id);
        $learners = [];
        foreach ([...Catalog::LEARNERS, 'profile', 'verification'] as $index => $name) {
            $learners[$name] = $this->applicant(['username' => 'learner.'.$name,
                'first_name' => 'Applicant', 'last_name' => ucfirst($name), 'scenario' => in_array($name, ['profile', 'verification'], true) ? $name : 'shared.'.$name,
                'next_action' => 'Open your profile and applications to follow the next available action', 'actor' => 'the responsible applicant or staff workspace'], $index + 1, $this->staff[Catalog::ADMIN.'.reviews']);
        }
        foreach (Catalog::scenarios() as [$round, $scenario, $learner]) {
            $baseRound = $round === 'renewal' ? 'support' : $round;
            $original = $this->programs[$baseRound];
            $this->programs[$baseRound] = $this->programs[$round];
            try {
                $application = $this->application($learners[$learner], $baseRound, $scenario);
            } finally {
                $this->programs[$baseRound] = $original;
            }
            $this->applications[$round.'.'.$learner] = $application;
        }
        foreach (['aurora' => 97, 'brio' => 90, 'cielo' => 82] as $name => $score) {
            $application = $this->applications['decision.'.$name];
            foreach (['exam', 'interview'] as $stage) {
                $progress = $application->stageProgresses()->where('stage_key', $stage)->sole();
                app(SelectionEvaluationService::class)->record($application->fresh(), $this->staff[Catalog::PROVIDER.'.selection'], $stage,
                    $score, 100, $progress->evaluation_revision, 1, 'Evaluation checked against the completed activity record.');
            }
        }
        $this->applications['decision.dune']->stageEvaluations()->where('stage_key', 'interview')->delete();
        $this->prepareSupport();
        $this->release($this->applications['renewal.aurora'], true);
        $this->release($this->applications['renewal.dune'], false, RecipientBenefitPayoutService::WALLET, 'submitted');
        foreach (['brio' => false, 'cielo' => true, 'dune' => true] as $name => $released) $this->release($this->applications['one_time.'.$name], $released);
        $this->confirmReceipt($this->applications['one_time.dune']);
        $this->services($provider);
        SupportReport::create(['applicant_id' => $learners['aurora']->id, 'assigned_role' => 'provider', 'provider_id' => $provider->id,
            'scholarship_id' => $this->programs['screening']->id, 'category' => 'application', 'subject' => 'Clarify the original-document requirements',
            'description' => 'Which originals should I bring if I pass pre-screening?', 'status' => 'open', 'provider_status' => 'open', 'admin_status' => 'not_required']);
        SupportReport::create(['applicant_id' => $learners['brio']->id, 'assigned_role' => 'admin', 'category' => 'technical',
            'subject' => 'Explain my notification settings', 'description' => 'Please explain how application notifications appear in my account.',
            'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
        $this->scopeTeam($provider);
        foreach ($this->applications as $application) app(DecisionSupportService::class)->syncApplication($application->fresh(), Catalog::DIRECTORY);
        PortalNotification::where('type', 'demo_next_action')->update(['title' => 'Your next steps', 'message' => 'Open Applications to see your different scholarship records and the next action for each.']);
        SingleProviderDemoAudit::inspect();
        $this->writeManifest();
    }

    protected function program(User $provider, string $round, string $title, string $mark, int $variant, string $status): Scholarship
    {
        $program = parent::program($provider, $round, $title, $mark, $variant, $status);
        $oneTime = $round === 'one_time';
        $program->update(['exclude_current_scholarship_recipients' => false, 'selection_scoring_policy' => null,
            'slots_available' => $round === 'decision' ? 2 : 20, 'application_limit' => 80,
            'recipient_agreement' => ['commitment_type' => $oneTime ? 'none' : 'reporting',
                'release_conditions' => $oneTime ? 'Accept the agreement and present your identity before collection.' : 'Accept the agreement before the first release. Later releases require the due original-document checks.',
                'responsibilities' => 'Remain enrolled, attend scheduled original-document reviews, and confirm benefits actually received.',
                'required_evidence' => 'Current grades and certificate of enrollment.', 'duration' => $oneTime ? 'One distribution' : 'The stated support period',
                'noncompliance_consequence' => 'The provider reviews the circumstances before a warning, support pause, or termination decision.',
                'exit_or_exception_process' => 'Contact the scholarship office to explain a delay, request an exception, or withdraw voluntarily.'],
        ]);
        return $program->fresh(['benefits', 'provider.providerProfile']);
    }

    protected function session(ScholarshipApplication $application, string $round, string $stage, string $scenario): ApplicationStageSession
    {
        $session = parent::session($application, $round, $stage, $scenario);
        if ($application->scholarship_id === $this->renewalProgramId && !isset($this->shiftedSessions[$session->id])) {
            $session->update(['starts_at' => $session->starts_at->copy()->addHours(2), 'ends_at' => $session->ends_at->copy()->addHours(2), 'completed_at' => $session->completed_at?->copy()->addHours(2)]);
            foreach ($session->evidence as $proof) $proof->update(['uploaded_at' => $proof->uploaded_at->copy()->addHours(2)]);
            $this->shiftedSessions[$session->id] = true;
        }
        foreach ($session->assignments()->with('attendance')->get() as $assignment) {
            if ($assignment->attendance?->recorded_at) $assignment->attendance->update(['recorded_at' => $session->ends_at->copy()->addMinutes(15)]);
        }
        return $session->fresh();
    }

    private function prepareSupport(): void
    {
        $actor = $this->staff[Catalog::PROVIDER.'.monitoring'];
        foreach (['support', 'renewal'] as $round) {
            $program = $this->programs[$round];
            $plan = RecipientMonitoringPlan::create(['scholarship_id' => $program->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                'frequency' => 'semester', 'starts_on' => $program->support_starts_at, 'ends_on' => $program->support_ends_at,
                'grace_period_days' => 7, 'allow_exception_requests' => true, 'status' => 'active', 'version' => 1, 'activated_at' => $this->timeline->at(-28),
                'instructions' => 'Bring original grades and enrollment proof to the appointment. The provider records the document review; no applicant uploads substitute for attendance.']);
            foreach (['academic_progress' => 'Current academic record', 'enrollment' => 'Current enrollment certificate'] as $type => $title) {
                $plan->requirements()->create(['type' => $type, 'title' => $title, 'evidence_description' => $title, 'required' => true,
                    'requires_file' => false, 'requires_original_verification' => true, 'minimum_grade' => $type === 'academic_progress' ? 85 : null,
                    'grading_scale' => $type === 'academic_progress' ? 'percentage' : null, 'sort_order' => $type === 'academic_progress' ? 1 : 2, 'active' => true]);
            }
            if ($round === 'support') {
                $this->applications['monitoring.review'] = $this->applications['support.cielo'];
                $this->applications['monitoring.close'] = $this->applications['support.dune'];
                $this->checkIn($plan, $actor, -3, ['monitoring.review', 'monitoring.close']);
            } else {
                $this->applications['monitoring.appointment'] = $this->applications['renewal.cielo'];
                $this->applications['support.decision'] = $this->applications['renewal.brio'];
                $this->checkIn($plan, $actor, 5, ['monitoring.appointment']);
                $this->checkIn($plan, $actor, -4, ['support.decision']);
            }
        }
        foreach (['monitoring.review', 'monitoring.close', 'monitoring.appointment', 'support.decision'] as $alias) unset($this->applications[$alias]);
        foreach (['in_person', 'other'] as $method) {
            $this->release($this->applications['renewal.brio'], true, $method);
            $this->confirmReceipt($this->applications['renewal.brio']);
        }
    }

    protected function release(ScholarshipApplication $application, bool $released, string $method = 'in_person', ?string $payoutStatus = null): void
    {
        parent::release($application, $released, $method, $payoutStatus);
        $record = $application->benefitReleaseRecords()->latest('id')->firstOrFail();
        if (!$released) {
            $record->release->update(['release_at' => $this->timeline->at(2, 9)->addMinutes(60 * $this->futureReleaseSlot++)]);
        } elseif ($method === 'other') {
            $at = $this->timeline->at(-1, 18);
            $record->release->update(['release_at' => $at->subHour()]);
            $record->update(['released_at' => $at, 'recorded_at' => $at]);
        }
    }

    private function confirmReceipt(ScholarshipApplication $application): void
    {
        $record = $application->benefitReleaseRecords()->latest('id')->firstOrFail();
        $response = $record->receiptResponse()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
            'response_type' => 'confirmed', 'status' => 'confirmed', 'received_on' => $record->released_at->toDateString(),
            'recipient_note' => 'I received the stated benefit.', 'responded_at' => $record->released_at->copy()->addMinutes(30)]);
        app(RecipientBenefitEntitlementService::class)->syncRecipientResponse($response);
    }

    private function scopeTeam(User $provider): void
    {
        foreach (['reviewer' => ['screening', 'exam', 'interview', 'decision'], 'selection' => ['screening', 'exam', 'interview', 'decision'],
            'decisions' => ['screening', 'exam', 'interview', 'decision'], 'monitoring' => ['support', 'renewal'],
            'recipients' => ['support', 'renewal', 'one_time'], 'releases' => ['support', 'renewal', 'one_time']] as $suffix => $rounds) {
            User::where('username', $provider->username.'.'.$suffix)->sole()->update(['assigned_program_ids' => array_map(fn ($round) => $this->programs[$round]->id, $rounds)]);
        }
    }

    private function writeManifest(): void
    {
        $manifest = ['catalog' => Catalog::DIRECTORY, 'anchor_date' => $this->timeline->anchor->toDateString(), 'timezone' => WorkflowDemoTimeline::TIMEZONE,
            'accounts' => User::with('parentAccount')->orderBy('id')->get()->map(fn ($user) => ['username' => $user->username, 'name' => $user->name,
                'role' => $user->role, 'title' => $user->account_title, 'owner' => $user->parentAccount?->username, 'must_reset_password' => $user->must_reset_password])->all(),
            'rounds' => collect($this->programs)->map(fn ($program) => ['id' => $program->id, 'title' => $program->title, 'status' => $program->status,
                'opens_on' => $program->application_opens_at->toDateString(), 'closes_on' => $program->deadline->toDateString()])->all(),
            'scenarios' => array_map(function ($row) {
                [$round, $scenario, $learner, $next] = $row;
                $application = $this->applications[$round.'.'.$learner]->fresh();
                return ['username' => 'learner.'.$learner, 'program' => $application->scholarship->title, 'program_id' => $application->scholarship_id,
                    'application_id' => $application->id, 'stage' => $application->workflow_stage, 'scenario' => $scenario, 'next_action' => $next];
            }, Catalog::scenarios()), 'audit' => SingleProviderDemoAudit::inspect()];
        Storage::disk('local')->put(Catalog::DIRECTORY.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
