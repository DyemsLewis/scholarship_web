<?php

namespace Tests\Support;

use App\Models\Scholarship;
use App\Models\User;
use App\Services\AdminReviewWorkflowService;

final class AdminReviewSnapshots
{
    public static function applicant(int $id): string
    {
        return app(AdminReviewWorkflowService::class)->token('applicant', User::findOrFail($id));
    }

    public static function program(int $id): string
    {
        return app(AdminReviewWorkflowService::class)->token('program', Scholarship::findOrFail($id));
    }
}
