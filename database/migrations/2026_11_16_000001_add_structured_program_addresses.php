<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('location_address_details')->nullable();
            $table->json('handoff_address_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn(['location_address_details', 'handoff_address_details']));
    }
};
