<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_monitoring_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_monitoring_case_id');
            $table->foreign('recipient_monitoring_case_id', 'rm_case_event_case_fk')
                ->references('id')
                ->on('recipient_monitoring_cases')
                ->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable();
            $table->foreign('actor_id', 'rm_case_event_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->string('action');
            $table->string('from_status');
            $table->string('to_status');
            $table->string('outcome')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['recipient_monitoring_case_id', 'created_at'],
                'rm_case_event_case_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_case_events');
    }
};
