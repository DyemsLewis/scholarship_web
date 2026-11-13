<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_benefit_release_records', function (Blueprint $table): void {
            $table->date('transfer_sent_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_benefit_release_records', function (Blueprint $table): void {
            $table->dropColumn('transfer_sent_on');
        });
    }
};
