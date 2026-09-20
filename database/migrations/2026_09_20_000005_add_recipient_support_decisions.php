<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_support_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision')->index();
            $table->date('effective_on');
            $table->date('support_ends_on')->nullable();
            $table->date('next_review_on')->nullable();
            $table->text('reason')->nullable();
            $table->text('next_period_terms')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamps();

            $table->index(['scholarship_application_id', 'decided_at'], 'recipient_support_decision_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_support_decisions');
    }
};
