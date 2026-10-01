<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->text('mission')->nullable()->after('provider_description');
            $table->unsignedSmallInteger('year_established')->nullable()->after('mission');
            $table->string('service_area', 500)->nullable()->after('year_established');
            $table->string('contact_department')->nullable()->after('provider_contact_number');
            $table->string('office_hours')->nullable()->after('contact_department');
            $table->string('legal_name')->nullable()->after('office_hours');
            $table->string('registration_authority')->nullable()->after('legal_name');
            $table->string('registration_number')->nullable()->after('registration_authority');
            $table->date('registration_date')->nullable()->after('registration_number');
            $table->string('representative_position')->nullable()->after('contact_number');
        });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'mission',
                'year_established',
                'service_area',
                'contact_department',
                'office_hours',
                'legal_name',
                'registration_authority',
                'registration_number',
                'registration_date',
                'representative_position',
            ]);
        });
    }
};
