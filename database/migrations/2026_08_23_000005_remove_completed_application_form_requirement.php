<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scholarships')
            ->select(['id', 'requirements', 'optional_requirements'])
            ->orderBy('id')
            ->get()
            ->each(function (object $scholarship): void {
                DB::table('scholarships')
                    ->where('id', $scholarship->id)
                    ->update([
                        'requirements' => $this->withoutApplicationForm($scholarship->requirements),
                        'optional_requirements' => $this->withoutApplicationForm($scholarship->optional_requirements),
                    ]);
            });
    }

    public function down(): void
    {
        // Removed requirements cannot be restored reliably to their original programs.
    }

    private function withoutApplicationForm(?string $requirements): string
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $requirements))
            ->map(fn (string $requirement): string => trim($requirement))
            ->filter()
            ->reject(fn (string $requirement): bool => strtolower($requirement) === 'completed application form')
            ->unique(fn (string $requirement): string => strtolower($requirement))
            ->implode("\n");
    }
};
