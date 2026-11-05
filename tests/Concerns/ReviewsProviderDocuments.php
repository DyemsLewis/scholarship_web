<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\ProviderVerificationReviewService;

trait ReviewsProviderDocuments
{
    protected function acceptProviderDocuments(User $reviewer, User $provider): string
    {
        foreach ($provider->providerVerificationDocuments()->get() as $document) {
            $this->actingAs($reviewer)->patchJson('/admin/provider-verification-documents/'.$document->id.'/review', [
                'status' => 'approved', 'expected_revision' => $document->revision, 'compared' => true,
            ])->assertOk();
        }

        return app(ProviderVerificationReviewService::class)->token($provider->fresh());
    }
}
