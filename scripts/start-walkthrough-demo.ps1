param([switch]$HostingRehearsal)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$filename = if ($HostingRehearsal) { 'findscholar-hosted-rehearsal-20261120.sqlite' } else { 'findscholar-demo-20261120.sqlite' }
$port = if ($HostingRehearsal) { 8011 } else { 8010 }
$database = Join-Path (Join-Path $project 'database') $filename

if (-not (Test-Path -LiteralPath $database -PathType Leaf)) {
    throw "The walkthrough database is missing: $database"
}

if (Test-Path -LiteralPath (Join-Path $project 'bootstrap\cache\config.php')) {
    throw 'The demo needs uncached configuration so it cannot fall back to the main database.'
}

$settings = @{
    APP_ENV = 'local'
    APP_URL = "http://127.0.0.1:$port"
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = $database
    DB_URL = ''
    MAIL_MAILER = 'array'
    SESSION_COOKIE = if ($HostingRehearsal) { 'findscholar_hosted_rehearsal_session' } else { 'findscholar_walkthrough_session' }
    SESSION_SECURE_COOKIE = 'false'
}
$previous = @{}
Push-Location -LiteralPath $project
try {
    foreach ($key in $settings.Keys) {
        $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $settings[$key], 'Process')
    }
    php artisan serve --host=127.0.0.1 --port=$port --tries=1 --no-reload
    if ($LASTEXITCODE -ne 0) { throw "The demo server exited with code $LASTEXITCODE." }
} finally {
    foreach ($key in $previous.Keys) {
        [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process')
    }
    Pop-Location
}
