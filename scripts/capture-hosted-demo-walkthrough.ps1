param([string[]]$Accounts)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$capture = Join-Path $project 'storage\framework\cache\mirevalo-walkthrough-shots'
New-Item -Path $capture -ItemType Directory -Force | Out-Null

$env:PROVIDER_SMOKE_BASE_URL = 'http://127.0.0.1:8011'
$env:PROVIDER_SMOKE_PASSWORD = 'password123'
$env:PROVIDER_SMOKE_WINDOW_SIZE = '1440,1050'
$env:PROVIDER_SMOKE_TIMEOUT_MS = '12000'
$groups = [ordered]@{
    'admin.reviews' = @(
        @('/admin/applicants/28/review', 'Applicant Mavero', 'profile-check'),
        @('/admin/scholarships/11/review', 'Mirevalo College Readiness Award', 'program-review')
    )
    'learner.ziavon' = @(
        @('/dashboard/scholarships/1', 'Mirevalo Open Study Grant', 'find-scholarship'),
        @('/dashboard/applications/26/outcome', 'Mirevalo Orientation Support Grant', 'offer')
    )
    'mirevalo.reviewer' = ,@('/provider/applications/1?workspace=review&section=documents', 'Scholarship application files', 'application-files')
    'mirevalo.selection' = ,@('/provider/workspaces/selection/results', 'Mirevalo Applied Learning Award', 'stage-results')
    'mirevalo.decisions' = ,@('/provider/workspaces/decisions/pending?program_id=5', 'Mirevalo Future Scholars Award', 'final-decisions')
    'mirevalo.monitoring' = ,@('/provider/workspaces/monitoring/check-ins/1', 'Mirevalo Learning Continuity Grant', 'check-in')
    'mirevalo.releases' = @(
        @('/provider/workspaces/releases/distributions/5', 'In person', 'release-collection'),
        @('/provider/workspaces/releases/distributions/4', 'E-wallet', 'release-wallet'),
        @('/provider/workspaces/releases/distributions/8', 'Bank transfer', 'release-bank')
    )
    'admin' = ,@('/admin', 'Super admin', 'superadmin')
}

Push-Location -LiteralPath $project
try {
    foreach ($role in $groups.Keys) {
        if ($Accounts -and $role -notin $Accounts) { continue }
        $env:PROVIDER_SMOKE_LOGIN = $role
        $workspaces = [System.Collections.Generic.List[object]]::new()
        foreach ($page in $groups[$role]) {
            $loading = if ($role -eq 'admin') { 'Loading governance overview...' } else { $null }
            $workspaces.Add(@($page[0], $page[1], $loading, @{
                path = Join-Path $capture ($page[2] + '.png')
                allowOpaqueRecord = $true
                dismissWorkspaceGuide = $role -in 'mirevalo.selection', 'mirevalo.decisions'
            }))
        }
        $env:PROVIDER_SMOKE_WORKSPACES = ConvertTo-Json -InputObject ($workspaces.ToArray()) -Depth 5 -Compress
        node tests/Browser/providerWorkspaceSmoke.mjs
        if ($LASTEXITCODE -ne 0) { throw "Could not capture the pages for $role." }
    }
} finally {
    Pop-Location
}
