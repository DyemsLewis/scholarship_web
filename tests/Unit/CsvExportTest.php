<?php

namespace Tests\Unit;

use App\Support\CsvExport;
use PHPUnit\Framework\TestCase;

class CsvExportTest extends TestCase
{
    public function test_spreadsheet_formulas_are_escaped_in_csv_rows(): void
    {
        $handle = fopen('php://temp', 'w+');
        CsvExport::writeRow($handle, ['=SUM(1,1)', '+123', '-1', '@command', 'safe value']);
        rewind($handle);

        $row = fgetcsv($handle);
        fclose($handle);

        $this->assertSame(["'=SUM(1,1)", "'+123", "'-1", "'@command", 'safe value'], $row);
    }
}
