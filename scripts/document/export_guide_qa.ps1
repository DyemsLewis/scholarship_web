param([Parameter(Mandatory)] [string] $InputDocx,[Parameter(Mandatory)] [string] $OutputPdf)
$ErrorActionPreference='Stop'
$inputPath=(Resolve-Path -LiteralPath $InputDocx).Path
$outputPath=[IO.Path]::GetFullPath($OutputPdf)
$outputRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../.tmp-docx/single-provider-guide-20261114'))
if(!$outputPath.StartsWith($outputRoot+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'QA PDF must remain in the private working folder.'}
[IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($outputPath)) | Out-Null
$before=(Get-FileHash -LiteralPath $inputPath).Hash
$word=$null;$document=$null
try {
    $word=New-Object -ComObject Word.Application
    $word.Visible=$false
    $word.DisplayAlerts=0
    $word.AutomationSecurity=3
    $document=$word.Documents.Open($inputPath,$false,$true)
    $document.Fields.Update() | Out-Null
    $document.Repaginate()
    $pages=$document.ComputeStatistics(2)
    $document.ExportAsFixedFormat($outputPath,17)
    Write-Output "WORD_QA_RENDER=success; PAGES=$pages"
} finally {
    if($document){$document.Close(0);[Runtime.InteropServices.Marshal]::FinalReleaseComObject($document) | Out-Null}
    if($word){$word.Quit();[Runtime.InteropServices.Marshal]::FinalReleaseComObject($word) | Out-Null}
}
if((Get-FileHash -LiteralPath $inputPath).Hash -ne $before){throw 'Read-only rendering changed the document.'}
