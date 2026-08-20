<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('profile_photo_path')->nullable();
            $table->string('profile_photo_original_name')->nullable();
            $table->string('profile_photo_mime_type', 100)->nullable();
            $table->unsignedBigInteger('profile_photo_size')->nullable();
            $table->timestamp('profile_photo_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'profile_photo_path',
                'profile_photo_original_name',
                'profile_photo_mime_type',
                'profile_photo_size',
                'profile_photo_updated_at',
            ]);
        });
    }
};
