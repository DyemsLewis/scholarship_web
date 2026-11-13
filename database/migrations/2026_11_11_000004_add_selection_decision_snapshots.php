<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', fn (Blueprint $table) => $table->json('selection_decision_snapshot')->nullable());
        Schema::table('application_status_histories', fn (Blueprint $table) => $table->json('selection_evaluation_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('application_status_histories', fn (Blueprint $table) => $table->dropColumn('selection_evaluation_snapshot'));
        Schema::table('scholarship_applications', fn (Blueprint $table) => $table->dropColumn('selection_decision_snapshot'));
    }
};
