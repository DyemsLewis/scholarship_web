<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->text('achievements')->nullable()->after('scholarship_goal');
            $table->text('activities_and_responsibilities')->nullable()->after('achievements');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'achievements',
                'activities_and_responsibilities',
            ]);
        });
    }
};
