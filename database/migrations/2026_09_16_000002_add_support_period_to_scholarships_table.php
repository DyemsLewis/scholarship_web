<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->date('support_starts_at')->nullable()->after('expected_results_at');
            $table->date('support_ends_at')->nullable()->after('support_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn(['support_starts_at', 'support_ends_at']);
        });
    }
};
