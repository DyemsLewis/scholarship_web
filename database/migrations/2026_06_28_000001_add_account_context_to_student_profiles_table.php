<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('account_managed_by', 50)->nullable()->after('contact_number');
            $table->string('guardian_relationship', 100)->nullable()->after('guardian_name');
            $table->string('guardian_email')->nullable()->after('guardian_contact');
            $table->boolean('guardian_is_account_owner')->default(false)->after('guardian_email');
        });

        DB::table('student_profiles')
            ->whereNull('account_managed_by')
            ->update(['account_managed_by' => 'learner']);

        DB::table('student_profiles')
            ->whereNull('guardian_relationship')
            ->whereNotNull('guardian_name')
            ->update(['guardian_relationship' => 'Parent / guardian']);
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'account_managed_by',
                'guardian_relationship',
                'guardian_email',
                'guardian_is_account_owner',
            ]);
        });
    }
};
