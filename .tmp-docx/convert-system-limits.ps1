$ErrorActionPreference = 'Stop'

$source = (Resolve-Path "$PSScriptRoot\..\docs\Scholarship_Platform_System_Limits_and_Service_Boundaries.docx").Path
$outputDirectory = (Resolve-Path "$PSScriptRoot\..\docs").Path
$pdf = Join-Path $PSScriptRoot 'Scholarship_Platform_System_Limits_and_Service_Boundaries.pdf'

$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0

try {
    Write-Output 'Opening document'
    $document = $word.Documents.Open($source)
    Write-Output 'Saving PDF copy'
    $document.SaveAs2($pdf, 17)
    Write-Output 'Closing document'
    $document.Close($false)
} finally {
    $word.Quit()
    [System.Runtime.InteropServices.Marshal]::FinalReleaseComObject($word) | Out-Null
}

Write-Output $pdf
