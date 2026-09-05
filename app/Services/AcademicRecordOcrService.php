<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class AcademicRecordOcrService
{
    public const STATUS_NOT_REQUESTED = 'not_requested';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';

    public function enabled(): bool
    {
        return (bool) config('services.academic_ocr.enabled', false);
    }

    public function configured(): bool
    {
        $endpoint = (string) config('services.academic_ocr.endpoint');

        return $this->enabled()
            && str_starts_with($endpoint, 'https://')
            && filled(config('services.academic_ocr.key'));
    }

    public function publicConfiguration(): array
    {
        $maximumKilobytes = max(1, (int) config('services.academic_ocr.max_file_size_kb', 1024));

        return [
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'provider' => 'OCR.space',
            'max_file_size_mb' => round($maximumKilobytes / 1024, 1),
            'supported_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        ];
    }

    public function scan(string $contents, ?string $filename = null, ?string $mimeType = null): array
    {
        if (! $this->enabled()) {
            return $this->result(self::STATUS_NOT_REQUESTED, 'Automatic academic scanning is disabled.');
        }

        if (! $this->configured()) {
            return $this->result(self::STATUS_UNAVAILABLE, 'The academic scanner is not configured. Your file remains available for review.');
        }

        $upload = $this->uploadDescriptor($filename, $mimeType);

        if ($upload === null) {
            return $this->result(self::STATUS_FAILED, 'Automatic scanning supports clear PDF, JPG, JPEG, or PNG academic records.');
        }

        $stream = null;

        try {
            $stream = fopen('php://temp', 'w+b');

            if ($stream === false || fwrite($stream, $contents) === false) {
                throw new \RuntimeException('Unable to prepare the academic record for scanning.');
            }

            rewind($stream);

            $response = Http::acceptJson()
                ->withHeaders([
                    'apikey' => (string) config('services.academic_ocr.key'),
                ])
                ->timeout(max(5, (int) config('services.academic_ocr.timeout_seconds', 30)))
                ->attach('file', $stream, $upload['filename'], [
                    'Content-Type' => $upload['mime_type'],
                ])
                ->post((string) config('services.academic_ocr.endpoint'), [
                    'language' => (string) config('services.academic_ocr.language', 'eng'),
                    'isOverlayRequired' => 'false',
                    'detectOrientation' => 'true',
                    'scale' => 'true',
                    'isTable' => 'true',
                    'OCREngine' => (string) $this->engine(),
                    'filetype' => $upload['filetype'],
                ]);

            if (! $response->successful()) {
                return $this->result(self::STATUS_FAILED, 'The scanner could not read this file. Upload a clear image or PDF and try again.');
            }

            $payload = $response->json();

            if (! is_array($payload)
                || (bool) data_get($payload, 'IsErroredOnProcessing', false)
                || ! in_array((int) data_get($payload, 'OCRExitCode', 0), [1, 2], true)) {
                return $this->result(self::STATUS_FAILED, 'The scanner could not process this record. Upload a clearer file and try again.');
            }

            $parsedText = collect((array) data_get($payload, 'ParsedResults', []))
                ->pluck('ParsedText')
                ->filter(fn ($text): bool => filled($text))
                ->implode("\n");

            if (blank($parsedText)) {
                return $this->result(self::STATUS_NEEDS_REVIEW, 'No readable text was found. Upload a clearer academic record and try again.');
            }

            return $this->extractResult($parsedText);
        } catch (Throwable $error) {
            report($error);

            return $this->result(self::STATUS_UNAVAILABLE, 'The academic scanner is temporarily unavailable. Your file remains available for review.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function extractResult(string $content): array
    {
        $lines = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        )));
        $candidateLines = $lines;

        foreach ($lines as $index => $line) {
            if (isset($lines[$index + 1])) {
                $candidateLines[] = "{$line} {$lines[$index + 1]}";
            }
        }

        $candidates = [];

        foreach (array_values(array_unique($candidateLines)) as $line) {
            $candidate = $this->gradeCandidate($line);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates !== []) {
            usort($candidates, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $best = $candidates[0];

            return [
                'status' => self::STATUS_SUCCEEDED,
                'provider' => 'ocr_space',
                'grade' => $best['grade'],
                'grading_scale' => $best['grading_scale'],
                'label' => $best['label'],
                'message' => 'Academic result extracted. A reviewer must compare it with the original record.',
            ];
        }

        if (preg_match('/\b(?:overall|general)\s+(?:result|rating)\s*[:=\-]?\s*(?:passed|pass|competent|satisfactory)\b/i', $content)) {
            return [
                'status' => self::STATUS_SUCCEEDED,
                'provider' => 'ocr_space',
                'grade' => null,
                'grading_scale' => 'pass_fail',
                'label' => 'Overall result',
                'message' => 'A pass or competency result was extracted. A reviewer must compare it with the original record.',
            ];
        }

        return $this->result(
            self::STATUS_NEEDS_REVIEW,
            'No clearly labeled general average, GWA, GPA, or overall result was found. Upload a clearer record that shows the final result.',
        );
    }

    private function gradeCandidate(string $line): ?array
    {
        $normalized = preg_replace('/(?<=\d),(?=\d)/', '.', $line) ?? $line;
        $labels = [
            'general weighted average' => 115,
            'pangkalahatang marka' => 110,
            'general average' => 105,
            'overall average' => 100,
            'final average' => 95,
            'academic average' => 90,
            'kabuuang marka' => 90,
            'gwa' => 100,
            'gpa' => 95,
        ];

        foreach ($labels as $label => $priority) {
            if (! preg_match('/\b'.preg_quote($label, '/').'\b/i', $normalized, $labelMatch, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            preg_match_all('/(?<!\d)(\d{1,4}(?:\.\d{1,2})?)(?:\s*%)?(?!\d)/', $normalized, $numberMatches, PREG_OFFSET_CAPTURE);
            $best = null;
            $labelOffset = $labelMatch[0][1];

            foreach ($numberMatches[1] ?? [] as [$rawValue, $offset]) {
                $value = (float) $rawValue;
                $scale = $this->gradingScale(
                    $value,
                    str_contains(substr($normalized, $offset, strlen($rawValue) + 2), '%'),
                );

                if ($scale === null) {
                    continue;
                }

                $distance = abs($offset - $labelOffset);
                $score = $priority - min(40, $distance);

                if ($best === null || $score > $best['score']) {
                    $best = [
                        'grade' => round($value, 2),
                        'grading_scale' => $scale,
                        'label' => str($label)->title()->toString(),
                        'score' => $score,
                    ];
                }
            }

            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    private function gradingScale(float $value, bool $hasPercentSign): ?string
    {
        if ($value <= 0 || $value > 100) {
            return null;
        }

        if ($hasPercentSign) {
            return 'percentage';
        }

        if ($value <= 5) {
            return 'grade_point';
        }

        return 'percentage';
    }

    private function uploadDescriptor(?string $filename, ?string $mimeType): ?array
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, ['pdf', 'jpg', 'png'], true)) {
            $extension = match (strtolower((string) $mimeType)) {
                'application/pdf' => 'pdf',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/png' => 'png',
                default => '',
            };
        }

        if ($extension === '') {
            return null;
        }

        return [
            'filename' => "academic-record.{$extension}",
            'filetype' => strtoupper($extension),
            'mime_type' => match ($extension) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                default => 'image/jpeg',
            },
        ];
    }

    private function engine(): int
    {
        $engine = (int) config('services.academic_ocr.engine', 2);

        return in_array($engine, [2, 3], true) ? $engine : 2;
    }

    private function result(string $status, string $message): array
    {
        return [
            'status' => $status,
            'provider' => $this->enabled() ? 'ocr_space' : null,
            'grade' => null,
            'grading_scale' => null,
            'label' => null,
            'message' => $message,
        ];
    }
}
