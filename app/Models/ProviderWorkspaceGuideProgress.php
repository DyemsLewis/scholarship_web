<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWorkspaceGuideProgress extends Model
{
    protected $table = 'provider_workspace_guide_progress';

    protected $fillable = ['user_id', 'workspace_key', 'guide_version', 'status'];

    protected function casts(): array
    {
        return ['guide_version' => 'integer'];
    }
}
