<?php

namespace Database\Seeders;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\SelectionEvaluationService;
use Database\Seeders\Support\WorkflowDemoAudit;
use Database\Seeders\Support\WorkflowDemoCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class NovemberWorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([FreshWorkflowDemoSeeder::class, ProcessWorkflowDemoEnrichmentSeeder::class, AdminProcessHandoffDemoSeeder::class]);
        $report = WorkflowDemoAudit::inspect();
        foreach (ScholarshipApplication::with(['scholarship', 'stageProgresses', 'stageEvaluations.recorder'])->get() as $application) {
            $summary = app(SelectionEvaluationService::class)->summary($application);
            if (! $summary['policy_frozen'] || $summary['state'] === 'legacy') throw new RuntimeException('Missing fixed scoring plan.');
        }
        if (Scholarship::whereNotNull('application_questions')->get()->contains(fn ($p) => $p->application_questions !== [])) throw new RuntimeException('Unexpected short application question.');
        if (Scholarship::whereHas('benefits', fn ($q) => $q->where('type', 'mentorship'))->exists()) throw new RuntimeException('Unexpected mentoring benefit.');
        $manifest = json_decode(Storage::disk('local')->get(WorkflowDemoCatalog::DIRECTORY.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['users'] = User::count();
        $manifest['programs'] = Scholarship::count();
        $manifest['accounts'] = User::with(['parentAccount', 'studentProfile', 'providerProfile'])->orderBy('id')->get()->map(fn ($u) => [
            'username' => $u->username, 'name' => $u->name, 'role' => $u->role, 'title' => $u->account_title,
            'organization' => $u->isProvider() ? $u->provider_name : null, 'owner' => $u->parentAccount?->username,
            'must_reset_password' => $u->must_reset_password,
        ])->all();
        $manifest['rounds'] = Scholarship::orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id, 'title' => $p->title, 'status' => $p->status,
            'opens_on' => $p->application_opens_at?->toDateString(), 'closes_on' => $p->deadline?->toDateString(),
            'scoring_weights' => $p->selection_scoring_policy['weights'] ?? [],
        ])->all();
        $manifest['applications'] = ScholarshipApplication::with(['applicant', 'scholarship', 'monitoringCases', 'benefitReleaseRecords'])->orderBy('id')->get()->map(fn ($a) => [
            'id' => $a->id, 'username' => $a->applicant->username, 'name' => $a->applicant->name, 'program' => $a->scholarship->title,
            'program_id' => $a->scholarship_id, 'stage' => $a->workflow_stage, 'outcome' => $a->final_outcome,
            'agreement' => $a->student_response_status, 'check_ins' => $a->monitoringCases->pluck('recipient_monitoring_cycle_id')->all(),
            'release_record_ids' => $a->benefitReleaseRecords->pluck('id')->all(),
        ])->all();
        $manifest['audit'] = $report;
        Storage::disk('local')->put(WorkflowDemoCatalog::DIRECTORY.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
