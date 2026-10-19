<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_service_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained('scholarships')->nullOnDelete();
            $table->string('plan_code', 80);
            $table->string('plan_name');
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('PHP');
            $table->string('status', 40)->default('ready_for_review');
            $table->json('answers');
            $table->text('request_summary');
            $table->text('requested_outcome');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status'], 'provider_service_requests_provider_status_index');
            $table->index(['plan_code', 'status'], 'provider_service_requests_plan_status_index');
        });

        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->foreignId('service_request_id')
                ->nullable()
                ->unique()
                ->after('created_by')
                ->constrained('provider_service_requests')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_request_id');
        });

        Schema::dropIfExists('provider_service_requests');
    }
};
