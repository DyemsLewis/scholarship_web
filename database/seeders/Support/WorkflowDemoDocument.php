<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Storage;

final class WorkflowDemoDocument
{
    public static function make(string $path, string $title, array $lines): array
    {
        $escape = fn (string $text): string => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $content = "BT /F1 16 Tf 44 790 Td (".$escape($title).") Tj 0 -30 Td /F1 10 Tf ";
        foreach (['FICTIONAL DEMO - NOT AN OFFICIAL DOCUMENT', ...$lines] as $line) {
            foreach (explode("\n", wordwrap((string) $line, 88)) as $part) {
                $content .= '('.$escape($part).") Tj 0 -21 Td ";
            }
        }
        $content .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        Storage::disk('local')->put($path, $pdf);

        return ['original_name' => basename($path), 'path' => $path, 'mime_type' => 'application/pdf', 'size' => strlen($pdf)];
    }
}
