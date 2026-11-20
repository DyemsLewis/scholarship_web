<?php

namespace App\Support;

use App\Models\ApplicationDocument;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class ApplicationDocumentReviewReceipt
{
    public const HEADER = 'X-Document-Review-Receipt';

    public static function issue(ApplicationDocument $document, User $reviewer): string
    {
        return Crypt::encryptString(json_encode([
            ...self::claims($document, $reviewer),
            'expires_at' => now()->addHour()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public static function valid(?string $receipt, ApplicationDocument $document, User $reviewer): bool
    {
        if (! $receipt) {
            return false;
        }

        try {
            $claims = json_decode(Crypt::decryptString($receipt), true);
        } catch (DecryptException) {
            return false;
        }

        $expiresAt = $claims['expires_at'] ?? null;

        return is_int($expiresAt) && $expiresAt > now()->timestamp
            && $claims === [...self::claims($document, $reviewer), 'expires_at' => $expiresAt];
    }

    private static function claims(ApplicationDocument $document, User $reviewer): array
    {
        return [
            'purpose' => 'application_document_review',
            'reviewer_id' => $reviewer->id,
            'document_id' => $document->id,
            'file_path' => $document->path,
        ];
    }
}
