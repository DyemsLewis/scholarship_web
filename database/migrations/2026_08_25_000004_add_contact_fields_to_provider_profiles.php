<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->string('provider_contact_email')->nullable();
            $table->string('provider_contact_number', 30)->nullable();
        });

        DB::table('provider_profiles')
            ->orderBy('id')
            ->get(['id', 'user_id', 'contact_number'])
            ->each(function (object $profile): void {
                DB::table('provider_profiles')
                    ->where('id', $profile->id)
                    ->update([
                        'provider_contact_email' => DB::table('users')
                            ->where('id', $profile->user_id)
                            ->value('email'),
                        'provider_contact_number' => $profile->contact_number,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['provider_contact_email', 'provider_contact_number']);
        });
    }
};
