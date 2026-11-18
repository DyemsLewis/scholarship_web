param([string]$AreaName='recipient-onboarding-20261118')
$ErrorActionPreference='Stop'
if($AreaName -notmatch '^recipient-onboarding(?:-live-demo)?-20261118$'){throw 'Unexpected guide working folder.'}
$root=(Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$area=Join-Path $root ".tmp-docx/$AreaName"
$update=Get-Content (Join-Path $area 'update.json') -Raw | ConvertFrom-Json
$staged=(Resolve-Path (Join-Path $area 'guide-updated.docx')).Path
$targets=@((Join-Path $root 'deliverables/Platform_Process_Guide_With_Accounts_Expanded.docx'),'D:\Platform_Process_Guide_With_Accounts_Expanded.docx')
foreach($target in $targets){
    if((Get-FileHash -LiteralPath $target).Hash.ToLowerInvariant() -ne $update.source_sha256){throw 'An existing guide changed during preparation; inspect rather than overwrite it.'}
    $file=[IO.File]::Open($target,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::None)
    $file.Dispose()
}
Copy-Item -LiteralPath $targets[0] -Destination (Join-Path $area 'guide-before.docx')
$hash=(Get-FileHash -LiteralPath $staged).Hash
foreach($target in $targets){
    Copy-Item -LiteralPath $staged -Destination $target -Force
    if((Get-FileHash -LiteralPath $target).Hash -ne $hash){throw 'Published document checksum mismatch.'}
}
Write-Output 'GUIDE_UPDATED_IN_PLACE=yes; PROJECT_AND_D_DRIVE_IDENTICAL=yes; NEW_PUBLIC_COPIES=none'
