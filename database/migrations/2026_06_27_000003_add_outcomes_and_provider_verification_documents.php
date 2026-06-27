<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->decimal('awarded_amount', 12, 2)->nullable()->after('decision_reason');
            $table->text('outcome_notes')->nullable()->after('awarded_amount');
            $table->timestamp('outcome_at')->nullable()->after('outcome_notes');
        });

        Schema::create('provider_verification_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('document_type');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status')->default('submitted');
            $table->text('review_notes')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['provider_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_verification_documents');

        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'awarded_amount',
                'outcome_notes',
                'outcome_at',
            ]);
        });
    }
};
