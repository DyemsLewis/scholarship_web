<?php

namespace App\Queries\Provider\Programs;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ProgramDirectoryQuery
{
    public const PER_PAGE = 10;

    public function __construct(private readonly ProviderProgramScope $scope) {}

    public function paginate(User $actor, string $filter, string $search, string $sort): LengthAwarePaginator
    {
        $query = $this->scope->query($actor)
            ->select([
                'id',
                'provider_id',
                'title',
                'category',
                'program_cycle',
                'status',
                'application_limit',
                'slots_available',
                'application_opens_at',
                'deadline',
                'updated_at',
            ])
            ->withCount('applications');

        $this->applyFilter($query, $filter);

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('program_cycle', 'like', "%{$search}%");
            });
        }

        match ($sort) {
            'deadline' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
                ->orderBy('deadline')
                ->orderBy('title'),
            'title' => $query->orderBy('title')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    public function summary(User $actor): array
    {
        $summary = $this->scope->query($actor)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('draft', 'rejected') THEN 1 ELSE 0 END) as needs_action")
            ->selectRaw("SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as under_review")
            ->selectRaw("SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published")
            ->first();

        return [
            'total' => (int) ($summary?->total ?? 0),
            'needs_action' => (int) ($summary?->needs_action ?? 0),
            'under_review' => (int) ($summary?->under_review ?? 0),
            'published' => (int) ($summary?->published ?? 0),
        ];
    }

    private function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'drafts' => $query->whereIn('status', ['draft', 'rejected']),
            'review' => $query->where('status', 'pending_review'),
            'published' => $query->where('status', 'published'),
            'closed' => $query->where('status', 'closed'),
            default => null,
        };
    }
}
