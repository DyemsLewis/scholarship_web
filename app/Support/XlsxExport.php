<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class XlsxExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, int|float>  $columnWidths
     */
    public static function create(
        string $path,
        string $title,
        string $subtitle,
        array $headers,
        iterable $rows,
        array $columnWidths = [],
    ): void {
        if ($headers === []) {
            throw new RuntimeException('An Excel export requires at least one column.');
        }

        $sheetPath = tempnam(sys_get_temp_dir(), 'xlsx-sheet-');

        if ($sheetPath === false) {
            throw new RuntimeException('Unable to prepare the Excel worksheet.');
        }

        try {
            $filterRange = self::writeWorksheet($sheetPath, $title, $subtitle, $headers, $rows, $columnWidths);
            self::writePackage($path, $sheetPath, $filterRange);
        } finally {
            @unlink($sheetPath);
        }
    }

    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     * @param  array<int, int|float>  $columnWidths
     */
    private static function writeWorksheet(
        string $sheetPath,
        string $title,
        string $subtitle,
        array $headers,
        iterable $rows,
        array $columnWidths,
    ): string {
        $handle = fopen($sheetPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to write the Excel worksheet.');
        }

        $lastColumn = self::columnLetter(count($headers));
        $defaultWidths = [14, 30, 24, 28, 16, 21, 18, 13, 22, 18, 34, 34, 28, 23, 15, 21, 34];
        $widths = $columnWidths ?: $defaultWidths;

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
        fwrite($handle, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
        fwrite($handle, '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>');
        fwrite($handle, '<sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>');
        fwrite($handle, '<sheetFormatPr defaultRowHeight="18"/>');
        fwrite($handle, '<cols>');

        foreach ($headers as $index => $_header) {
            $width = max(8, min(60, (float) ($widths[$index] ?? 18)));
            $column = $index + 1;
            fwrite($handle, sprintf('<col min="%d" max="%d" width="%s" customWidth="1"/>', $column, $column, $width));
        }

        fwrite($handle, '</cols><sheetData>');
        fwrite($handle, '<row r="1" ht="28" customHeight="1">'.self::cellXml('A1', $title, 1).'</row>');
        fwrite($handle, '<row r="2" ht="22" customHeight="1">'.self::cellXml('A2', $subtitle, 2).'</row>');
        fwrite($handle, '<row r="3" ht="30" customHeight="1">');

        foreach ($headers as $index => $header) {
            fwrite($handle, self::cellXml(self::columnLetter($index + 1).'3', $header, 3));
        }

        fwrite($handle, '</row>');

        $rowNumber = 4;
        $centeredColumns = [1, 6, 7, 8, 9, 10, 15, 16];
        $wrappedColumns = [11, 12, 13, 17];

        foreach ($rows as $row) {
            fwrite($handle, sprintf('<row r="%d">', $rowNumber));

            foreach ($headers as $index => $_header) {
                $columnNumber = $index + 1;
                $style = 4;

                if (in_array($columnNumber, $centeredColumns, true)) {
                    $style = 5;
                } elseif (in_array($columnNumber, $wrappedColumns, true)) {
                    $style = 6;
                }

                fwrite($handle, self::cellXml(
                    self::columnLetter($columnNumber).$rowNumber,
                    $row[$index] ?? null,
                    $style,
                ));
            }

            fwrite($handle, '</row>');
            $rowNumber++;
        }

        $lastRow = max(3, $rowNumber - 1);
        fwrite($handle, '</sheetData>');
        fwrite($handle, sprintf('<autoFilter ref="A3:%s%d"/>', $lastColumn, $lastRow));
        fwrite($handle, sprintf('<mergeCells count="2"><mergeCell ref="A1:%s1"/><mergeCell ref="A2:%s2"/></mergeCells>', $lastColumn, $lastColumn));
        fwrite($handle, '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>');
        fwrite($handle, '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0" paperSize="9"/>');
        fwrite($handle, '</worksheet>');
        fclose($handle);

        return '$A$3:$'.$lastColumn.'$'.$lastRow;
    }

    private static function writePackage(string $path, string $sheetPath, string $filterRange): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelationshipsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($filterRange));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationshipsXml());
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            throw new RuntimeException('Unable to finish the Excel file.');
        }
    }

    private static function cellXml(string $reference, mixed $value, int $style): string
    {
        if ($value === null || $value === '') {
            return sprintf('<c r="%s" s="%d"/>', $reference, $style);
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d" t="n"><v>%s</v></c>', $reference, $style, $value);
        }

        if (is_bool($value)) {
            return sprintf('<c r="%s" s="%d" t="b"><v>%d</v></c>', $reference, $style, $value ? 1 : 0);
        }

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value) ?? '';
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return sprintf('<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $reference, $style, $escaped);
    }

    private static function columnLetter(int $column): string
    {
        $letter = '';

        while ($column > 0) {
            $column--;
            $letter = chr(65 + ($column % 26)).$letter;
            $column = intdiv($column, 26);
        }

        return $letter;
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbookXml(string $filterRange): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView/></bookViews>'
            .'<sheets><sheet name="Applicants" sheetId="1" r:id="rId1"/></sheets>'
            .'<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">&apos;Applicants&apos;!'.$filterRange.'</definedName></definedNames>'
            .'</workbook>';
    }

    private static function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="4">'
            .'<font><sz val="11"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Cambria"/><family val="1"/></font>'
            .'<font><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/><family val="2"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF0B172A"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
