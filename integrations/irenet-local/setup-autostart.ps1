#requires -version 5.1
<#
 VITI-IRENET: one-time per-user Windows autostart installer.
 Saves the existing bridge token using Windows DPAPI, not plain text.
 Creates a shortcut in the current user's Startup folder; no admin needed.
 The bridge launches after sign-in without opening a terminal window.
#>
param()
$ErrorActionPreference = 'Stop'

$worker = Join-Path $PSScriptRoot 'worker.py'
$runner = Join-Path $PSScriptRoot 'run-autostart.ps1'
if (!(Test-Path -LiteralPath $worker) -or !(Test-Path -LiteralPath $runner)) {
    throw 'El conector no está completo. Actualiza la carpeta VITI-IRENET-bridge con git pull.'
}

$python = Get-Command python.exe -ErrorAction SilentlyContinue
if (!$python) { throw 'No encontré Python. Comprueba python --version.' }

$localDirectory = Join-Path $env:LOCALAPPDATA 'VITI-IRENET'
New-Item -ItemType Directory -Path $localDirectory -Force | Out-Null
$secretFile = Join-Path $localDirectory 'bridge-token.dpapi'

# Prefer the already configured token from the same console that ran worker.py.
# If absent, prompt invisibly for the SAME token configured in Render.
$secureToken = $null
$existing = $env:VITI_IRENET_BRIDGE_TOKEN
if ($existing -and $existing.Length -ge 32) {
    $secureToken = ConvertTo-SecureString -String $existing -AsPlainText -Force
} else {
    Write-Host 'Introduce la MISMA clave IRENET_LOCAL_BRIDGE_TOKEN configurada en Render.'
    Write-Host 'No generes una clave nueva; no se mostrará lo que pegues.'
    $secureToken = Read-Host 'Clave del conector (oculta)' -AsSecureString
}

$buffer = [IntPtr]::Zero
try {
    $buffer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
    $actualLength = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($buffer).Length
    if ($actualLength -lt 32) { throw 'La clave debe tener al menos 32 caracteres.' }
} finally {
    if ($buffer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($buffer)
    }
}
# ConvertFrom-SecureString without a key uses Windows DPAPI for this user and PC.
$secureToken | ConvertFrom-SecureString | Set-Content -LiteralPath $secretFile -Encoding ASCII -NoNewline

$startup = [Environment]::GetFolderPath('Startup')
if (!$startup -or !(Test-Path -LiteralPath $startup)) { throw 'No se encontró la carpeta Inicio de Windows.' }

# The VBScript holds only the path to the local launcher, never the token.
# WScript starts PowerShell hidden and without a CMD window.
$powerShellPath = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
$powerShellArgs = ('"{0}" -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{1}"' -f $powerShellPath, $runner)
$vbsCommand = $powerShellArgs.Replace('"', '""')
$vbsPath = Join-Path $localDirectory 'start-bridge-hidden.vbs'
@(
    'Option Explicit'
    ('CreateObject("WScript.Shell").Run "{0}", 0, False' -f $vbsCommand)
) | Set-Content -LiteralPath $vbsPath -Encoding ASCII

$shortcutPath = Join-Path $startup 'VITI - IRENET local.lnk'
$wshell = New-Object -ComObject WScript.Shell
$shortcut = $wshell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = Join-Path $env:WINDIR 'System32\wscript.exe'
$shortcut.Arguments = ('//B //Nologo "{0}"' -f $vbsPath)
$shortcut.WorkingDirectory = $PSScriptRoot
$shortcut.Description = 'Conector local IRENET para VITI - Ollama qwen2.5:7b'
$shortcut.Save()

Write-Host 'INICIO AUTOMATICO DE VITI-IRENET CONFIGURADO' -ForegroundColor Green
Write-Host 'La clave está protegida con DPAPI de Windows para este usuario.'
Write-Host 'El conector se iniciará oculto después de entrar a Windows.'
Write-Host 'VITI (Vercel/Render) ya funciona en línea y no requiere abrir un terminal.'
Write-Host 'Registro local: %LOCALAPPDATA%\VITI-IRENET\bridge.log'
Write-Host 'Detén el antiguo worker.py manual con Ctrl+C para evitar dos instancias.'
Write-Host 'La aplicación de escritorio Irenet.exe se configura por separado.'
