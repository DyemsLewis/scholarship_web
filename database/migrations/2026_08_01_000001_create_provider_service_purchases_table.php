<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_service_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('plan_code', 80);
            $table->string('plan_name');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('PHP');
            $table->string('status', 40)->default('pending');
            $table->string('fulfillment_status', 40)->default('queued');
            $table->string('reference_number', 80)->unique();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('payment_id')->nullable()->index();
            $table->string('payment_method', 80)->nullable();
            $table->boolean('livemode')->default(false);
            $table->timestamp('service_terms_accepted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('fulfillment_notes')->nullable();
            $table->string('failure_message')->nullable();
            $table->json('gateway_metadata')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'created_at']);
            $table->index(['status', 'fulfillment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_service_purchases');
    }
};
