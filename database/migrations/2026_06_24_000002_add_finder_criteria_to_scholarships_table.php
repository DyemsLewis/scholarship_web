<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->text('eligible_education_levels')->nullable()->after('eligibility');
            $table->text('eligible_school_types')->nullable()->after('eligible_courses');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn([
                'eligible_education_levels',
                'eligible_school_types',
            ]);
        });
    }
};
