<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_finance_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_service_purchase_id')->constrained()->cascadeOnDelete();
            $table->string('state', 24);
            $table->text('request_note');
            $table->text('billing_response')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('source_fingerprint', 64);
            $table->string('checked_fingerprint', 64)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['provider_service_purchase_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_finance_reviews');
    }
};
