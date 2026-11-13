<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\SupportReport;
use App\Models\User;
use App\Services\ProviderServiceLifecycleService;
use App\Services\ServiceFinanceCoordination;
use App\Services\SupportAccountHandoffService;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AdminProcessHandoffDemoSeeder extends Seeder
{
    public const MARKER = 'workflow_admin_handoffs_20261113';

    public function run(): void
    {
        if (app()->environment('production') && getenv('ADMIN_PROCESS_DATA_CONFIRM') !== self::MARKER) {
            throw new RuntimeException('Production preparation requires a verified backup and explicit confirmation.');
        }
        if (ActivityLog::where('action', self::MARKER)->exists()) return;
        if (ProviderServicePurchase::where('reference_number', 'like', 'GUIDE-20261113-%')->exists()) {
            throw new RuntimeException('Reserved guide examples already exist without a completion marker.');
        }
        $users = [];
        foreach (['talunaw', 'talunaw.billing', 'demoops.support', 'demoops.accounts', 'demoops.billing', 'demoops.finance',
            'demo.process.support.appointment', 'demo.process.support.review'] as $username) {
            $users[$username] = User::where('username', $username)->sole();
            if (! $users[$username]->isActive()) throw new RuntimeException('Required account is inactive: '.$username);
        }
        DB::transaction(function () use ($users): void {
            $handoffs = app(SupportAccountHandoffService::class);
            foreach (['pending' => 'demo.process.support.appointment', 'returned' => 'demo.process.support.review'] as $state => $username) {
                $report = SupportReport::create(['applicant_id' => $users[$username]->id, 'assigned_role' => 'admin', 'category' => 'account',
                    'subject' => $state === 'pending' ? 'Check my sign-in settings' : 'Explain the account access check',
                    'description' => 'I can sign in. Please help me understand the account access and password recovery settings.',
                    'context' => 'Account access help', 'status' => 'open', 'provider_status' => 'not_required', 'admin_status' => 'open']);
                $handoffs->refer($this->request($users['demoops.support'], [
                    'expected_support_token' => $handoffs->caseToken($report), 'task' => 'sign_in',
                    'instruction' => 'Check the account access settings and explain the next account-holder step. Do not change verification or scholarship support.',
                ]), $report);
                if ($state === 'returned') {
                    $task = $report->accountHandoffs()->sole();
                    $handoffs->finish($this->request($users['demoops.accounts'], [
                        'expected_handoff_token' => $handoffs->payload($task)['token'],
                        'result' => 'The account is active and email verified. Sign-in is available; use password recovery only if the account holder needs it. No account or verification changes were required.',
                    ]), $task, false);
                }
            }
            $finance = app(ServiceFinanceCoordination::class);
            foreach (['BILLING', 'FINANCE'] as $state) {
                $purchase = ProviderServicePurchase::create(['provider_id' => $users['talunaw']->id, 'created_by' => $users['talunaw.billing']->id,
                    'plan_code' => 'application_cycle_support', 'plan_name' => 'Application cycle support',
                    'amount' => config('billing.plans.application_cycle_support.amount'), 'currency' => 'PHP',
                    'status' => 'paid', 'fulfillment_status' => 'in_progress', 'livemode' => false,
                    'payment_method' => 'demo', 'reference_number' => 'GUIDE-20261113-'.$state,
                    'paid_at' => now()->subDays(3), 'started_at' => now()->subDays(2), 'assigned_to' => $users['demoops.billing']->id,
                    'request_summary' => 'Review operational records for the current scholarship cycle.',
                    'requested_outcome' => 'Clear service records and confirmed ownership of the remaining delivery work.',
                    'target_due_at' => now()->addDays(7), 'service_terms_accepted_at' => now()->subDays(4)]);
                app(ProviderServiceLifecycleService::class)->initialize($purchase);
                $finance->financeReview($this->request($users['demoops.finance'], [
                    'expected_finance_token' => $finance->payload($purchase->fresh())['token'], 'action' => 'request_billing',
                    'note' => 'Please compare the service intake and payment reference with the delivery record. Confirm what was checked without changing payment or fulfillment status.',
                ]), $purchase);
                if ($state === 'FINANCE') {
                    $finance->billingResponse($this->request($users['demoops.billing'], [
                        'expected_finance_token' => $finance->payload($purchase->fresh())['token'],
                        'note' => 'The intake, amount and payment reference match the service record. Delivery remains in progress under the assigned officer. No payment change or refund was recorded.',
                    ]), $purchase);
                }
            }
            ActivityLog::record($users['demoops.support'], self::MARKER,
                'Prepared separate account-access and service-record handoffs. Existing accounts and payment records were not altered.');
        });
    }

    private function request(User $actor, array $data): Request
    {
        $request = Request::create('/internal/guide-preparation', 'POST', $data);
        $request->setUserResolver(fn () => $actor);
        return $request;
    }
}
