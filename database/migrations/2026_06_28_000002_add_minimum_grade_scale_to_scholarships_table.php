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
            $table->string('minimum_grade_scale')->nullable()->after('minimum_gwa');
        });

        DB::table('scholarships')
            ->whereNotNull('minimum_gwa')
            ->update([
                'minimum_grade_scale' => DB::raw("CASE WHEN minimum_gwa <= 5 THEN 'grade_point' ELSE 'percentage' END"),
            ]);
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('minimum_grade_scale');
        });
    }
};
