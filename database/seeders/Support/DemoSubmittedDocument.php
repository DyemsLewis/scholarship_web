<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Str;
use RuntimeException;

final class DemoSubmittedDocument
{
    private const FILES = [
        'academic_record' => [
            'form-138.png',
            'form-137.jpg',
            'senior-high-record.png',
            'college-transcript.png',
        ],
        'recent_school_id' => [
            'recent-school-id.png',
        ],
        'achievement_evidence' => [
            'recommendation-letter.png',
            'good-moral-certificate.png',
            'personal-essay.jpg',
        ],
        'certificate_of_enrollment' => [
            'certificate-of-enrollment.png',
        ],
        'proof_of_household_income' => [
            'proof-of-income.png',
            'income-tax-return.jpg',
            'certificate-of-indigency.png',
        ],
    ];

    private const ALIASES = [
        'Certificate of enrollment' => 'certificate_of_enrollment',
        'Latest report card or grades' => 'academic_record',
        'Recent school ID' => 'recent_school_id',
        'Proof of household income' => 'proof_of_household_income',
    ];

    public static function exists(string $type): bool
    {
        return array_key_exists(self::key($type), self::FILES);
    }

    /**
     * @return array{contents: string, extension: string, mime_type: string, original_name: string, size: int}
     */
    public static function get(string $type, int $variant = 0): array
    {
        $key = self::key($type);
        $files = self::FILES[$key] ?? null;

        if ($files === null) {
            throw new RuntimeException("No demo submitted document is configured for [{$type}].");
        }

        $filename = $files[abs($variant) % count($files)];
        $source = database_path('seeders/assets/demo-documents/'.$filename);

        if (! is_file($source)) {
            throw new RuntimeException("Demo submitted document asset is missing: {$source}");
        }

        $contents = file_get_contents($source);

        if ($contents === false) {
            throw new RuntimeException("Unable to read demo submitted document asset: {$source}");
        }

        return [
            'contents' => $contents,
            'extension' => Str::lower(pathinfo($filename, PATHINFO_EXTENSION)),
            'mime_type' => mime_content_type($source) ?: 'application/octet-stream',
            'original_name' => $filename,
            'size' => filesize($source),
        ];
    }

    private static function key(string $type): string
    {
        return self::ALIASES[$type] ?? $type;
    }
}
