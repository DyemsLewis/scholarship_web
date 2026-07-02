<?php

namespace App\Http\Controllers;

use App\Models\ApplicationDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ApplicationDocumentController extends Controller
{
    public function view(Request $request, ApplicationDocument $document)
    {
        $document->load(['application.scholarship']);
        $user = $request->user();

        abort_unless($user, 403);

        $canView = $user->isAdmin()
            || $document->application?->applicant_id === $user->id
            || $document->application?->scholarship?->provider_id === $user->id;

        abort_unless($canView, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }

    public function download(Request $request, ApplicationDocument $document)
    {
        $document->load(['application.scholarship']);
        $user = $request->user();

        abort_unless($user, 403);

        $canDownload = $user->isAdmin()
            || $document->application?->applicant_id === $user->id
            || $document->application?->scholarship?->provider_id === $user->id;

        abort_unless($canDownload, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }
}
