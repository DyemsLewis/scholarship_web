<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title');
            $table->dateTime('scheduled_at');
            $table->string('mode', 30)->default('onsite');
            $table->string('venue', 500)->nullable();
            $table->text('location_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('online_url')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->string('attendance_status', 30)->default('pending');
            $table->text('attendance_notes')->nullable();
            $table->timestamp('applicant_acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scholarship_application_id', 'type']);
            $table->index(['scholarship_application_id', 'status']);
            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_schedules');
    }
};
