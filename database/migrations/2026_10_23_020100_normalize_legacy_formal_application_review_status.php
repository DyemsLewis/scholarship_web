<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('application_stage_progresses')
            ->where('stage_key', 'formal_application')
            ->where('status', 'current')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('operational_status')
                    ->orWhere('operational_status', 'not_started');
            })
            ->update([
                'operational_status' => 'ready_for_review',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Legacy blank states cannot be reconstructed after normalization.
    }
};
