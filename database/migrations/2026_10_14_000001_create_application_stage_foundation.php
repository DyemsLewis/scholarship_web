<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_stage_progresses', function (Blueprint $table): void {
            $table->string('operational_status', 40)->nullable()->after('status');
            $table->string('outcome_key', 50)->nullable()->after('result');
            $table->boolean('proof_required')->default(false)->after('notes');
            $table->foreignId('proof_verified_by')->nullable()->after('decided_by');
            $table->foreign('proof_verified_by', 'app_stage_proof_verifier_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->timestamp('proof_verified_at')->nullable()->after('proof_verified_by');
            $table->index(['stage_key', 'operational_status'], 'app_stage_operational_queue_idx');
        });

        Schema::create('application_stage_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->string('stage_key', 40);
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('mode', 30);
            $table->string('venue', 500)->nullable();
            $table->text('location_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('meeting_url')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('draft');
            $table->boolean('proof_required')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['scholarship_id', 'stage_key', 'status'],
                'app_stage_session_program_queue_idx',
            );
            $table->index(['starts_at', 'status'], 'app_stage_session_schedule_idx');
        });

        Schema::create('application_stage_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_stage_session_id');
            $table->foreign('application_stage_session_id', 'app_stage_assignment_session_fk')
                ->references('id')->on('application_stage_sessions')->cascadeOnDelete();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'app_stage_assignment_application_fk')
                ->references('id')->on('scholarship_applications')->cascadeOnDelete();
            $table->foreignId('previous_assignment_id')->nullable();
            $table->foreign('previous_assignment_id', 'app_stage_assignment_previous_fk')
                ->references('id')->on('application_stage_assignments')->nullOnDelete();
            $table->string('status', 30)->default('assigned');
            $table->text('notes')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['application_stage_session_id', 'scholarship_application_id'],
                'app_stage_assignment_unique',
            );
            $table->index(
                ['scholarship_application_id', 'status'],
                'app_stage_assignment_application_status_idx',
            );
        });

        Schema::create('application_stage_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_stage_assignment_id');
            $table->foreign('application_stage_assignment_id', 'app_stage_attendance_assignment_fk')
                ->references('id')->on('application_stage_assignments')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique('application_stage_assignment_id', 'app_stage_attendance_assignment_unique');
            $table->index(['status', 'recorded_at'], 'app_stage_attendance_status_idx');
        });

        Schema::create('application_stage_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id')->nullable();
            $table->foreign('scholarship_application_id', 'app_stage_evidence_application_fk')
                ->references('id')->on('scholarship_applications')->cascadeOnDelete();
            $table->foreignId('application_stage_session_id')->nullable();
            $table->foreign('application_stage_session_id', 'app_stage_evidence_session_fk')
                ->references('id')->on('application_stage_sessions')->cascadeOnDelete();
            $table->foreignId('application_stage_assignment_id')->nullable();
            $table->foreign('application_stage_assignment_id', 'app_stage_evidence_assignment_fk')
                ->references('id')->on('application_stage_assignments')->cascadeOnDelete();
            $table->foreignId('supersedes_evidence_id')->nullable();
            $table->foreign('supersedes_evidence_id', 'app_stage_evidence_supersedes_fk')
                ->references('id')->on('application_stage_evidence')->nullOnDelete();
            $table->string('stage_key', 40);
            $table->string('scope', 30);
            $table->string('category', 50)->default('other');
            $table->string('original_name');
            $table->string('path');
            $table->string('disk', 30)->default('local');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size')->default(0);
            $table->date('event_date')->nullable();
            $table->text('note')->nullable();
            $table->string('visibility', 30)->default('provider_private');
            $table->boolean('is_current')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();

            $table->unique('path', 'app_stage_evidence_path_unique');
            $table->index(
                ['scholarship_id', 'stage_key', 'scope'],
                'app_stage_evidence_program_stage_idx',
            );
            $table->index(
                ['scholarship_application_id', 'stage_key', 'is_current'],
                'app_stage_evidence_application_idx',
            );
            $table->index(
                ['application_stage_session_id', 'is_current'],
                'app_stage_evidence_session_idx',
            );
        });

        Schema::create('application_stage_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id')->nullable();
            $table->foreign('scholarship_application_id', 'app_stage_audit_application_fk')
                ->references('id')->on('scholarship_applications')->cascadeOnDelete();
            $table->foreignId('application_stage_session_id')->nullable();
            $table->foreign('application_stage_session_id', 'app_stage_audit_session_fk')
                ->references('id')->on('application_stage_sessions')->nullOnDelete();
            $table->foreignId('application_stage_assignment_id')->nullable();
            $table->foreign('application_stage_assignment_id', 'app_stage_audit_assignment_fk')
                ->references('id')->on('application_stage_assignments')->nullOnDelete();
            $table->string('stage_key', 40);
            $table->string('event_type', 80);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(
                ['scholarship_id', 'stage_key', 'occurred_at'],
                'app_stage_audit_program_stage_idx',
            );
            $table->index(
                ['scholarship_application_id', 'occurred_at'],
                'app_stage_audit_application_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_stage_audit_events');
        Schema::dropIfExists('application_stage_evidence');
        Schema::dropIfExists('application_stage_attendances');
        Schema::dropIfExists('application_stage_assignments');
        Schema::dropIfExists('application_stage_sessions');

        Schema::table('application_stage_progresses', function (Blueprint $table): void {
            $table->dropForeign('app_stage_proof_verifier_fk');
            $table->dropIndex('app_stage_operational_queue_idx');
            $table->dropColumn([
                'operational_status',
                'outcome_key',
                'proof_required',
                'proof_verified_by',
                'proof_verified_at',
            ]);
        });
    }
};
