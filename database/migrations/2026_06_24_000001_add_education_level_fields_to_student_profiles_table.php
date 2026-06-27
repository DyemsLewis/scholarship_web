<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('education_level')->nullable()->after('contact_number');
            $table->string('school_type')->nullable()->after('school');
            $table->string('learner_reference_number', 50)->nullable()->after('school_type');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'education_level',
                'school_type',
                'learner_reference_number',
            ]);
        });
    }
};
