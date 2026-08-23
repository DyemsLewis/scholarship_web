<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->text('post_qualification_requirements')->nullable()->after('optional_requirements');
            $table->string('handoff_mode', 30)->nullable()->after('post_qualification_requirements');
            $table->text('handoff_instructions')->nullable()->after('handoff_mode');
            $table->date('handoff_deadline')->nullable()->after('handoff_instructions');
            $table->string('handoff_location_name')->nullable()->after('handoff_deadline');
            $table->string('handoff_location_address', 500)->nullable()->after('handoff_location_name');
            $table->string('handoff_url', 2048)->nullable()->after('handoff_location_address');
        });

        DB::table('scholarships')->update([
            'post_qualification_requirements' => implode("\n", [
                'Original copies of submitted school records',
                'Valid school or government ID',
                'Provider-specific documents requested after qualification',
            ]),
            'handoff_mode' => 'provider_contact',
            'handoff_instructions' => 'The provider will contact qualified applicants with the formal application schedule and confirm which original documents to bring.',
        ]);
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn([
                'post_qualification_requirements',
                'handoff_mode',
                'handoff_instructions',
                'handoff_deadline',
                'handoff_location_name',
                'handoff_location_address',
                'handoff_url',
            ]);
        });
    }
};
