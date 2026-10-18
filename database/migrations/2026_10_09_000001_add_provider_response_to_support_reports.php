<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->text('provider_response')->nullable()->after('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('support_reports', function (Blueprint $table): void {
            $table->dropColumn('provider_response');
        });
    }
};
