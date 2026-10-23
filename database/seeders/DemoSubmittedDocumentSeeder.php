<?php

namespace Database\Seeders;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use Database\Seeders\Support\DemoSubmittedDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSubmittedDocumentSeeder extends Seeder
{
    public function run(): void
    {
        $profileDocuments = 0;
        $applicationDocuments = 0;

        ApplicantVerificationDocument::query()
            ->orderBy('id')
            ->each(function (ApplicantVerificationDocument $document) use (&$profileDocuments): void {
                if (! DemoSubmittedDocument::exists($document->document_type)) {
                    return;
                }

                $asset = DemoSubmittedDocument::get($document->document_type, $document->applicant_id);
                $path = "demo-showcase/applicant-evidence/{$document->applicant_id}/{$document->document_type}.{$asset['extension']}";

                $this->replaceFile($document->path, $path, $asset['contents']);
                $document->update([
                    'original_name' => $asset['original_name'],
                    'path' => $path,
                    'mime_type' => $asset['mime_type'],
                    'size' => $asset['size'],
                ]);
                $profileDocuments++;
            });

        ApplicationDocument::query()
            ->with('application:id,applicant_id')
            ->orderBy('id')
            ->each(function (ApplicationDocument $document) use (&$applicationDocuments): void {
                if (! DemoSubmittedDocument::exists($document->document_name)) {
                    return;
                }

                $variant = $document->application?->applicant_id ?? $document->scholarship_application_id;
                $asset = DemoSubmittedDocument::get($document->document_name, $variant);
                $filename = Str::slug($document->document_name).'.'.$asset['extension'];
                $path = "demo-showcase/application-documents/{$document->scholarship_application_id}/{$filename}";

                $this->replaceFile($document->path, $path, $asset['contents']);
                $document->update([
                    'original_name' => $asset['original_name'],
                    'path' => $path,
                    'mime_type' => $asset['mime_type'],
                    'size' => $asset['size'],
                ]);
                $applicationDocuments++;
            });

        $this->command?->info("Replaced {$profileDocuments} profile evidence files and {$applicationDocuments} application files with sample documents.");
    }

    private function replaceFile(?string $oldPath, string $newPath, string $contents): void
    {
        Storage::disk('local')->put($newPath, $contents);

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
