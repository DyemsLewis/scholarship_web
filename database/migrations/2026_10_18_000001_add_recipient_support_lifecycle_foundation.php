<?php

use App\Domain\Recipients\RecipientSupportCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->string('support_mode')->default(RecipientSupportCatalog::SUPPORT_ONE_TIME)->index();
            $table->string('monitoring_policy')->default(RecipientSupportCatalog::MONITORING_NONE)->index();
        });

        Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->foreignId('closed_by')->nullable();
            $table->foreign('closed_by', 'rm_cycle_closed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable();
            $table->foreign('reopened_by', 'rm_cycle_reopened_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
        });

        Schema::create('recipient_monitoring_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_cycle_id');
            $table->foreign('recipient_monitoring_cycle_id', 'rm_case_cycle_fk')
                ->references('id')
                ->on('recipient_monitoring_cycles')
                ->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rm_case_application_fk')
                ->references('id')
                ->on('scholarship_applications')
                ->cascadeOnDelete();
            $table->foreignId('applicant_id');
            $table->foreign('applicant_id', 'rm_case_applicant_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->string('status')->default(RecipientSupportCatalog::CASE_AWAITING_AGREEMENT)->index();
            $table->string('outcome')->nullable()->index();
            $table->text('status_reason')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('ready_to_close_at')->nullable();
            $table->foreignId('closed_by')->nullable();
            $table->foreign('closed_by', 'rm_case_closed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable();
            $table->foreign('reopened_by', 'rm_case_reopened_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recipient_monitoring_cycle_id', 'scholarship_application_id'],
                'rm_case_cycle_application_unique'
            );
            $table->index(['scholarship_application_id', 'status'], 'rm_case_application_status_idx');
            $table->index(['recipient_monitoring_cycle_id', 'status'], 'rm_case_cycle_status_idx');
        });

        $this->backfillProgramPolicies();
        $this->backfillMonitoringCases();
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_cases');

        Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->dropForeign('rm_cycle_closed_by_fk');
            $table->dropForeign('rm_cycle_reopened_by_fk');
            $table->dropColumn(['closed_by', 'closed_at', 'reopened_by', 'reopened_at']);
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn(['support_mode', 'monitoring_policy']);
        });
    }

    private function backfillProgramPolicies(): void
    {
        DB::table('scholarships')
            ->select(['id', 'award_amount'])
            ->orderBy('id')
            ->each(function (object $program): void {
                $frequencies = DB::table('scholarship_benefits')
                    ->where('scholarship_id', $program->id)
                    ->pluck('frequency');
                $supportMode = RecipientSupportCatalog::supportModeFromFrequencies($frequencies);
                $hasMonitoring = DB::table('recipient_monitoring_plans')
                    ->where('scholarship_id', $program->id)
                    ->exists()
                    || DB::table('recipient_monitoring_cycles')
                        ->where('scholarship_id', $program->id)
                        ->exists();

                DB::table('scholarships')
                    ->where('id', $program->id)
                    ->update([
                        'support_mode' => $supportMode,
                        'monitoring_policy' => $hasMonitoring
                            ? RecipientSupportCatalog::MONITORING_RECURRING
                            : RecipientSupportCatalog::defaultMonitoringPolicy($supportMode),
                    ]);
            });
    }

    private function backfillMonitoringCases(): void
    {
        $now = now();

        DB::table('recipient_monitoring_cycles')
            ->select(['id', 'scholarship_id', 'status', 'published_at', 'created_at'])
            ->orderBy('id')
            ->each(function (object $cycle) use ($now): void {
                $applications = DB::table('scholarship_applications')
                    ->select(['id', 'applicant_id', 'student_response_status'])
                    ->where('scholarship_id', $cycle->scholarship_id)
                    ->where(function ($query): void {
                        $query->where('final_outcome', 'selected')
                            ->orWhereIn('status', ['awarded', 'distribution_scheduled', 'disbursed', 'renewed']);
                    })
                    ->where('status', '!=', 'benefits_terminated')
                    ->where(function ($query): void {
                        $query->whereNull('student_response_status')
                            ->orWhere('student_response_status', '!=', 'declined');
                    })
                    ->orderBy('id')
                    ->get();

                foreach ($applications as $application) {
                    $status = $this->initialCaseStatus($cycle, $application);

                    DB::table('recipient_monitoring_cases')->updateOrInsert(
                        [
                            'recipient_monitoring_cycle_id' => $cycle->id,
                            'scholarship_application_id' => $application->id,
                        ],
                        [
                            'applicant_id' => $application->applicant_id,
                            'status' => $status,
                            'opened_at' => $cycle->published_at ?? $cycle->created_at,
                            'closed_at' => $status === RecipientSupportCatalog::CASE_CLOSED
                                ? ($cycle->published_at ?? $cycle->created_at)
                                : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            });
    }

    private function initialCaseStatus(object $cycle, object $application): string
    {
        if ($cycle->status === 'closed') {
            return RecipientSupportCatalog::CASE_CLOSED;
        }

        if ($application->student_response_status !== 'accepted') {
            return RecipientSupportCatalog::CASE_AWAITING_AGREEMENT;
        }

        $pendingAdjustment = DB::table('recipient_monitoring_adjustment_requests')
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->where('scholarship_application_id', $application->id)
            ->where('status', 'pending')
            ->exists();
        if ($pendingAdjustment) {
            return RecipientSupportCatalog::CASE_EXTENSION_PENDING;
        }

        $openFollowUp = DB::table('recipient_monitoring_interventions')
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->where('scholarship_application_id', $application->id)
            ->where('status', 'open')
            ->exists();
        if ($openFollowUp) {
            return RecipientSupportCatalog::CASE_FOLLOW_UP;
        }

        $submissions = DB::table('recipient_monitoring_submissions')
            ->where('recipient_monitoring_cycle_id', $cycle->id)
            ->where('scholarship_application_id', $application->id)
            ->get(['review_status']);
        if ($submissions->isEmpty()) {
            return RecipientSupportCatalog::CASE_AWAITING_SUBMISSION;
        }
        if ($submissions->contains('review_status', 'needs_correction')) {
            return RecipientSupportCatalog::CASE_CORRECTION_REQUESTED;
        }
        if ($submissions->contains('review_status', 'not_met')) {
            return RecipientSupportCatalog::CASE_FOLLOW_UP;
        }
        if ($submissions->every(fn (object $submission): bool => in_array($submission->review_status, ['met', 'excused'], true))) {
            return RecipientSupportCatalog::CASE_READY_TO_CLOSE;
        }

        return RecipientSupportCatalog::CASE_SUBMITTED;
    }
};
