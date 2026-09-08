<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->json('eligibility_conditions')->nullable()->after('eligibility');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn('eligibility_conditions');
        });
    }
};
