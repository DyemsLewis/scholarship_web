<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->unsignedInteger('slots_available')->nullable()->after('minimum_gwa');
            $table->string('application_mode', 50)->nullable()->after('slots_available');
            $table->text('renewal_policy')->nullable()->after('application_mode');
            $table->string('contact_email')->nullable()->after('renewal_policy');
            $table->string('contact_number', 30)->nullable()->after('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('scholarships', function (Blueprint $table): void {
            $table->dropColumn([
                'slots_available',
                'application_mode',
                'renewal_policy',
                'contact_email',
                'contact_number',
            ]);
        });
    }
};
