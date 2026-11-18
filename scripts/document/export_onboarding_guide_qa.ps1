param([string]$AreaName='recipient-onboarding-20261118')
$ErrorActionPreference='Stop'
if($AreaName -notmatch '^recipient-onboarding(?:-live-demo)?-20261118$'){throw 'Unexpected QA folder.'}
$root=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$area=Join-Path $root ".tmp-docx/$AreaName"
$inputPath=(Resolve-Path (Join-Path $area 'guide-updated.docx')).Path
$outputPath=Join-Path $area 'guide.pdf'
$before=(Get-FileHash -LiteralPath $inputPath).Hash
$word=$null;$document=$null
try{
    $word=New-Object -ComObject Word.Application
    $word.Visible=$false;$word.DisplayAlerts=0;$word.AutomationSecurity=3
    $document=$word.Documents.Open($inputPath,$false,$true)
    $document.Fields.Update() | Out-Null
    $document.Repaginate()
    $pages=$document.ComputeStatistics(2)
    $document.ExportAsFixedFormat($outputPath,17)
    "WORD_QA_RENDER=success; PAGES=$pages"
}finally{
    if($document){$document.Close(0);[Runtime.InteropServices.Marshal]::FinalReleaseComObject($document)|Out-Null}
    if($word){$word.Quit();[Runtime.InteropServices.Marshal]::FinalReleaseComObject($word)|Out-Null}
}
if((Get-FileHash -LiteralPath $inputPath).Hash -ne $before){throw 'Read-only rendering changed the document.'}
