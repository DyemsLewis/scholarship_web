<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_monitoring_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_cycle_id');
            $table->foreign('recipient_monitoring_cycle_id', 'rm_adjustment_cycle_fk')
                ->references('id')->on('recipient_monitoring_cycles')->cascadeOnDelete();
            $table->foreignId('recipient_monitoring_cycle_requirement_id')->nullable();
            $table->foreign('recipient_monitoring_cycle_requirement_id', 'rm_adjustment_requirement_fk')
                ->references('id')->on('recipient_monitoring_cycle_requirements')->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rm_adjustment_application_fk')
                ->references('id')->on('scholarship_applications')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->string('request_type')->index();
            $table->string('reason_category')->index();
            $table->text('explanation');
            $table->date('requested_due_at')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime_type')->nullable();
            $table->unsignedBigInteger('attachment_size')->default(0);
            $table->string('status')->default('pending')->index();
            $table->text('decision_notes')->nullable();
            $table->date('approved_due_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(
                ['scholarship_application_id', 'recipient_monitoring_cycle_id', 'status'],
                'rm_adjustment_application_cycle_status_idx'
            );
        });

        Schema::create('recipient_monitoring_interventions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_cycle_id');
            $table->foreign('recipient_monitoring_cycle_id', 'rm_intervention_cycle_fk')
                ->references('id')->on('recipient_monitoring_cycles')->cascadeOnDelete();
            $table->foreignId('recipient_monitoring_cycle_requirement_id')->nullable();
            $table->foreign('recipient_monitoring_cycle_requirement_id', 'rm_intervention_requirement_fk')
                ->references('id')->on('recipient_monitoring_cycle_requirements')->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rm_intervention_application_fk')
                ->references('id')->on('scholarship_applications')->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->index();
            $table->text('summary');
            $table->text('action_required')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->string('status')->default('open')->index();
            $table->text('completion_notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['scholarship_application_id', 'recipient_monitoring_cycle_id', 'status'],
                'rm_intervention_application_cycle_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_interventions');
        Schema::dropIfExists('recipient_monitoring_adjustment_requests');
    }
};
