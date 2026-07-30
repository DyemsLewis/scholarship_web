<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApplicationDocumentController extends Controller
{
    public function view(Request $request, ApplicationDocument $document)
    {
        $document->load(['application.scholarship']);
        $user = $request->user();

        abort_unless($user, 403);

        abort_unless($this->canAccessDocument($user, $document), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Request $request, ApplicationDocument $document)
    {
        $document->load(['application.scholarship']);
        $user = $request->user();

        abort_unless($user, 403);

        abort_unless($this->canAccessDocument($user, $document), 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function canAccessDocument(User $user, ApplicationDocument $document): bool
    {
        if ($document->application?->applicant_id === $user->id) {
            return true;
        }

        if ($user->isAdmin()) {
            return $user->hasPortalPermission('manage_reviews');
        }

        return $user->isProvider()
            && $user->hasPortalPermission('review_applications')
            && $document->application?->scholarship?->provider_id === $user->providerOrganizationId();
    }
}
