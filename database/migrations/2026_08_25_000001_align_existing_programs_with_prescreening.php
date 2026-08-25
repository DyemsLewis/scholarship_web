<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scholarships')) {
            return;
        }

        DB::table('scholarships')
            ->select(['id', 'selection_stages'])
            ->orderBy('id')
            ->each(function (object $scholarship): void {
                $stages = json_decode((string) $scholarship->selection_stages, true);

                if (! is_array($stages) || ! in_array('distribution', $stages, true)) {
                    return;
                }

                $stages = array_values(array_filter(
                    $stages,
                    fn (mixed $stage): bool => $stage !== 'distribution',
                ));

                DB::table('scholarships')
                    ->where('id', $scholarship->id)
                    ->update([
                        'selection_stages' => json_encode($stages ?: ['screening']),
                        'updated_at' => now(),
                    ]);
            });

        if (Schema::hasTable('scholarship_events')) {
            DB::table('scholarship_events')->where('type', 'distribution')->delete();
        }

        if (Schema::hasTable('application_schedules')) {
            DB::table('application_schedules')->where('type', 'distribution')->delete();
        }

        if (Schema::hasTable('scholarship_applications')) {
            DB::table('scholarship_applications')
                ->whereIn('status', ['awarded', 'distribution_scheduled', 'disbursed'])
                ->update([
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Removed distribution schedules cannot be reconstructed safely.
    }
};
