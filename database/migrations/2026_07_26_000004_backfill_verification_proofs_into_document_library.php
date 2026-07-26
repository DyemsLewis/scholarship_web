<?php

use App\Models\ApplicantVerificationDocument;
use App\Models\StudentDocument;
use App\Services\ApplicantDocumentLibraryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $library = app(ApplicantDocumentLibraryService::class);

        ApplicantVerificationDocument::query()
            ->with('applicant')
            ->chunkById(100, function ($documents) use ($library): void {
                foreach ($documents as $document) {
                    if ($document->applicant) {
                        $library->ensureVerificationCopy($document->applicant, $document);
                    }
                }
            });
    }

    public function down(): void
    {
        StudentDocument::query()
            ->where('path', 'like', 'student-documents/%/verification-%')
            ->each(function (StudentDocument $document): void {
                Storage::disk('local')->delete($document->path);
                $document->delete();
            });
    }
};
