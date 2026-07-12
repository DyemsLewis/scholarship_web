<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->date('distribution_scheduled_for')->nullable()->after('outcome_at');
            $table->text('distribution_instructions')->nullable()->after('distribution_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'distribution_scheduled_for',
                'distribution_instructions',
            ]);
        });
    }
};
