<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->string('cover_image_source', 24)->nullable()->after('logo_path');
            $table->string('cover_image_key', 100)->nullable()->after('cover_image_source');
            $table->string('cover_image_path')->nullable()->after('cover_image_key');
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->string('cover_image_source', 24)->nullable()->after('image_path');
            $table->string('cover_image_key', 100)->nullable()->after('cover_image_source');
            $table->string('cover_image_path')->nullable()->after('cover_image_key');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['cover_image_source', 'cover_image_key', 'cover_image_path']);
        });

        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn(['cover_image_source', 'cover_image_key', 'cover_image_path']);
        });
    }
};
