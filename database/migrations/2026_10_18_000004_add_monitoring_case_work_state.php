<?php

use App\Domain\Recipients\RecipientSupportCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_cases', function (Blueprint $table): void {
            $table->string('due_status')->default('open')->after('status');
            $table->index('due_status', 'recipient_monitoring_cases_due_status_index');
            $table->date('effective_due_at')->nullable()->after('due_status');
            $table->string('next_action_role')->nullable()->after('status_reason');
            $table->string('next_action')->nullable()->after('next_action_role');
        });

        $today = CarbonImmutable::today();
        DB::table('recipient_monitoring_cases as cases')
            ->join('recipient_monitoring_cycles as cycles', 'cycles.id', '=', 'cases.recipient_monitoring_cycle_id')
            ->select([
                'cases.id',
                'cases.status',
                'cases.scholarship_application_id',
                'cycles.id as cycle_id',
                'cycles.status as cycle_status',
                'cycles.opens_at',
                'cycles.due_at',
                'cycles.grace_period_days',
            ])
            ->orderBy('cases.id')
            ->each(function (object $case) use ($today): void {
                $approvedDueAt = DB::table('recipient_monitoring_adjustment_requests')
                    ->where('recipient_monitoring_cycle_id', $case->cycle_id)
                    ->where('scholarship_application_id', $case->scholarship_application_id)
                    ->where('request_type', 'extension')
                    ->where('status', 'approved')
                    ->max('approved_due_at');
                $dueAt = CarbonImmutable::parse($approvedDueAt ?: $case->due_at);
                $opensAt = $case->opens_at ? CarbonImmutable::parse($case->opens_at) : null;
                $graceEndsAt = $dueAt->addDays((int) ($case->grace_period_days ?? 0));
                $closed = in_array($case->status, [
                    RecipientSupportCatalog::CASE_CLOSED,
                    RecipientSupportCatalog::CASE_CANCELLED,
                    RecipientSupportCatalog::CASE_WAIVED,
                ], true) || in_array($case->cycle_status, ['closed', 'cancelled'], true);
                $dueStatus = match (true) {
                    $closed => 'closed',
                    $opensAt?->isAfter($today) => 'upcoming',
                    $today->lessThanOrEqualTo($dueAt) && $approvedDueAt && $dueAt->isAfter(CarbonImmutable::parse($case->due_at)) => 'extended',
                    $today->lessThanOrEqualTo($dueAt) => 'open',
                    $today->lessThanOrEqualTo($graceEndsAt) => 'grace_period',
                    default => 'overdue',
                };
                [$role, $action] = $this->nextActionForStatus($case->status, $dueStatus);

                DB::table('recipient_monitoring_cases')
                    ->where('id', $case->id)
                    ->update([
                        'due_status' => $dueStatus,
                        'effective_due_at' => $dueAt->toDateString(),
                        'next_action_role' => $role,
                        'next_action' => $action,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('recipient_monitoring_cases', function (Blueprint $table): void {
            $table->dropIndex('recipient_monitoring_cases_due_status_index');
            $table->dropColumn(['due_status', 'effective_due_at', 'next_action_role', 'next_action']);
        });
    }

    /** @return array{string|null, string|null} */
    private function nextActionForStatus(string $status, string $dueStatus): array
    {
        return match ($status) {
            RecipientSupportCatalog::CASE_AWAITING_AGREEMENT => ['applicant', 'accept_agreement'],
            RecipientSupportCatalog::CASE_EXTENSION_PENDING => ['provider', 'decide_adjustment'],
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED => ['applicant', 'replace_record'],
            RecipientSupportCatalog::CASE_SUBMITTED,
            RecipientSupportCatalog::CASE_UNDER_REVIEW => ['provider', 'review_requirements'],
            RecipientSupportCatalog::CASE_FOLLOW_UP => ['provider', 'manage_follow_up'],
            RecipientSupportCatalog::CASE_READY_TO_CLOSE,
            RecipientSupportCatalog::CASE_WAIVED => ['provider', 'close_check_in'],
            RecipientSupportCatalog::CASE_CLOSED,
            RecipientSupportCatalog::CASE_CANCELLED => [null, null],
            default => $dueStatus === 'overdue'
                ? ['provider', 'resolve_overdue']
                : ['applicant', 'submit_requirements'],
        };
    }
};
