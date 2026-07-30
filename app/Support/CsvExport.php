<?php

namespace App\Support;

class CsvExport
{
    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    public static function writeRow($handle, array $values): void
    {
        fputcsv($handle, array_map([self::class, 'safeCell'], $values));
    }

    private static function safeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1
            ? "'{$value}"
            : $value;
    }
}
