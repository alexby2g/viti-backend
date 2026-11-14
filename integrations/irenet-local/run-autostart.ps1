#requires -version 5.1
<#
 Runs from the Windows per-user Startup shortcut.
 Never place the bridge token in this script or in process command-line args.
#>
param()
$ErrorActionPreference = 'Stop'

$localDirectory = Join-Path $env:LOCALAPPDATA 'VITI-IRENET'
$secretFile = Join-Path $localDirectory 'bridge-token.dpapi'
$logFile = Join-Path $localDirectory 'bridge.log'
$worker = Join-Path $PSScriptRoot 'worker.py'

New-Item -ItemType Directory -Force -Path $localDirectory | Out-Null

function Write-BridgeLog([string]$message) {
    try {
        if ((Test-Path -LiteralPath $logFile) -and (Get-Item -LiteralPath $logFile).Length -gt 5242880) {
            Move-Item -LiteralPath $logFile -Destination (Join-Path $localDirectory 'bridge.previous.log') -Force
        }
        Add-Content -LiteralPath $logFile -Value ('{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message) -Encoding UTF8
    } catch {
        # Logging is best effort; never print the private token.
    }
}

# The mutex prevents duplicate polling workers after repeated sign-in events.
$mutex = [System.Threading.Mutex]::new($false, 'Local\VITI_IRENET_LocalBridge')
$locked = $false
try {
    $locked = $mutex.WaitOne(0)
    if (!$locked) { exit 0 }

    if (!(Test-Path -LiteralPath $secretFile)) {
        Write-BridgeLog 'No encuentro el secreto protegido. Ejecuta setup-autostart.ps1 otra vez.'
        exit 1
    }
    if (!(Test-Path -LiteralPath $worker)) {
        Write-BridgeLog 'El archivo worker.py no está en la carpeta de VITI.'
        exit 1
    }

    # DPAPI decrypts only for the Windows account that installed the shortcut.
    $encrypted = (Get-Content -LiteralPath $secretFile -Raw).Trim()
    $secure = ConvertTo-SecureString -String $encrypted
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        $env:VITI_IRENET_BRIDGE_TOKEN = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    } finally {
        if ($pointer -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
        }
    }

    if ($env:VITI_IRENET_BRIDGE_TOKEN.Length -lt 32) {
        Write-BridgeLog 'La clave guardada es inválida.'
        exit 1
    }
    $env:VITI_LOCAL_OLLAMA_MODEL = 'qwen2.5:7b'
    $env:OLLAMA_HOST = '127.0.0.1:11434'

    $python = Get-Command python.exe -ErrorAction Stop
    Write-BridgeLog 'Inicio automático activado. El bridge solo realiza HTTPS saliente.'

    function Test-OllamaReady {
        try {
            $null = Invoke-RestMethod -Uri 'http://127.0.0.1:11434/api/tags' -TimeoutSec 3 -ErrorAction Stop
            return $true
        } catch { return $false }
    }

    # Give Windows and Ollama's own login startup a chance to initialize.
    Start-Sleep -Seconds 15
    if (!(Test-OllamaReady)) {
        $ollama = Get-Command ollama.exe -ErrorAction SilentlyContinue
        if ($ollama) {
            try {
                Start-Process -FilePath $ollama.Source -ArgumentList 'serve' -WindowStyle Hidden | Out-Null
                Write-BridgeLog 'Ollama local no respondía; se solicitó su inicio oculto.'
            } catch {
                Write-BridgeLog ('No pude iniciar Ollama automáticamente: ' + $_.Exception.GetType().Name)
            }
        } else {
            Write-BridgeLog 'No encontré ollama.exe en PATH. Activa Ollama en el inicio de Windows.'
        }
    }

    for ($i = 0; $i -lt 20 -and !(Test-OllamaReady); $i++) {
        Start-Sleep -Seconds 3
    }
    if (Test-OllamaReady) {
        Write-BridgeLog 'Ollama local está disponible.'
    } else {
        Write-BridgeLog 'Ollama aún no responde. El conector se ejecutará y reintentará.'
    }

    # -u enables unbuffered logs. The worker owns its own retry loop.
    # This wrapper restarts it if the Python process exits unexpectedly.
    while ($true) {
        try {
            & $python.Source -u $worker 2>&1 | ForEach-Object {
                Write-BridgeLog ([string]$_)
            }
            Write-BridgeLog ('worker.py terminó con código: ' + $LASTEXITCODE)
        } catch {
            Write-BridgeLog ('Error al iniciar el worker: ' + $_.Exception.GetType().Name)
        }
        Start-Sleep -Seconds 20
    }
} catch {
    Write-BridgeLog ('Error al iniciar IRENET: ' + $_.Exception.GetType().Name)
} finally {
    Remove-Item Env:\VITI_IRENET_BRIDGE_TOKEN -ErrorAction SilentlyContinue
    if ($locked) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
