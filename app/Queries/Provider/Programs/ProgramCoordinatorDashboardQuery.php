<?php

namespace App\Queries\Provider\Programs;

use App\Models\User;

final class ProgramCoordinatorDashboardQuery
{
    public function __construct(
        private readonly ProviderProgramScope $scope,
        private readonly ProgramDirectoryQuery $directory,
    ) {}

    public function for(User $actor): array
    {
        $base = $this->scope->query($actor);

        $priorities = (clone $base)
            ->whereIn('status', ['draft', 'rejected'])
            ->select($this->columns())
            ->orderByRaw("CASE WHEN status = 'rejected' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit(4)
            ->get();

        $deadlines = (clone $base)
            ->where('status', 'published')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', today())
            ->select($this->columns())
            ->orderBy('deadline')
            ->orderBy('title')
            ->limit(4)
            ->get();

        $recent = (clone $base)
            ->select($this->columns())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return [
            'summary' => $this->directory->summary($actor),
            'priorities' => $priorities,
            'deadlines' => $deadlines,
            'recent' => $recent,
        ];
    }

    private function columns(): array
    {
        return [
            'id',
            'provider_id',
            'title',
            'category',
            'program_cycle',
            'status',
            'deadline',
            'updated_at',
        ];
    }
}
