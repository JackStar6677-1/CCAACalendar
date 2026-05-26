[CmdletBinding()]
param(
    [string]$TaskName = 'CCAACalendarPublicDemo'
)

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$launcher = Join-Path $repoRoot 'scripts\start-public-demo.ps1'

try {
    if (-not (Test-Path -LiteralPath $launcher)) {
        throw "No se encontro el iniciador: $launcher"
    }

    $action = New-ScheduledTaskAction `
        -Execute 'powershell.exe' `
        -Argument "-NoProfile -WindowStyle Hidden -File `"$launcher`""
    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
    $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 0)
    Register-ScheduledTask `
        -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Description 'Inicia CCAACalendar local y su tunel Cloudflare al iniciar sesion.' `
        -Force | Out-Null
    Enable-ScheduledTask -TaskName $TaskName | Out-Null
    Write-Output "[SUCCESS] Tarea $TaskName instalada para el usuario actual."
} catch {
    Write-Error "[ERROR] No se pudo instalar la tarea: $($_.Exception.Message)"
    exit 1
}
