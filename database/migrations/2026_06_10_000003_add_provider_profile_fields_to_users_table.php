<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('provider_name')->nullable()->after('contact_number');
            $table->string('provider_type')->nullable()->after('provider_name');
            $table->string('provider_website')->nullable()->after('provider_type');
            $table->string('provider_address', 500)->nullable()->after('provider_website');
            $table->text('provider_description')->nullable()->after('provider_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'provider_name',
                'provider_type',
                'provider_website',
                'provider_address',
                'provider_description',
            ]);
        });
    }
};
