<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->dateTime('handoff_at')->nullable();
            $table->string('handoff_location')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_monitoring_cycles', function (Blueprint $table): void {
            $table->dropColumn(['handoff_at', 'handoff_location']);
        });
    }
};
