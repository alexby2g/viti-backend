#requires -version 5.1
<#
 Removes the VITI-IRENET startup shortcut for the current Windows user.
 Optional -ForgetToken deletes the user-bound DPAPI secret too.
 Does not stop an already running worker; sign out to terminate it.
#>
param([switch]$ForgetToken)
$ErrorActionPreference = 'Stop'
$startup = [Environment]::GetFolderPath('Startup')
$shortcut = Join-Path $startup 'VITI - IRENET local.lnk'
if (Test-Path -LiteralPath $shortcut) {
    Remove-Item -LiteralPath $shortcut -Force
    Write-Host 'Inicio automático desactivado. El conector no arrancará en la próxima sesión.'
} else {
    Write-Host 'No había acceso directo de inicio automático para este usuario.'
}
if ($ForgetToken) {
    $localDir = Join-Path $env:LOCALAPPDATA 'VITI-IRENET'
    $token = Join-Path $localDir 'bridge-token.dpapi'
    if (Test-Path -LiteralPath $token) {
        Remove-Item -LiteralPath $token -Force
        Write-Host 'Se eliminó el secreto local protegido. No se modificó la clave de Render.'
    }
}
Write-Host 'No se eliminó Ollama ni ningún dato de VITI.'
