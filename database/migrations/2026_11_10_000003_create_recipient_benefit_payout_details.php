<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_benefit_payout_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('release_record_id')->unique()
                ->constrained('recipient_benefit_release_records')->cascadeOnDelete();
            $table->string('method', 24);
            $table->text('destination');
            $table->timestamp('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_benefit_payout_details');
    }
};
