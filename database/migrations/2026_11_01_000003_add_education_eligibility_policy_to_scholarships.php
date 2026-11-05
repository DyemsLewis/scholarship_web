<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', fn (Blueprint $table) => $table->json('education_eligibility_policy')->nullable());
    }

    public function down(): void
    {
        Schema::table('scholarships', fn (Blueprint $table) => $table->dropColumn('education_eligibility_policy'));
    }
};
