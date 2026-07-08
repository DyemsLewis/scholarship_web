<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->string('student_response_status', 20)->nullable()->index();
            $table->timestamp('student_responded_at')->nullable();
            $table->timestamp('student_response_terms_accepted_at')->nullable();
            $table->string('student_response_terms_version', 30)->nullable();
            $table->text('student_response_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarship_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'student_response_status',
                'student_responded_at',
                'student_response_terms_accepted_at',
                'student_response_terms_version',
                'student_response_note',
            ]);
        });
    }
};
