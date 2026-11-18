<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('onboarding_policy')->nullable();
        });

        Schema::create('recipient_onboarding_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_application_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('policy_snapshot');
            $table->string('policy_version', 100);
            $table->string('offer_version', 100);
            $table->string('status', 24);
            $table->unsignedInteger('revision')->default(1);
            $table->text('instructions')->nullable();
            $table->foreignId('instructions_published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('instructions_published_at')->nullable();
            $table->date('completed_on')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmation_note')->nullable();
            $table->timestamps();
        });

        Schema::create('recipient_onboarding_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('onboarding_record_id')->constrained('recipient_onboarding_records')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 40);
            $table->unsignedInteger('revision');
            $table->json('details');
            $table->timestamp('recorded_at');
            $table->unique(['onboarding_record_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_onboarding_events');
        Schema::dropIfExists('recipient_onboarding_records');
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('onboarding_policy');
        });
    }
};
