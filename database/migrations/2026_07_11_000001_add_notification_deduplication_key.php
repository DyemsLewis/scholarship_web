<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portal_notifications', function (Blueprint $table): void {
            $table->string('deduplication_key')->nullable()->unique()->after('action_url');
        });
    }

    public function down(): void
    {
        Schema::table('portal_notifications', function (Blueprint $table): void {
            $table->dropUnique(['deduplication_key']);
            $table->dropColumn('deduplication_key');
        });
    }
};
