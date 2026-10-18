<?php

namespace App\Queries\Provider\Programs;

use App\Queries\Provider\ProviderWorkspaceScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ProviderProgramScope
{
    public function __construct(private readonly ProviderWorkspaceScope $workspaceScope) {}

    public function query(User $actor): Builder
    {
        return $this->workspaceScope->programs($actor);
    }
}
