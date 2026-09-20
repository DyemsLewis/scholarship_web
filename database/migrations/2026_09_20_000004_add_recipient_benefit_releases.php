<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_benefit_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scholarship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->dateTime('release_at');
            $table->string('benefit_description');
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('release_method')->default('in_person');
            $table->string('location')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('requires_original_verification')->default(true);
            $table->string('status')->default('scheduled')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['scholarship_id', 'release_at']);
        });

        Schema::create('recipient_benefit_release_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_benefit_release_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scholarship_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('scheduled')->index();
            $table->boolean('originals_verified')->default(false);
            $table->text('notes')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_mime_type')->nullable();
            $table->unsignedBigInteger('receipt_size')->default(0);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['recipient_benefit_release_id', 'scholarship_application_id'],
                'recipient_benefit_release_record_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_benefit_release_records');
        Schema::dropIfExists('recipient_benefit_releases');
    }
};
