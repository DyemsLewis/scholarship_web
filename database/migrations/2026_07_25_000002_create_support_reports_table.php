<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('applicant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('scholarship_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_role', 20);
            $table->string('category', 30);
            $table->string('subject', 150);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_role', 'status', 'created_at']);
            $table->index(['provider_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_reports');
    }
};
