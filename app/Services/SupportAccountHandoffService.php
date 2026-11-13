<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\SupportAccountHandoff;
use App\Models\SupportReport;
use App\Models\User;
use App\Support\AdminWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class SupportAccountHandoffService
{
    public function caseToken(SupportReport $report): string
    {
        $handoffs = $report->accountHandoffs()->orderBy('id')->get()
            ->map(fn ($item) => $item->only(['id', 'status', 'result']))->all();

        return $this->token([$report->id, $report->admin_status, $report->provider_status,
            $report->status, $report->admin_response, $report->provider_response, $handoffs]);
    }

    public function assertCaseToken(SupportReport $report, string $token): void
    {
        abort_unless(hash_equals($this->caseToken($report), $token), 409,
            'This case changed. Reload it before continuing; keep a copy of any unsaved response.');
    }

    public function payload(SupportAccountHandoff $handoff): array
    {
        return [
            'id' => $handoff->id,
            'task' => $handoff->task,
            'task_label' => SupportAccountHandoff::TASKS[$handoff->task] ?? 'Account access follow-up',
            'instruction' => $handoff->instruction,
            'status' => $handoff->status,
            'status_label' => match ($handoff->status) {
                'pending' => 'With Account Manager',
                'completed' => 'Returned to Support',
                default => 'Cancelled by Support',
            },
            'result' => $handoff->result,
            'requested_at' => $handoff->created_at?->format('M d, Y h:i A'),
            'completed_at' => $handoff->completed_at?->format('M d, Y h:i A'),
            'token' => $this->token($handoff->only(['id', 'user_id', 'status', 'instruction', 'result'])),
        ];
    }

    public function forAccount(User $user): array
    {
        // Deliberately exclude the source report, its subject, privacy data, and attachments.
        return SupportAccountHandoff::query()->where('user_id', $user->id)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')->limit(10)->get()->map(fn ($item) => $this->payload($item))->all();
    }

    public function refer(Request $request, SupportReport $report): void
    {
        $actor = $request->user();
        $this->authorize($actor, 'manage_reports');
        $data = $request->validate([
            'expected_support_token' => ['required', 'string', 'size:64'],
            'task' => ['required', Rule::in(array_keys(SupportAccountHandoff::TASKS))],
            'instruction' => ['required', 'string', 'min:10', 'max:600'],
        ]);
        DB::transaction(function () use ($request, $report, $actor, $data): void {
            $report = SupportReport::query()->lockForUpdate()->findOrFail($report->id);
            $this->assertCaseToken($report, $data['expected_support_token']);
            abort_unless($report->admin_status === 'open', 409, 'Reopen the platform response before referring this case.');
            abort_unless($report->applicant?->isApplicant() || $report->applicant?->isProvider(), 422,
                'Only applicant or provider access issues can be referred here.');
            abort_if($report->accountHandoffs()->where('status', 'pending')->exists(), 409, 'An account task is already pending.');
            $handoff = $report->accountHandoffs()->create([
                'user_id' => $report->applicant_id, 'requested_by' => $actor->id,
                'task' => $data['task'], 'instruction' => trim($data['instruction']), 'status' => 'pending',
            ]);
            ActivityLog::record($actor, 'support_account_handoff_requested', 'Referred an account access task to the Account Manager.', $request,
                ['support_report_id' => $report->id, 'handoff_id' => $handoff->id, 'target_user_id' => $report->applicant_id]);
            User::query()->where('role', 'admin')->where('account_status', '!=', 'suspended')->get()
                ->filter(fn (User $user) => $user->hasPortalPermission('manage_accounts') && ! AdminWorkspace::usesPortalManagerWorkspace($user))
                ->each(fn (User $user) => PortalNotification::create([
                    'user_id' => $user->id, 'type' => 'support_account_handoff', 'title' => 'Account access follow-up',
                    'message' => 'Support requested an account access check. Review the task in the account record.',
                    'action_url' => '/admin/workspaces/accounts/'.$report->applicant_id,
                ]));
        });
    }

    public function finish(Request $request, SupportAccountHandoff $handoff, bool $cancel): void
    {
        $actor = $request->user();
        $this->authorize($actor, $cancel ? 'manage_reports' : 'manage_accounts');
        $data = $request->validate([
            'expected_handoff_token' => ['required', 'string', 'size:64'],
            'result' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $handoff, $actor, $data, $cancel): void {
            // All mutations lock the case first, so completing and cancelling cannot race.
            $report = SupportReport::query()->lockForUpdate()->findOrFail($handoff->support_report_id);
            $handoff = SupportAccountHandoff::query()->lockForUpdate()->findOrFail($handoff->id);
            abort_unless($handoff->status === 'pending'
                && hash_equals($this->payload($handoff)['token'], $data['expected_handoff_token']), 409,
                'This account task changed. Reload the record before continuing.');
            abort_unless($report->admin_status === 'open', 409, 'The platform response is no longer open.');
            $handoff->update([
                'status' => $cancel ? 'cancelled' : 'completed', 'result' => trim($data['result']),
                'completed_by' => $actor->id, 'completed_at' => now(),
            ]);
            ActivityLog::record($actor, $cancel ? 'support_account_handoff_cancelled' : 'support_account_handoff_completed',
                $cancel ? 'Cancelled an account access handoff.' : 'Returned an account access task to Support.', $request,
                ['support_report_id' => $report->id, 'handoff_id' => $handoff->id, 'target_user_id' => $handoff->user_id]);
            if (! $cancel && $handoff->requested_by) {
                PortalNotification::create([
                    'user_id' => $handoff->requested_by, 'type' => 'support_account_handoff', 'title' => 'Account task returned',
                    'message' => 'The Account Manager recorded a result. Review it before replying to the reporter.',
                    'action_url' => '/admin/workspaces/support/cases/'.$report->id,
                ]);
            }
        });
    }

    public function authorize(User $actor, string $permission): void
    {
        abort_unless($actor->isAdmin() && $actor->hasPortalPermission($permission)
            && ! AdminWorkspace::usesPortalManagerWorkspace($actor), 403);
    }

    private function token(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
