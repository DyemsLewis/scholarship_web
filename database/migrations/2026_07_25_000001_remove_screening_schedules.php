<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_schedules')->where('type', 'screening')->delete();
        DB::table('scholarship_events')->where('type', 'screening')->delete();
    }

    public function down(): void
    {
        // Screening is an internal review step, so removed schedules are not restored.
    }
};
