<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_stage_progresses')
            ->where('stage_key', 'formal_application')
            ->where('status', 'current')
            ->where('operational_status', 'waiting_for_schedule')
            ->update([
                'operational_status' => 'ready_for_review',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // This data correction is intentionally not reversed because appointments remain optional.
    }
};
