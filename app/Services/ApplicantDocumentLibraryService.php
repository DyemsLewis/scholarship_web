<?php

namespace App\Services;

use App\Models\ApplicantVerificationDocument;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ApplicantDocumentLibraryService
{
    private const VERIFICATION_DOCUMENT_NAMES = [
        'school_id' => 'School ID',
        'government_id' => 'Government-issued ID',
        'enrollment_certificate' => 'Certificate of enrollment',
        'academic_record' => 'Latest report card or grades',
        'birth_certificate' => 'Birth certificate',
        'other' => 'Other identity or school proof',
    ];

    public function ensureVerificationCopy(User $user, ApplicantVerificationDocument $verificationDocument): ?StudentDocument
    {
        $documentName = self::VERIFICATION_DOCUMENT_NAMES[$verificationDocument->document_type] ?? null;

        if (! $documentName || $verificationDocument->applicant_id !== $user->id) {
            return null;
        }

        $existing = StudentDocument::query()
            ->where('user_id', $user->id)
            ->where('document_name', $documentName)
            ->first();

        if ($existing) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($verificationDocument->path)) {
            return null;
        }

        $extension = pathinfo($verificationDocument->path, PATHINFO_EXTENSION);
        $targetPath = "student-documents/{$user->id}/verification-{$verificationDocument->id}-".(string) Str::uuid()
            .($extension !== '' ? ".{$extension}" : '');

        if (! $disk->copy($verificationDocument->path, $targetPath)) {
            throw new RuntimeException('Unable to copy the verification proof into the prepared-document library.');
        }

        $document = StudentDocument::query()->firstOrCreate([
            'user_id' => $user->id,
            'document_name' => $documentName,
        ], [
            'original_name' => $verificationDocument->original_name,
            'path' => $targetPath,
            'mime_type' => $verificationDocument->mime_type,
            'size' => $verificationDocument->size,
            'uploaded_at' => $verificationDocument->uploaded_at ?? now(),
            'terms_accepted_at' => $verificationDocument->terms_accepted_at ?? now(),
            'terms_version' => $verificationDocument->terms_version,
        ]);

        if (! $document->wasRecentlyCreated) {
            $disk->delete($targetPath);

            return null;
        }

        return $document;
    }
}
