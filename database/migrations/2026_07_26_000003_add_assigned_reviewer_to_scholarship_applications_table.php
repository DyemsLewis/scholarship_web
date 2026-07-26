<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->foreignId('assigned_reviewer_id')
                ->nullable()
                ->after('reviewed_by')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_reviewer_id');
        });
    }
};
