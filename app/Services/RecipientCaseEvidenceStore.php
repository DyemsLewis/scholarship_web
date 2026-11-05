<?php

namespace App\Services;

use App\Models\RecipientAgreementCase;
use App\Models\RecipientAgreementCaseEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

final class RecipientCaseEvidenceStore
{
    public function validate(array $files, int $maximum = 3): void
    {
        Validator::make(['evidence' => $files], [
            'evidence' => ['array', 'max:'.$maximum], 'evidence.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ])->validate();
    }

    public function store(User $actor, RecipientAgreementCase $case, UploadedFile $file, array &$paths, ?int $responseId = null): RecipientAgreementCaseEvidence
    {
        $directory = "recipient-agreement-cases/{$case->id}/evidence";
        $filename = Str::uuid().'.'.$file->extension();
        $path = $directory.'/'.$filename;
        $paths[] = $path;
        if ($file->storeAs($directory, $filename, 'local') !== $path) {
            throw new RuntimeException('The private evidence file could not be stored.');
        }
        $evidence = new RecipientAgreementCaseEvidence;
        $evidence->forceFill([
            'recipient_agreement_case_id' => $case->id, 'response_id' => $responseId, 'uploaded_by' => $actor->id,
            'original_name' => Str::limit(str_replace(["\r", "\n", "\0"], '', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 200, ''),
            'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()), 'created_at' => now(),
        ])->save();

        return $evidence;
    }
}
