<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            $table->string('open_obligation_key', 64)->nullable()->unique('agreement_open_obligation');
        });
        Schema::table('recipient_agreement_case_responses', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('response_kind', 32)->nullable();
            $table->unique(['recipient_agreement_case_id', 'request_key'], 'agreement_response_request');
        });
        Schema::create('recipient_agreement_evidence_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_id')->unique()
                ->constrained('recipient_agreement_case_evidence')->cascadeOnDelete();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_agreement_evidence_exclusions');
        Schema::table('recipient_agreement_case_responses', function (Blueprint $table): void {
            $table->dropUnique('agreement_response_request');
            $table->dropColumn(['request_key', 'request_hash', 'response_kind']);
        });
        Schema::table('recipient_agreement_cases', function (Blueprint $table): void {
            $table->dropUnique('agreement_open_obligation');
            $table->dropColumn('open_obligation_key');
        });
    }
};
