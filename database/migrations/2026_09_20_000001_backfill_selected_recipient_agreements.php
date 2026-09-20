<?php

use App\Models\ScholarshipApplication;
use App\Support\RecipientAgreement;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ScholarshipApplication::query()
            ->whereNull('provider_contract_terms_snapshot')
            ->where(function ($query): void {
                $query->where('final_outcome', 'selected')
                    ->orWhereIn('status', ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated']);
            })
            ->with(['scholarship.provider.providerProfile', 'scholarship.benefits'])
            ->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    if (! $application->scholarship) {
                        continue;
                    }

                    $snapshot = RecipientAgreement::snapshot($application->scholarship, $application);
                    $application->update([
                        'provider_contract_terms_snapshot' => $snapshot,
                        'provider_contract_terms_version' => RecipientAgreement::version($snapshot),
                    ]);
                }
            });
    }

    public function down(): void
    {
        ScholarshipApplication::query()
            ->where('provider_contract_terms_version', 'like', 'recipient-agreement-v1-%')
            ->update([
                'provider_contract_terms_snapshot' => null,
                'provider_contract_terms_version' => null,
                'provider_contract_terms_accepted_at' => null,
                'provider_contract_acceptance_ip' => null,
                'provider_contract_acceptance_user_agent' => null,
                'student_response_status' => null,
                'student_responded_at' => null,
                'student_response_terms_accepted_at' => null,
                'student_response_terms_version' => null,
                'student_response_note' => null,
            ]);
    }
};
