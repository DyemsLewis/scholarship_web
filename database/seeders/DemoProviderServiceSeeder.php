<?php

namespace Database\Seeders;

use App\Models\ProviderServicePurchase;
use App\Models\User;
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
            ->where('email', env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'))
            ->first();

        if (! $admin || ! $provider) {
            $this->command?->warn('Demo admin or Tulay Aral provider account is missing. Provider services were not seeded.');

            return;
        }

        $now = now()->startOfMinute();
        $cycleMeeting = $now->copy()->addDays(3)->setTime(14, 0);
        $cycle = $this->service($provider, 'application_cycle_support', [
            'plan_name' => 'Application cycle support',
            'amount' => (int) config('billing.plans.application_cycle_support.amount', 250000),
            'fulfillment_status' => 'in_progress',
            'request_summary' => 'Our team is preparing the next scholarship application cycle and needs help organizing applicant stages, review ownership, schedules, and notifications before the public deadline.',
            'requested_outcome' => 'A clear application workflow with an organized applicant queue, verified schedule details, and applicant notifications ready for the active cycle.',
            'priority' => 'high',
            'assigned_to' => $admin->id,
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
            'meeting_decided_by' => $admin->id,
            'fulfilled_at' => null,
            'provider_confirmed_at' => null,
            'provider_feedback' => null,
            'provider_rating' => null,
            'reopened_at' => null,
            'fulfilled_by' => $admin->id,
            'fulfillment_notes' => 'The workflow and applicant queue are organized. The final schedule and notification check is in progress.',
        ]);

        $this->seedUpdates($cycle, [
            [
                'actor_id' => $provider->id,
                'kind' => 'provider_response',
                'message' => 'We currently expect about 50 applicants. Two provider reviewers will check eligibility and documents before qualified applicants move to the scheduled assessment stage.',
                'at' => $now->copy()->subDays(5)->setTime(9, 15),
            ],
            [
                'actor_id' => $admin->id,
                'kind' => 'progress_update',
                'message' => 'The application stages and reviewer responsibilities have been reviewed. The workflow now separates document review, provider decision, and scheduled activities.',
                'at' => $now->copy()->subDays(4)->setTime(15, 30),
            ],
            [
                'actor_id' => $provider->id,
                'kind' => 'meeting_request',
                'message' => "Online meeting requested for {$cycleMeeting->format('M j, Y g:i A')} to confirm the final cycle setup.",
                'at' => $now->copy()->subDays(2)->setTime(10, 0),
            ],
            [
                'actor_id' => $admin->id,
                'kind' => 'meeting_confirmed',
                'message' => "Meeting confirmed for {$cycleMeeting->format('M j, Y g:i A')}. The private meeting link will be sent to the provider team.",
                'at' => $now->copy()->subDay()->setTime(11, 20),
            ],
            [
                'actor_id' => $admin->id,
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
                'uploaded_by' => $provider->id,
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
                'uploaded_by' => $admin->id,
                'at' => $now->copy()->subHours(4),
            ],
        ]);

        $setupMeeting = $now->copy()->subDays(9)->setTime(10, 0);
        $completed = $this->service($provider, 'assisted_setup', [
            'plan_name' => 'Assisted program setup',
            'amount' => (int) config('billing.plans.assisted_setup.amount', 75000),
            'fulfillment_status' => 'completed',
            'request_summary' => 'Our organization needed guidance completing its first scholarship program form, defining understandable eligibility rules, and checking the program before submission for admin review.',
            'requested_outcome' => 'A complete scholarship program draft with clear eligibility and document requirements that is ready to submit for publication review.',
            'priority' => 'normal',
            'assigned_to' => $admin->id,
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
            'meeting_decided_by' => $admin->id,
            'fulfilled_at' => $now->copy()->subDays(5)->setTime(15, 30),
            'provider_confirmed_at' => $now->copy()->subDays(4)->setTime(9, 45),
            'provider_feedback' => 'The walkthrough and checklist made the program form easier to complete and review.',
            'provider_rating' => 5,
            'reopened_at' => null,
            'fulfilled_by' => $admin->id,
            'fulfillment_notes' => 'All setup milestones are complete. The provider received the final publishing-readiness checklist and confirmed the result.',
        ]);

        $this->seedUpdates($completed, [
            [
                'actor_id' => $provider->id,
                'kind' => 'provider_response',
                'message' => 'We need the program to support senior high school applicants and clearly separate general eligibility from documents that applicants upload later.',
                'at' => $now->copy()->subDays(12)->setTime(8, 45),
            ],
            [
                'actor_id' => $admin->id,
                'kind' => 'meeting_confirmed',
                'message' => "Program setup walkthrough confirmed for {$setupMeeting->format('M j, Y g:i A')}.",
                'at' => $now->copy()->subDays(10)->setTime(9, 0),
            ],
            [
                'actor_id' => $admin->id,
                'kind' => 'progress_update',
                'message' => 'The program form walkthrough and eligibility review are complete. Requirements were rewritten as clear applicant-facing items.',
                'at' => $now->copy()->subDays(7)->setTime(14, 10),
            ],
            [
                'actor_id' => $admin->id,
                'kind' => 'progress_update',
                'message' => 'The publishing-readiness check is complete. The final checklist and recommended corrections are available under deliverables.',
                'at' => $now->copy()->subDays(5)->setTime(15, 30),
            ],
            [
                'actor_id' => $provider->id,
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
                'uploaded_by' => $provider->id,
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
                'uploaded_by' => $admin->id,
                'at' => $now->copy()->subDays(5)->setTime(15, 25),
            ],
        ]);
    }

    private function service(User $provider, string $planCode, array $workspace): ProviderServicePurchase
    {
        $purchase = ProviderServicePurchase::query()->firstOrNew([
            'provider_id' => $provider->id,
            'plan_code' => $planCode,
        ]);

        if (! $purchase->exists) {
            $purchase->fill([
                'created_by' => $provider->id,
                'currency' => config('billing.currency', 'PHP'),
                'status' => 'paid',
                'reference_number' => 'SP-DEMO-'.strtoupper(str_replace('_', '-', $planCode)),
                'service_terms_accepted_at' => now()->subWeeks(3),
                'paid_at' => now()->subWeeks(3),
            ]);
        }

        $purchase->fill(['status' => 'paid', ...$workspace])->save();

        return $purchase;
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
            "<< /Length ".strlen($stream)." >>\nstream\n{$stream}endstream",
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
