<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->string('meeting_join_url', 2048)->nullable()->after('meeting_admin_note');
        });
    }

    public function down(): void
    {
        Schema::table('provider_service_purchases', function (Blueprint $table): void {
            $table->dropColumn('meeting_join_url');
        });
    }
};
