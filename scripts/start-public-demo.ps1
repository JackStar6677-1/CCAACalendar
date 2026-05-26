[CmdletBinding()]
param(
    [string]$TunnelConfig = (Join-Path $env:USERPROFILE '.cloudflared\ccaa-calendar.yml')
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$localDir = Join-Path $repoRoot '.local'
$logDir = Join-Path $localDir 'logs'
$envPath = Join-Path $repoRoot '.env'
$oauthPath = Join-Path $localDir 'google_oauth_client_secret.json'

try {
    foreach ($requiredFile in @($envPath, $oauthPath, $TunnelConfig)) {
        if (-not (Test-Path -LiteralPath $requiredFile)) {
            throw "Falta configuracion local requerida: $requiredFile"
        }
    }

    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    $uv = (Get-Command uv -ErrorAction Stop).Source
    $cloudflared = (Get-Command cloudflared -ErrorAction Stop).Source

    $running = Get-CimInstance Win32_Process |
        Where-Object { $_.CommandLine -like '*ccaa_calendar.main:app*' }
    if (-not $running) {
        Start-Process -FilePath $uv `
            -ArgumentList @('run', 'uvicorn', 'ccaa_calendar.main:app', '--app-dir', 'backend', '--host', '127.0.0.1', '--port', '8000') `
            -WorkingDirectory $repoRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logDir 'uvicorn.out.log') `
            -RedirectStandardError (Join-Path $logDir 'uvicorn.err.log')
    }

    $tunnelRunning = Get-CimInstance Win32_Process |
        Where-Object { $_.CommandLine -like '*cloudflared*tunnel*ccaa-calendar.yml*' }
    if (-not $tunnelRunning) {
        Start-Process -FilePath $cloudflared `
            -ArgumentList @('tunnel', '--config', $TunnelConfig, 'run') `
            -WorkingDirectory $repoRoot `
            -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logDir 'cloudflared.out.log') `
            -RedirectStandardError (Join-Path $logDir 'cloudflared.err.log')
    }
} catch {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
    "[$(Get-Date -Format o)] $($_.Exception.Message)" |
        Add-Content -LiteralPath (Join-Path $logDir 'startup.err.log')
    throw
}
