<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_monitoring_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('frequency')->default('semester');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('grace_period_days')->default(7);
            $table->boolean('allow_exception_requests')->default(true);
            $table->text('instructions')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('recipient_monitoring_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('recipient_monitoring_plan_id');
            $table->foreign('recipient_monitoring_plan_id', 'monitoring_req_plan_fk')
                ->references('id')
                ->on('recipient_monitoring_plans')
                ->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('evidence_description')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('requires_file')->default(true);
            $table->boolean('requires_original_verification')->default(false);
            $table->decimal('minimum_grade', 5, 2)->nullable();
            $table->string('grading_scale')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(
                ['recipient_monitoring_plan_id', 'sort_order'],
                'monitoring_req_plan_sort_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_requirements');
        Schema::dropIfExists('recipient_monitoring_plans');
    }
};
