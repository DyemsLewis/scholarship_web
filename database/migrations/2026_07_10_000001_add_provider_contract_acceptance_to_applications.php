<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->json('provider_contract_terms_snapshot')->nullable()->after('terms_version');
            $table->timestamp('provider_contract_terms_accepted_at')->nullable()->after('provider_contract_terms_snapshot');
            $table->string('provider_contract_terms_version', 80)->nullable()->after('provider_contract_terms_accepted_at');
            $table->string('provider_contract_acceptance_ip', 45)->nullable()->after('provider_contract_terms_version');
            $table->text('provider_contract_acceptance_user_agent')->nullable()->after('provider_contract_acceptance_ip');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_contract_terms_snapshot',
                'provider_contract_terms_accepted_at',
                'provider_contract_terms_version',
                'provider_contract_acceptance_ip',
                'provider_contract_acceptance_user_agent',
            ]);
        });
    }
};
