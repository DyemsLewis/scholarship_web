<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_account_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('task', 40);
            $table->text('instruction');
            $table->string('status', 20)->default('pending');
            $table->text('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['support_report_id', 'status']);
            $table->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_account_handoffs');
    }
};
