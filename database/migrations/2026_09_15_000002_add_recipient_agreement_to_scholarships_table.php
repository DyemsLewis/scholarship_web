<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->json('recipient_agreement')->nullable()->after('other_contract_terms');
        });

        $agreements = [
            'Tulay Aral Senior High Support Grant' => [
                'commitment_type' => 'activities',
                'duration' => 'One orientation and one end-of-term update during the current program cycle.',
                'noncompliance_consequence' => 'The provider will contact the recipient first. Continued or unreleased support may be paused or ended only after the circumstances are reviewed.',
                'exit_or_exception_process' => 'The recipient may contact the Community Scholarship Desk to explain illness, transfer, withdrawal, or another circumstance and request an adjusted arrangement.',
            ],
            'Tulay Aral College Starter Grant' => [
                'commitment_type' => 'reporting',
                'duration' => 'Proof of enrollment before release and one utilization update after the first semester.',
                'noncompliance_consequence' => 'Support not yet released may be held while the provider verifies the recipient status. Any further action must be explained directly to the recipient.',
                'exit_or_exception_process' => 'The recipient may contact the Community Scholarship Desk to report enrollment changes or request consideration for circumstances outside their control.',
            ],
            'Bukas Kinabukasan School Essentials Grant' => [
                'commitment_type' => 'activities',
                'duration' => 'One release orientation during the current school year.',
                'noncompliance_consequence' => 'Materials will not be released until the parent or guardian completes the required receipt and orientation process.',
                'exit_or_exception_process' => 'A parent or guardian may contact the Learner Support Office to request another orientation arrangement when attendance is not possible.',
            ],
            'Bukas Kinabukasan STEM Pathways Grant' => [
                'commitment_type' => 'activities',
                'duration' => 'One community learning session during the current program cycle.',
                'noncompliance_consequence' => 'The provider will contact the recipient and review the circumstances before deciding whether any remaining program support should continue.',
                'exit_or_exception_process' => 'The recipient may contact the STEM Programs Office to explain academic, health, family, or scheduling circumstances and request an alternative arrangement.',
            ],
        ];

        foreach ($agreements as $title => $agreement) {
            DB::table('scholarships')
                ->where('title', $title)
                ->update(['recipient_agreement' => json_encode($agreement)]);
        }
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table) {
            $table->dropColumn('recipient_agreement');
        });
    }
};
