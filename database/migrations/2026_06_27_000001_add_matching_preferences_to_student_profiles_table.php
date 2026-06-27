<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('household_size')->nullable()->after('income_bracket');
            $table->text('preferred_categories')->nullable()->after('household_size');
            $table->text('preferred_locations')->nullable()->after('preferred_categories');
            $table->string('willing_to_relocate', 30)->nullable()->after('preferred_locations');
            $table->text('support_needs')->nullable()->after('willing_to_relocate');
            $table->text('scholarship_goal')->nullable()->after('support_needs');
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'household_size',
                'preferred_categories',
                'preferred_locations',
                'willing_to_relocate',
                'support_needs',
                'scholarship_goal',
            ]);
        });
    }
};
