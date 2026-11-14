# VITI + IRENET: inicio automático en Windows 10/11

Este mecanismo inicia el puente local de IRENET para AGR 006 y, si hace falta,
el servidor Ollama al entrar a la cuenta Windows. El portal VITI ya está
publicado en Vercel y Render: no requiere abrir una consola.

NOTA: este arranque es para el puente Python que conecta VITI con Ollama.
No inicia el ejecutable de escritorio Irenet.exe ni modifica esa aplicación.

## Requisitos

- PowerShell 5.1, Python y Ollama instalados para el usuario Windows actual.
- El repositorio clonado en C:\Users\HP\VITI-IRENET-bridge (o la carpeta
  correspondiente a su propio USERPROFILE), con worker.py funcionando.
- El modelo qwen2.5:7b instalado.
- La misma clave del conector de Render, nunca una clave inventada adicional.

## Instalación única

1. En la ventana PowerShell donde está funcionando worker.py, presionar
   Ctrl+C para detenerlo. No cerrar la ventana: normalmente conserva
   la variable de entorno VITI_IRENET_BRIDGE_TOKEN.

2. Ejecutar estos dos comandos en esa misma ventana:

       git -C "$env:USERPROFILE\VITI-IRENET-bridge" pull --ff-only

       powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\VITI-IRENET-bridge\integrations\irenet-local\setup-autostart.ps1"

   Si falta la clave en esa consola, el instalador solicita la MISMA clave
   ya configurada en Render usando una entrada oculta. No compartirla en
   chats, capturas o repositorios. Si se perdió, hay que rotarla en Render
   y volver a instalar. La clave de Ollama Cloud no sirve para este puente.

3. El instalador escribe el token cifrado con DPAPI CurrentUser en
   %LOCALAPPDATA%\VITI-IRENET\bridge-token.dpapi y crea el acceso directo
   "VITI - IRENET local" en la carpeta Inicio del usuario (shell:startup).
   El pequeño lanzador VBS guardado en LocalAppData no contiene la clave.
   No requiere permisos de administrador.

4. Cerrar sesión Windows y volver a entrar. Iniciará oculto, sin CMD.
   Después de iniciar sesión, abrir VITI y activar "IRENET local" en AGR 006.
   Mantener la computadora encendida y con la sesión Windows abierta.

## Cómo revisar el estado

En una ventana PowerShell solo para diagnóstico:

    Get-Content "$env:LOCALAPPDATA\VITI-IRENET\bridge.log" -Tail 30

Resultados útiles:
- Ollama local está disponible.
- IRENET conectado a VITI mediante HTTPS. Modelo: qwen2.5:7b
- Respuesta enviada a VITI.
- HTTP 403 significa que la clave local probablemente no coincide con Render.

Si el log no aparece, comprobar que el acceso directo sigue habilitado en
shell:startup y que Windows no bloqueó la ejecución del lanzador.

## Desactivar y revertir

Para impedir el inicio automático, presionar Win+R, introducir
shell:startup y eliminar solamente "VITI - IRENET local".

También se puede ejecutar este script desde la carpeta del repositorio:

    powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$env:USERPROFILE\VITI-IRENET-bridge\integrations\irenet-local\remove-autostart.ps1" -ForgetToken

La opción -ForgetToken elimina también el archivo cifrado de la clave local;
no cambia el secreto de Render, ni borra datos, ni desinstala Ollama.
Eliminar el acceso directo no detiene un proceso ya activo: cerrar sesión
Windows termina ese proceso oculto.

## Seguridad y limitaciones

- El token no está guardado en texto plano, no aparece en los argumentos
  del proceso y no se sube a GitHub.
- DPAPI protege la clave entre usuarios/máquinas, pero malware ejecutado
  como el mismo usuario podría obtener acceso al proceso o clave.
- La conexión VITI se realiza por HTTPS saliente y el modelo solo escucha
  en 127.0.0.1; no se abren puertos entrantes para Internet.
- El puente se ejecuta después del inicio de sesión Windows, no antes.
- El servidor web VITI seguirá funcionando cuando la PC esté apagada,
  pero las preguntas a Ollama local no podrán responderse.
- Las acciones de editar código, ejecutar comandos o escribir datos
  siguen deshabilitadas en el agente.
