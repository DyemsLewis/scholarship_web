<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_reviews', function (Blueprint $table): void {
            $table->json('evidence_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_monitoring_reviews', function (Blueprint $table): void {
            $table->dropColumn('evidence_snapshot');
        });
    }
};
