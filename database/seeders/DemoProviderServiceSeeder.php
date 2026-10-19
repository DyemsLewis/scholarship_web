<?php

namespace Database\Seeders;

use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\User;
use App\Services\ProviderServiceLifecycleService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoProviderServiceSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::query()->where('role', 'admin')->orderBy('id')->first();
        $provider = User::query()
            ->where('role', 'provider')
            ->where('email', env('DEMO_PROVIDER_EMAIL', 'programs@harborlight.test'))
            ->first();

        if (! $provider) {
            $provider = User::query()
                ->where('role', 'provider')
                ->whereNull('parent_account_id')
                ->orderBy('id')
                ->first();
        }

        if (! $admin || ! $provider) {
            $this->command?->warn('Demo admin or Harborlight provider account is missing. Provider services were not seeded.');

            return;
        }

        $providerBilling = User::query()->where('username', 'harborlight.billing')->first() ?: $provider;
        $adminBilling = User::query()->where('username', 'admin.billing')->first() ?: $admin;
        $program = $provider->providerScholarships()->orderBy('id')->first();
        $now = now()->startOfMinute();

        $readyRequest = $this->serviceRequest($provider, $providerBilling, 'assisted_setup', 'draft-review', [
            'scholarship_id' => $program?->id,
            'status' => 'ready_for_review',
            'answers' => [
                'program_stage' => 'review',
                'main_blocker' => 'The draft needs a final requirements and publishing-readiness review.',
                'requested_outcome' => 'A focused list of corrections before the program is submitted.',
                'contact_name' => $providerBilling->name,
                'contact_email' => $providerBilling->email,
            ],
            'request_summary' => 'Review the Harborlight scholarship draft before publication.',
            'requested_outcome' => 'A focused list of corrections before program submission.',
            'submitted_at' => $now->copy()->subDay(),
        ]);
        $checkoutRequest = $this->serviceRequest($provider, $providerBilling, 'integration_consultation', 'payment-pending', [
            'status' => 'checkout_started',
            'answers' => [
                'current_process' => 'Applicant records are prepared in spreadsheets before they are encoded in the portal.',
                'systems_used' => 'Spreadsheet and shared drive',
                'requested_outcome' => 'A safe transition plan that avoids duplicate applicant records.',
                'contact_name' => $providerBilling->name,
                'contact_email' => $providerBilling->email,
            ],
            'request_summary' => 'Map the existing spreadsheet process to the portal.',
            'requested_outcome' => 'A safe transition plan that avoids duplicate applicant records.',
            'submitted_at' => $now->copy()->subDays(2),
            'reviewed_at' => $now->copy()->subDay(),
            'terms_accepted_at' => $now->copy()->subDay(),
        ]);
        $this->service($provider, $providerBilling, 'integration_consultation', 'payment-pending', [
            'service_request_id' => $checkoutRequest->id,
            'status' => 'pending',
            'fulfillment_status' => 'queued',
            'request_summary' => $checkoutRequest->request_summary,
            'requested_outcome' => $checkoutRequest->requested_outcome,
            'priority' => 'normal',
            'assigned_to' => null,
            'target_due_at' => null,
            'paid_at' => null,
            'checkout_url' => 'https://checkout.paymongo.com/demo-pending-service',
        ]);
        $this->service($provider, $providerBilling, 'assisted_setup', 'payment-failed', [
            'status' => 'failed',
            'fulfillment_status' => 'queued',
            'request_summary' => 'Prepare a second program draft for a publishing-readiness review.',
            'requested_outcome' => 'A reviewed draft that can proceed to publication review.',
            'priority' => 'normal',
            'assigned_to' => null,
            'target_due_at' => null,
            'paid_at' => null,
            'failed_at' => $now->copy()->subHours(8),
            'failure_message' => 'The demonstration payment was not completed.',
        ]);
        $cycleMeeting = $now->copy()->addDays(3)->setTime(14, 0);
        $cycle = $this->service($provider, $providerBilling, 'application_cycle_support', 'application_cycle_support', [
            'plan_name' => 'Application cycle support',
            'amount' => (int) config('billing.plans.application_cycle_support.amount', 250000),
            'fulfillment_status' => 'in_progress',
            'request_summary' => 'Our team is preparing the next scholarship application cycle and needs help organizing applicant stages, review ownership, schedules, and notifications before the public deadline.',
            'requested_outcome' => 'A clear application workflow with an organized applicant queue, verified schedule details, and applicant notifications ready for the active cycle.',
            'priority' => 'high',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->addDays(10)->setTime(17, 0),
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Workflow setup review', 'completed' => true],
                ['id' => 'milestone_2', 'label' => 'Applicant queue organization', 'completed' => true],
                ['id' => 'milestone_3', 'label' => 'Schedule and notification check', 'completed' => false],
            ],
            'meeting_scheduled_for' => $cycleMeeting,
            'meeting_mode' => 'online',
            'meeting_purpose' => 'Confirm the final applicant stages, review assignments, and notification schedule before the cycle opens.',
            'meeting_status' => 'confirmed',
            'meeting_admin_note' => 'Online coordination meeting confirmed. The private meeting link will be sent to the provider team before the scheduled time.',
            'meeting_decided_at' => $now->copy()->subDay(),
            'meeting_decided_by' => $adminBilling->id,
            'fulfilled_at' => null,
            'provider_confirmed_at' => null,
            'provider_feedback' => null,
            'provider_rating' => null,
            'reopened_at' => null,
            'fulfilled_by' => $adminBilling->id,
            'fulfillment_notes' => 'The workflow and applicant queue are organized. The final schedule and notification check is in progress.',
        ]);

        $this->seedUpdates($cycle, [
            [
                'actor_id' => $providerBilling->id,
                'kind' => 'provider_response',
                'message' => 'We currently expect about 50 applicants. Two provider reviewers will check eligibility and documents before qualified applicants move to the scheduled assessment stage.',
                'at' => $now->copy()->subDays(5)->setTime(9, 15),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'progress_update',
                'message' => 'The application stages and reviewer responsibilities have been reviewed. The workflow now separates document review, provider decision, and scheduled activities.',
                'at' => $now->copy()->subDays(4)->setTime(15, 30),
            ],
            [
                'actor_id' => $providerBilling->id,
                'kind' => 'meeting_request',
                'message' => "Online meeting requested for {$cycleMeeting->format('M j, Y g:i A')} to confirm the final cycle setup.",
                'at' => $now->copy()->subDays(2)->setTime(10, 0),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'meeting_confirmed',
                'message' => "Meeting confirmed for {$cycleMeeting->format('M j, Y g:i A')}. The private meeting link will be sent to the provider team.",
                'at' => $now->copy()->subDay()->setTime(11, 20),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'progress_update',
                'message' => 'The applicant queue has been organized. The remaining work is to verify event dates, applicant instructions, and notification timing.',
                'at' => $now->copy()->subHours(4),
            ],
        ]);
        $this->seedFiles($cycle, [
            [
                'category' => 'supporting',
                'name' => 'application-cycle-workflow.pdf',
                'title' => 'Application Cycle Workflow',
                'lines' => [
                    'Expected applicants: approximately 50',
                    'Review team: two provider reviewers',
                    'Stages: eligibility, documents, decision, scheduled activity',
                    'Main need: queue, schedule, and notification readiness',
                ],
                'uploaded_by' => $providerBilling->id,
                'at' => $now->copy()->subDays(5)->setTime(9, 20),
            ],
            [
                'category' => 'deliverable',
                'name' => 'cycle-workflow-review.pdf',
                'title' => 'Cycle Workflow Review',
                'lines' => [
                    'Workflow stages reviewed and clarified',
                    'Applicant queue labels organized',
                    'Reviewer ownership documented',
                    'Schedule and notification check remains in progress',
                ],
                'uploaded_by' => $adminBilling->id,
                'at' => $now->copy()->subHours(4),
            ],
        ]);

        $setupMeeting = $now->copy()->subDays(9)->setTime(10, 0);
        $completed = $this->service($provider, $providerBilling, 'assisted_setup', 'assisted_setup', [
            'plan_name' => 'Assisted program setup',
            'amount' => (int) config('billing.plans.assisted_setup.amount', 75000),
            'fulfillment_status' => 'completed',
            'request_summary' => 'Our organization needed guidance completing its first scholarship program form, defining understandable eligibility rules, and checking the program before submission for admin review.',
            'requested_outcome' => 'A complete scholarship program draft with clear eligibility and document requirements that is ready to submit for publication review.',
            'priority' => 'normal',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->subDays(5)->setTime(17, 0),
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Program form walkthrough', 'completed' => true],
                ['id' => 'milestone_2', 'label' => 'Requirement and eligibility review', 'completed' => true],
                ['id' => 'milestone_3', 'label' => 'Publishing-readiness check', 'completed' => true],
            ],
            'meeting_scheduled_for' => $setupMeeting,
            'meeting_mode' => 'online',
            'meeting_purpose' => 'Walk through the program form and resolve questions about eligibility, requirements, benefits, and the review process.',
            'meeting_status' => 'confirmed',
            'meeting_admin_note' => 'The online walkthrough was completed with the provider program coordinator.',
            'meeting_decided_at' => $now->copy()->subDays(10),
            'meeting_decided_by' => $adminBilling->id,
            'fulfilled_at' => $now->copy()->subDays(5)->setTime(15, 30),
            'provider_confirmed_at' => $now->copy()->subDays(4)->setTime(9, 45),
            'provider_feedback' => 'The walkthrough and checklist made the program form easier to complete and review.',
            'provider_rating' => 5,
            'reopened_at' => null,
            'fulfilled_by' => $adminBilling->id,
            'fulfillment_notes' => 'All setup milestones are complete. The provider received the final publishing-readiness checklist and confirmed the result.',
        ]);

        $this->seedUpdates($completed, [
            [
                'actor_id' => $providerBilling->id,
                'kind' => 'provider_response',
                'message' => 'We need the program to support senior high school applicants and clearly separate general eligibility from documents that applicants upload later.',
                'at' => $now->copy()->subDays(12)->setTime(8, 45),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'meeting_confirmed',
                'message' => "Program setup walkthrough confirmed for {$setupMeeting->format('M j, Y g:i A')}.",
                'at' => $now->copy()->subDays(10)->setTime(9, 0),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'progress_update',
                'message' => 'The program form walkthrough and eligibility review are complete. Requirements were rewritten as clear applicant-facing items.',
                'at' => $now->copy()->subDays(7)->setTime(14, 10),
            ],
            [
                'actor_id' => $adminBilling->id,
                'kind' => 'progress_update',
                'message' => 'The publishing-readiness check is complete. The final checklist and recommended corrections are available under deliverables.',
                'at' => $now->copy()->subDays(5)->setTime(15, 30),
            ],
            [
                'actor_id' => $providerBilling->id,
                'kind' => 'provider_confirmation',
                'message' => 'The provider reviewed the deliverables and confirmed that the requested setup support was completed.',
                'at' => $now->copy()->subDays(4)->setTime(9, 45),
            ],
        ]);
        $this->seedFiles($completed, [
            [
                'category' => 'supporting',
                'name' => 'draft-program-brief.pdf',
                'title' => 'Draft Program Brief',
                'lines' => [
                    'Target applicants: Senior High School learners',
                    'Support type: Financial assistance and school supplies',
                    'Provider concern: clear eligibility and requirements',
                    'Goal: prepare the program for publication review',
                ],
                'uploaded_by' => $providerBilling->id,
                'at' => $now->copy()->subDays(12)->setTime(8, 50),
            ],
            [
                'category' => 'deliverable',
                'name' => 'publishing-readiness-checklist.pdf',
                'title' => 'Publishing Readiness Checklist',
                'lines' => [
                    'Program identity and description complete',
                    'Target applicant criteria reviewed',
                    'Document requirements written clearly',
                    'Benefits and process details confirmed',
                    'Program ready for admin publication review',
                ],
                'uploaded_by' => $adminBilling->id,
                'at' => $now->copy()->subDays(5)->setTime(15, 25),
            ],
        ]);

        $ready = $this->service($provider, $providerBilling, 'application_cycle_support', 'ready-to-start', [
            'fulfillment_status' => 'ready',
            'request_summary' => 'Organize the next application cycle before applicant intake opens.',
            'requested_outcome' => 'A confirmed operating plan for queues, schedules, and team ownership.',
            'priority' => 'normal',
            'assigned_to' => null,
            'target_due_at' => $now->copy()->addDays(12),
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Workflow setup review', 'completed' => false],
                ['id' => 'milestone_2', 'label' => 'Applicant queue organization', 'completed' => false],
                ['id' => 'milestone_3', 'label' => 'Schedule and notification check', 'completed' => false],
            ],
            'meeting_scheduled_for' => $now->copy()->addDays(4)->setTime(10, 0),
            'meeting_mode' => 'online',
            'meeting_purpose' => 'Confirm the cycle scope before platform support begins.',
            'meeting_status' => 'requested',
            'fulfillment_notes' => 'Payment is confirmed. The request is waiting for assignment and meeting review.',
        ]);
        $this->seedUpdates($ready, [[
            'actor_id' => $providerBilling->id,
            'kind' => 'meeting_request',
            'message' => 'An online kickoff meeting was requested before service delivery begins.',
            'at' => $now->copy()->subHours(3),
        ]]);

        $needsInformation = $this->service($provider, $providerBilling, 'integration_consultation', 'needs-information', [
            'fulfillment_status' => 'needs_information',
            'request_summary' => 'Review the current spreadsheet intake and document filing process.',
            'requested_outcome' => 'A portal transition plan with clear data ownership and migration steps.',
            'priority' => 'high',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->addDays(7),
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Current-process review', 'completed' => false],
                ['id' => 'milestone_2', 'label' => 'Data and workflow mapping', 'completed' => false],
                ['id' => 'milestone_3', 'label' => 'Implementation recommendations', 'completed' => false],
            ],
            'fulfillment_notes' => 'Please provide a sample intake sheet with personal details removed before mapping begins.',
        ]);
        $this->seedUpdates($needsInformation, [[
            'actor_id' => $adminBilling->id,
            'kind' => 'clarification_request',
            'message' => 'Please upload a redacted sample intake sheet so the current fields can be mapped safely.',
            'at' => $now->copy()->subHours(6),
        ]]);

        $providerReview = $this->service($provider, $providerBilling, 'assisted_setup', 'provider-review', [
            'fulfillment_status' => 'provider_review',
            'request_summary' => 'Review the learning support grant setup and its applicant requirements.',
            'requested_outcome' => 'A publication-ready draft with concise eligibility and evidence requirements.',
            'priority' => 'normal',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->addDays(3),
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Program form walkthrough', 'completed' => true],
                ['id' => 'milestone_2', 'label' => 'Requirement and eligibility review', 'completed' => true],
                ['id' => 'milestone_3', 'label' => 'Publishing-readiness check', 'completed' => true],
            ],
            'fulfilled_at' => $now->copy()->subHours(2),
            'fulfilled_by' => $adminBilling->id,
            'fulfillment_notes' => 'The readiness review is complete. The provider can confirm the delivery or request changes.',
        ]);
        $this->seedUpdates($providerReview, [[
            'actor_id' => $adminBilling->id,
            'kind' => 'progress_update',
            'message' => 'The reviewed program checklist is ready for provider confirmation.',
            'at' => $now->copy()->subHours(2),
        ]]);
        $this->seedFiles($providerReview, [[
            'category' => 'deliverable',
            'name' => 'program-readiness-review.pdf',
            'title' => 'Program Readiness Review',
            'lines' => [
                'Eligibility statements simplified',
                'Required evidence separated from optional files',
                'Applicant instructions checked for clarity',
                'Provider confirmation is still required',
            ],
            'uploaded_by' => $adminBilling->id,
            'at' => $now->copy()->subHours(2),
        ]]);

        $cancellationPending = $this->service($provider, $providerBilling, 'integration_consultation', 'cancellation-review', [
            'fulfillment_status' => 'in_progress',
            'request_summary' => 'Map the provider reporting process to the portal.',
            'requested_outcome' => 'A practical reporting workflow for the provider team.',
            'priority' => 'normal',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->addDays(9),
            'cancellation_status' => 'requested',
            'cancellation_reason' => 'The provider postponed its reporting migration until the next school year.',
            'cancellation_requested_at' => $now->copy()->subHour(),
            'cancellation_requested_by' => $providerBilling->id,
            'fulfillment_notes' => 'Work is paused while the cancellation request is reviewed.',
        ]);
        $this->seedUpdates($cancellationPending, [[
            'actor_id' => $providerBilling->id,
            'kind' => 'provider_response',
            'message' => 'Cancellation requested because the reporting migration was postponed.',
            'at' => $now->copy()->subHour(),
        ]]);

        $cancelled = $this->service($provider, $providerBilling, 'application_cycle_support', 'cancelled-refunded', [
            'fulfillment_status' => 'cancelled',
            'request_summary' => 'Provide application-cycle support for a program that was later postponed.',
            'requested_outcome' => 'An organized application cycle once the program schedule is confirmed.',
            'priority' => 'normal',
            'assigned_to' => $adminBilling->id,
            'target_due_at' => $now->copy()->subDays(3),
            'cancellation_status' => 'approved',
            'cancellation_reason' => 'The scholarship cycle was postponed before delivery began.',
            'cancellation_requested_at' => $now->copy()->subDays(6),
            'cancellation_requested_by' => $providerBilling->id,
            'cancellation_decision_note' => 'Cancellation approved because no delivery milestone had started.',
            'cancellation_decided_at' => $now->copy()->subDays(5),
            'cancellation_decided_by' => $adminBilling->id,
            'cancelled_at' => $now->copy()->subDays(5),
            'closed_at' => $now->copy()->subDays(5),
            'refund_status' => 'refunded',
            'refund_reference' => 'DEMO-REFUND-001',
            'fulfillment_notes' => 'The request was cancelled before delivery and the demonstration refund was recorded.',
        ]);
        $this->seedUpdates($cancelled, [[
            'actor_id' => $adminBilling->id,
            'kind' => 'progress_update',
            'message' => 'Cancellation approved and the refund record was completed.',
            'at' => $now->copy()->subDays(5),
        ]]);

        foreach ([
            [$cycle, $adminBilling, 'in_progress'],
            [$completed, $providerBilling, 'completed'],
            [$ready, $providerBilling, 'ready'],
            [$needsInformation, $adminBilling, 'needs_information'],
            [$providerReview, $adminBilling, 'provider_review'],
            [$cancellationPending, $providerBilling, 'cancellation_requested'],
            [$cancelled, $adminBilling, 'cancelled'],
        ] as [$purchase, $actor, $state]) {
            $this->seedLifecycleEvents($purchase, $actor, $state, $now);
        }

        $readyRequest->touch();
    }

    private function service(User $provider, User $creator, string $planCode, string $demoKey, array $workspace): ProviderServicePurchase
    {
        $purchase = ProviderServicePurchase::query()->firstOrNew([
            'reference_number' => 'SP-DEMO-'.strtoupper(str_replace('_', '-', $demoKey)),
        ]);

        $purchase->fill([
            'provider_id' => $provider->id,
            'created_by' => $creator->id,
            'service_request_id' => null,
            'plan_code' => $planCode,
            'plan_name' => config("billing.plans.{$planCode}.name", $planCode),
            'amount' => (int) config("billing.plans.{$planCode}.amount", 0),
            'currency' => config('billing.currency', 'PHP'),
            'status' => 'paid',
            'fulfillment_status' => 'ready',
            'checkout_session_id' => null,
            'checkout_url' => null,
            'payment_intent_id' => null,
            'payment_id' => null,
            'payment_method' => 'demo',
            'livemode' => false,
            'service_terms_accepted_at' => now()->subWeeks(3),
            'paid_at' => now()->subWeeks(3),
            'started_at' => null,
            'failed_at' => null,
            'failure_message' => null,
            'fulfilled_at' => null,
            'closed_at' => null,
            'provider_confirmed_at' => null,
            'provider_feedback' => null,
            'provider_rating' => null,
            'reopened_at' => null,
            'revision_count' => 0,
            'cancellation_status' => null,
            'cancellation_reason' => null,
            'cancellation_requested_at' => null,
            'cancellation_requested_by' => null,
            'cancellation_decision_note' => null,
            'cancellation_decided_at' => null,
            'cancellation_decided_by' => null,
            'cancelled_at' => null,
            'refund_status' => null,
            'refund_reference' => null,
            'gateway_metadata' => ['demo' => true, 'scenario' => $demoKey],
            ...$workspace,
        ])->save();

        return app(ProviderServiceLifecycleService::class)->initialize($purchase);
    }

    private function serviceRequest(User $provider, User $creator, string $planCode, string $demoKey, array $attributes): ProviderServiceRequest
    {
        return ProviderServiceRequest::query()->updateOrCreate([
            'provider_id' => $provider->id,
            'request_summary' => $attributes['request_summary'],
        ], [
            'created_by' => $creator->id,
            'plan_code' => $planCode,
            'plan_name' => config("billing.plans.{$planCode}.name", $planCode),
            'amount' => (int) config("billing.plans.{$planCode}.amount", 0),
            'currency' => config('billing.currency', 'PHP'),
            'answers' => ['demo_key' => $demoKey, ...($attributes['answers'] ?? [])],
            ...$attributes,
        ]);
    }

    private function seedLifecycleEvents(ProviderServicePurchase $purchase, User $actor, string $state, $now): void
    {
        $purchase->lifecycleEvents()->delete();
        $events = [
            [
                'event_type' => 'payment_confirmed',
                'payment_from' => 'pending',
                'payment_to' => 'paid',
                'delivery_from' => 'queued',
                'delivery_to' => 'ready',
                'action_owner' => 'platform',
                'metadata' => ['current_action' => 'Assign and start the service'],
                'at' => $now->copy()->subWeeks(3),
            ],
            [
                'event_type' => $state === 'cancellation_requested' ? 'cancellation_requested' : 'demo_state_recorded',
                'delivery_from' => $state === 'completed' ? 'provider_review' : 'ready',
                'delivery_to' => $purchase->fulfillment_status,
                'action_owner' => $purchase->current_action_owner,
                'metadata' => [
                    'current_action' => $purchase->current_action,
                    'demo_state' => $state,
                    'cancellation_status' => $purchase->cancellation_status,
                    'refund_status' => $purchase->refund_status,
                ],
                'at' => $purchase->closed_at ?: $purchase->cancellation_requested_at ?: $purchase->fulfilled_at ?: $now->copy()->subDay(),
            ],
        ];

        foreach ($events as $data) {
            $recordedAt = $data['at'];
            unset($data['at']);
            $event = $purchase->lifecycleEvents()->make(['actor_id' => $actor->id, ...$data]);
            $event->forceFill(['created_at' => $recordedAt, 'updated_at' => $recordedAt])->save();
        }
    }

    private function seedUpdates(ProviderServicePurchase $purchase, array $updates): void
    {
        $purchase->updates()->delete();

        foreach ($updates as $data) {
            $recordedAt = $data['at'];
            unset($data['at']);

            $update = $purchase->updates()->make([
                ...$data,
                'visible_to_provider' => true,
            ]);
            $update->forceFill(['created_at' => $recordedAt, 'updated_at' => $recordedAt])->save();
        }
    }

    private function seedFiles(ProviderServicePurchase $purchase, array $files): void
    {
        $existingFiles = $purchase->files()->get();
        Storage::disk('local')->delete($existingFiles->pluck('path')->filter()->all());
        $purchase->files()->delete();

        foreach ($files as $data) {
            $path = "provider-services/{$purchase->id}/demo/{$data['name']}";
            $contents = $this->simplePdf($data['title'], $data['lines']);
            Storage::disk('local')->put($path, $contents);

            $file = $purchase->files()->make([
                'uploaded_by' => $data['uploaded_by'],
                'category' => $data['category'],
                'original_name' => $data['name'],
                'path' => $path,
                'mime_type' => 'application/pdf',
                'size' => strlen($contents),
                'visible_to_provider' => true,
            ]);
            $file->forceFill(['created_at' => $data['at'], 'updated_at' => $data['at']])->save();
        }
    }

    private function simplePdf(string $title, array $lines): string
    {
        $escape = fn (string $text): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT\n/F1 18 Tf\n50 750 Td\n({$escape($title)}) Tj\n/F1 11 Tf\n0 -34 Td\n";

        foreach ($lines as $line) {
            $stream .= "({$escape($line)}) Tj\n0 -20 Td\n";
        }

        $stream .= "ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf
            .'trailer'."\n<< /Size ".(count($objects) + 1).' /Root 1 0 R >>'
            ."\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }
}
