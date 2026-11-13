<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipient_monitoring_cases', function (Blueprint $table): void {
            $table->dateTime('appointment_at')->nullable();
            $table->string('appointment_location')->nullable();
            $table->string('attendance_status', 20)->nullable();
        });

        // Existing in-person results already represent a document handoff.
        DB::table('recipient_monitoring_cases')
            ->whereIn('recipient_monitoring_cycle_id', DB::table('recipient_monitoring_cycles')->whereNotNull('handoff_at')->select('id'))
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('recipient_monitoring_submissions')
                    ->whereColumn('recipient_monitoring_submissions.recipient_monitoring_cycle_id', 'recipient_monitoring_cases.recipient_monitoring_cycle_id')
                    ->whereColumn('recipient_monitoring_submissions.scholarship_application_id', 'recipient_monitoring_cases.scholarship_application_id')
                    ->where('recipient_monitoring_submissions.submission_source', 'provider_record')
                    ->whereIn('recipient_monitoring_submissions.review_status', ['met', 'not_met']);
            })
            ->update(['attendance_status' => 'attended']);
    }

    public function down(): void
    {
        Schema::table('recipient_monitoring_cases', function (Blueprint $table): void {
            $table->dropColumn(['appointment_at', 'appointment_location', 'attendance_status']);
        });
    }
};
