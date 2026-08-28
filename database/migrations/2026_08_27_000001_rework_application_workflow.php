<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->unsignedSmallInteger('workflow_version')->default(2)->after('status');
            $table->string('application_state', 40)->default('submitted')->after('workflow_version')->index();
            $table->string('workflow_stage', 40)->nullable()->after('application_state')->index();
            $table->string('final_outcome', 40)->nullable()->after('workflow_stage')->index();
            $table->json('submission_snapshot')->nullable()->after('final_outcome');
        });

        Schema::create('application_stage_progresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->string('stage_key', 40);
            $table->unsignedSmallInteger('position');
            $table->string('status', 30)->default('pending');
            $table->string('result', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['scholarship_application_id', 'stage_key'], 'application_stage_unique');
            $table->index(['scholarship_application_id', 'position'], 'application_stage_position');
            $table->index(['stage_key', 'status'], 'application_stage_queue');
        });

        $normalizeStages = static function (mixed $value): array {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            $rawStages = is_array($decoded) ? $decoded : [];
            $middle = collect($rawStages)
                ->map(fn (mixed $stage): string => strtolower(trim((string) $stage)))
                ->map(fn (string $stage): string => $stage === 'distribution' ? 'decision' : $stage)
                ->filter(fn (string $stage): bool => in_array($stage, ['formal_application', 'exam', 'interview'], true))
                ->unique()
                ->values();

            // Existing programs keep their configured order; the provider handoff is added after it.
            if (! $middle->contains('formal_application')) {
                $middle->push('formal_application');
            }

            return ['screening', ...$middle->all(), 'decision'];
        };

        $programStages = [];
        DB::table('scholarships')
            ->select(['id', 'selection_stages'])
            ->orderBy('id')
            ->get()
            ->each(function (object $scholarship) use (&$programStages, $normalizeStages): void {
                $stages = $normalizeStages($scholarship->selection_stages);
                $programStages[(int) $scholarship->id] = $stages;

                DB::table('scholarships')
                    ->where('id', $scholarship->id)
                    ->update(['selection_stages' => json_encode($stages)]);
            });

        $closedStatuses = [
            'rejected',
            'exam_failed',
            'interview_failed',
            'not_awarded',
            'awarded',
            'distribution_scheduled',
            'disbursed',
            'renewed',
            'withdrawn',
        ];

        DB::table('scholarship_applications')
            ->select(['id', 'scholarship_id', 'status', 'submitted_at', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get()
            ->each(function (object $application) use ($closedStatuses, $programStages): void {
                $status = (string) ($application->status ?: 'submitted');
                $stages = $programStages[(int) $application->scholarship_id]
                    ?? ['screening', 'formal_application', 'decision'];
                $inferredStage = match (true) {
                    in_array($status, ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed', 'exam_failed'], true) => 'exam',
                    in_array($status, ['interview', 'interview_failed'], true) => 'interview',
                    $status === 'approved' => 'formal_application',
                    in_array($status, ['waitlisted', 'awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'not_awarded'], true) => 'decision',
                    default => 'screening',
                };

                if (! in_array($inferredStage, $stages, true)) {
                    $inferredStage = in_array('formal_application', $stages, true)
                        ? 'formal_application'
                        : 'screening';
                }

                $isClosed = in_array($status, $closedStatuses, true);
                $workflowStage = $isClosed && $status !== 'waitlisted' ? 'complete' : $inferredStage;
                $applicationState = match (true) {
                    $status === 'withdrawn' => 'withdrawn',
                    $isClosed && $status !== 'waitlisted' => 'closed',
                    $status === 'waitlisted' => 'awaiting_decision',
                    in_array($status, ['submitted'], true) => 'submitted',
                    in_array($status, ['under_review', 'qualified', 'shortlisted'], true) => 'under_review',
                    default => 'in_provider_process',
                };
                $finalOutcome = match (true) {
                    in_array($status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true) => 'selected',
                    $status === 'waitlisted' => 'waitlisted',
                    in_array($status, ['not_awarded', 'exam_failed', 'interview_failed'], true) => 'not_selected',
                    default => null,
                };

                DB::table('scholarship_applications')
                    ->where('id', $application->id)
                    ->update([
                        'workflow_version' => 2,
                        'application_state' => $applicationState,
                        'workflow_stage' => $workflowStage,
                        'final_outcome' => $finalOutcome,
                    ]);

                $currentIndex = array_search($inferredStage, $stages, true);
                $currentIndex = $currentIndex === false ? 0 : $currentIndex;
                $failedStage = match ($status) {
                    'rejected' => 'screening',
                    'exam_failed' => 'exam',
                    'interview_failed' => 'interview',
                    'not_awarded' => 'decision',
                    default => null,
                };

                foreach ($stages as $position => $stage) {
                    $stageStatus = 'pending';
                    $result = null;

                    if ($status === 'withdrawn') {
                        $stageStatus = 'skipped';
                    } elseif ($failedStage !== null) {
                        $failedIndex = array_search($failedStage, $stages, true);
                        $failedIndex = $failedIndex === false ? $currentIndex : $failedIndex;
                        $stageStatus = $position < $failedIndex
                            ? 'passed'
                            : ($position === $failedIndex ? 'not_passed' : 'skipped');
                        $result = $position < $failedIndex
                            ? 'passed'
                            : ($position === $failedIndex ? 'not_passed' : null);
                    } elseif ($finalOutcome === 'selected') {
                        $stageStatus = 'passed';
                        $result = $stage === 'decision' ? 'selected' : 'passed';
                    } elseif ($finalOutcome === 'waitlisted') {
                        $stageStatus = $stage === 'decision' ? 'current' : 'passed';
                        $result = $stage === 'decision' ? 'waitlisted' : 'passed';
                    } elseif ($position < $currentIndex) {
                        $stageStatus = 'passed';
                        $result = 'passed';
                    } elseif ($position === $currentIndex) {
                        $stageStatus = 'current';
                    }

                    DB::table('application_stage_progresses')->insert([
                        'scholarship_application_id' => $application->id,
                        'stage_key' => $stage,
                        'position' => $position,
                        'status' => $stageStatus,
                        'result' => $result,
                        'started_at' => in_array($stageStatus, ['current', 'passed', 'not_passed'], true)
                            ? ($application->submitted_at ?: $application->created_at)
                            : null,
                        'completed_at' => in_array($stageStatus, ['passed', 'not_passed'], true)
                            ? ($application->updated_at ?: now())
                            : null,
                        'created_at' => $application->created_at ?: now(),
                        'updated_at' => $application->updated_at ?: now(),
                    ]);
                }
            });

        DB::table('scholarship_events')->where('type', 'distribution')->delete();
        DB::table('application_schedules')->where('type', 'distribution')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('application_stage_progresses');

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'workflow_version',
                'application_state',
                'workflow_stage',
                'final_outcome',
                'submission_snapshot',
            ]);
        });
    }
};
