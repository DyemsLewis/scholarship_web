<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->string('program_cycle', 100)->nullable()->after('category');
            $table->date('application_opens_at')->nullable()->after('contact_number');
            $table->date('expected_results_at')->nullable()->after('application_opens_at');
            $table->string('official_program_url', 2048)->nullable()->after('expected_results_at');
            $table->string('contact_person', 150)->nullable()->after('official_program_url');
            $table->string('contact_department', 150)->nullable()->after('contact_person');
        });

        Schema::table('scholarship_benefits', function (Blueprint $table): void {
            $table->string('duration', 100)->nullable()->after('frequency');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_benefits', function (Blueprint $table): void {
            $table->dropColumn('duration');
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn([
                'program_cycle',
                'application_opens_at',
                'expected_results_at',
                'official_program_url',
                'contact_person',
                'contact_department',
            ]);
        });
    }
};
