<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('assessment_type')->default('qualifying_exam');
            $table->string('image_path')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->decimal('passing_score', 5, 2)->nullable();
            $table->string('delivery_mode')->default('provider_managed');
            $table->string('venue')->nullable();
            $table->text('instructions')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_assessments');
    }
};
