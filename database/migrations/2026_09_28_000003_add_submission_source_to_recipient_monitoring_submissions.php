<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->string('submission_source')
                ->default('applicant_upload')
                ->after('applicant_note')
                ->index();
            $table->string('original_name')->nullable()->change();
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('recipient_monitoring_submissions')
            ->where('submission_source', 'provider_record')
            ->delete();

        Schema::table('recipient_monitoring_submissions', function (Blueprint $table): void {
            $table->dropIndex(['submission_source']);
            $table->dropColumn('submission_source');
            $table->string('original_name')->nullable(false)->change();
            $table->string('path')->nullable(false)->change();
        });
    }
};
