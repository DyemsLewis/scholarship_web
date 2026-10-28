<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->decimal('handoff_latitude', 10, 7)->nullable();
            $table->decimal('handoff_longitude', 10, 7)->nullable();
        });
        Schema::table('recipient_benefit_releases', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recipient_benefit_releases', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn(['handoff_latitude', 'handoff_longitude']));
        Schema::table('provider_profiles', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
    }
};
