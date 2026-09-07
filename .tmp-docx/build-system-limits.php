<?php

declare(strict_types=1);

$sourcePath = __DIR__.'/system-limits-source.html';
$outputPath = dirname(__DIR__).'/docs/Scholarship_Platform_System_Limits_and_Service_Boundaries.docx';

$html = file_get_contents($sourcePath);
if ($html === false) {
    throw new RuntimeException('Unable to read the document source.');
}

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
libxml_clear_errors();

function xmlText(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function paragraphXml(string $text, string $style = 'Normal', bool $pageBreakBefore = false): string
{
    $properties = '<w:pStyle w:val="'.$style.'"/>';
    if ($pageBreakBefore) {
        $properties .= '<w:pageBreakBefore/>';
    }
    if (in_array($style, ['Title', 'Heading1', 'Heading2'], true)) {
        $properties .= '<w:keepNext/>';
    }

    return '<w:p><w:pPr>'.$properties.'</w:pPr><w:r><w:t xml:space="preserve">'.xmlText($text).'</w:t></w:r></w:p>';
}

function tableXml(DOMElement $table): string
{
    $rows = [];
    foreach ($table->getElementsByTagName('tr') as $row) {
        $cells = [];
        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                continue;
            }

            $isHeader = strtolower($cell->tagName) === 'th';
            $cellText = xmlText($cell->textContent);
            $shade = $isHeader ? '<w:shd w:val="clear" w:color="auto" w:fill="172033"/>' : '';
            $runProperties = $isHeader ? '<w:rPr><w:b/><w:color w:val="FFFFFF"/></w:rPr>' : '';
            $cells[] = '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/><w:vAlign w:val="center"/><w:tcMar><w:top w:w="100" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tcMar>'.$shade.'</w:tcPr><w:p><w:pPr><w:spacing w:after="0" w:line="270" w:lineRule="auto"/></w:pPr><w:r>'.$runProperties.'<w:t xml:space="preserve">'.$cellText.'</w:t></w:r></w:p></w:tc>';
        }
        if ($cells !== []) {
            $rows[] = '<w:tr><w:trPr><w:cantSplit/></w:trPr>'.implode('', $cells).'</w:tr>';
        }
    }

    return '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblLayout w:type="autofit"/><w:tblBorders><w:top w:val="single" w:sz="4" w:color="D9D9D9"/><w:left w:val="single" w:sz="4" w:color="D9D9D9"/><w:bottom w:val="single" w:sz="4" w:color="D9D9D9"/><w:right w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideH w:val="single" w:sz="4" w:color="D9D9D9"/><w:insideV w:val="single" w:sz="4" w:color="D9D9D9"/></w:tblBorders><w:tblCellMar><w:top w:w="100" w:type="dxa"/><w:left w:w="120" w:type="dxa"/><w:bottom w:w="100" w:type="dxa"/><w:right w:w="120" w:type="dxa"/></w:tblCellMar></w:tblPr>'.implode('', $rows).'</w:tbl>'.paragraphXml('', 'Normal');
}

$body = $dom->getElementsByTagName('body')->item(0);
if (! $body instanceof DOMElement) {
    throw new RuntimeException('The document source has no body.');
}

$content = '';
foreach ($body->childNodes as $node) {
    if (! $node instanceof DOMElement) {
        continue;
    }

    $tag = strtolower($node->tagName);
    $class = $node->getAttribute('class');
    if ($tag === 'h1') {
        $content .= paragraphXml($node->textContent, 'Title');
    } elseif ($tag === 'h2') {
        $content .= paragraphXml($node->textContent, 'Heading1', str_contains($class, 'page-break'));
    } elseif ($tag === 'h3') {
        $content .= paragraphXml($node->textContent, 'Heading2');
    } elseif ($tag === 'p') {
        $content .= paragraphXml($node->textContent, str_contains($class, 'subtitle') ? 'Subtitle' : 'Normal');
    } elseif ($tag === 'table') {
        $content .= tableXml($node);
    }
}

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    .$content
    .'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1181" w:right="1181" w:bottom="1123" w:left="1181" w:header="708" w:footer="708" w:gutter="0"/><w:cols w:space="708"/></w:sectPr></w:body></w:document>';

$stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="111827"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="304" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="160" w:line="304" w:lineRule="auto"/></w:pPr></w:style>
  <w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Subtitle"/><w:qFormat/><w:pPr><w:spacing w:after="260"/><w:keepNext/></w:pPr><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="000000"/><w:sz w:val="50"/><w:szCs w:val="50"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:after="360"/></w:pPr><w:rPr><w:color w:val="4B5563"/><w:sz w:val="26"/><w:szCs w:val="26"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="360" w:after="120"/><w:keepNext/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="000000"/><w:sz w:val="34"/><w:szCs w:val="34"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr><w:spacing w:before="240" w:after="80"/><w:keepNext/><w:outlineLvl w:val="1"/></w:pPr><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:color w:val="000000"/><w:sz w:val="25"/><w:szCs w:val="25"/></w:rPr></w:style>
</w:styles>
XML;

$contentTypes = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>
XML;

$rootRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>
XML;

$documentRels = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>
XML;

$settingsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:zoom w:percent="100"/><w:defaultTabStop w:val="720"/><w:compat/></w:settings>';
$coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>Scholarship Finder Platform System Limits and Service Boundaries</dc:title><dc:creator>Scholarship Finder Platform Project Team</dc:creator><cp:lastModifiedBy>Scholarship Finder Platform Project Team</cp:lastModifiedBy></cp:coreProperties>';
$appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Microsoft Office Word</Application></Properties>';

if (is_file($outputPath)) {
    unlink($outputPath);
}

$zip = new ZipArchive();
if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create the DOCX file.');
}

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->addFromString('word/settings.xml', $settingsXml);
$zip->addFromString('word/_rels/document.xml.rels', $documentRels);
$zip->addFromString('docProps/core.xml', $coreXml);
$zip->addFromString('docProps/app.xml', $appXml);
$zip->close();

echo $outputPath.PHP_EOL;
