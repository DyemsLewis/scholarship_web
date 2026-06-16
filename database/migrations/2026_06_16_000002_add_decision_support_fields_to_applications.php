<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->decimal('dss_score', 5, 2)->nullable()->after('decision_reason');
            $table->string('dss_recommendation')->nullable()->after('dss_score');
            $table->json('dss_breakdown')->nullable()->after('dss_recommendation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table) {
            $table->dropColumn(['dss_score', 'dss_recommendation', 'dss_breakdown']);
        });
    }
};
