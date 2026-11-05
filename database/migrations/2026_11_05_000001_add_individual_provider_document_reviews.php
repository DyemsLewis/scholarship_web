<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_verification_documents', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provider_verification_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['revision', 'reviewed_at']);
        });
    }
};
