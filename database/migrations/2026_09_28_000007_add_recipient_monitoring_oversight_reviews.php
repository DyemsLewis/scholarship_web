<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_monitoring_oversight_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id');
            $table->foreign('scholarship_application_id', 'rm_oversight_application_fk')
                ->references('id')
                ->on('scholarship_applications')
                ->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('outcome')->index();
            $table->text('notes')->nullable();
            $table->json('flags_snapshot')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(
                ['scholarship_application_id', 'reviewed_at'],
                'rm_oversight_application_reviewed_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_monitoring_oversight_reviews');
    }
};
