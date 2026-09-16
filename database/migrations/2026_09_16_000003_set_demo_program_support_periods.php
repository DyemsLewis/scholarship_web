<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEMO_PROGRAMS = [
        'Tulay Aral Senior High Support Grant',
        'Tulay Aral College Starter Grant',
        'Bukas Kinabukasan School Essentials Grant',
        'Bukas Kinabukasan STEM Pathways Grant',
    ];

    public function up(): void
    {
        DB::table('scholarships')
            ->whereIn('title', self::DEMO_PROGRAMS)
            ->orderBy('id')
            ->get()
            ->each(function (object $program): void {
                if (blank($program->deadline)) {
                    return;
                }

                $deadline = Carbon::parse($program->deadline)->startOfDay();
                $schoolYearEnd = Carbon::create($deadline->year + 1, 6, 30)->startOfDay();

                DB::table('scholarships')->where('id', $program->id)->update([
                    'support_starts_at' => $program->support_starts_at
                        ?: $deadline->copy()->addDays(30)->toDateString(),
                    'support_ends_at' => $program->support_ends_at
                        ?: $schoolYearEnd->toDateString(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('scholarships')
            ->whereIn('title', self::DEMO_PROGRAMS)
            ->update([
                'support_starts_at' => null,
                'support_ends_at' => null,
            ]);
    }
};
