<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('completed_qualification', 50)->nullable();
            $table->string('completed_qualification_school')->nullable();
            $table->unsignedSmallInteger('completed_qualification_year')->nullable();
            $table->string('completed_qualification_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'completed_qualification', 'completed_qualification_school',
                'completed_qualification_year', 'completed_qualification_details',
            ]);
        });
    }
};
