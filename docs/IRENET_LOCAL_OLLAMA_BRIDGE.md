# Puente VITI ↔ IRENET (Ollama local qwen2.5:7b)

## Alcance de la primera versión

El botón **IRENET local** de AGR 006 usa la API de VITI para enviar consultas
cortas a un agente **iniciado manualmente** en un equipo Windows. El agente solo
hace HTTPS saliente hacia Render y se conecta a Ollama en
`http://127.0.0.1:11434/api/chat`. No abre puertos al exterior, no ejecuta
comandos Windows, no utiliza la clave de Ollama Cloud y no escribe en proyectos.

Se usan dos canales diferentes:
- Página VITI -> Render: sesión autenticada con permisos de administrador.
- PC IRENET -> Render: clave aleatoria independiente de mínimo 32 caracteres.
  Dicha clave NO sirve para acceder a otras APIs de administración de VITI.

Los mensajes y respuestas se guardan cifrados mediante el APP_KEY de Laravel en
la tabla `agr_local_inference_jobs`. Los trabajos expiran a los siete minutos;
las entradas de más de un día se eliminan durante la creación de nuevos trabajos.
No se deben incluir datos sensibles en prompts. El agente nunca necesita recibir
contraseñas de VITI, cookies de navegador ni credenciales PostgreSQL.

## Preparación (solo después de validar CI)

1. En PowerShell 5.1 crear un secreto aleatorio en la memoria de esa ventana:

       $bytes = New-Object byte[] 32
       $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
       $rng.GetBytes($bytes)
       $rng.Dispose()
       $secret = [Convert]::ToBase64String($bytes)
       $env:VITI_IRENET_BRIDGE_TOKEN = $secret

   En Render -> Environment configurar `IRENET_LOCAL_BRIDGE_TOKEN` con ese
   mismo valor, y luego `IRENET_LOCAL_BRIDGE_ENABLED=true`.

   La variable de PC solo dura esa sesión de PowerShell. Mantén la ventana
   abierta mientras se ejecuta el conector. No copies el secreto a repositorios,
   chats o capturas. Para reiniciar en otro momento debes almacenarlo de forma
   segura y restaurarlo a la variable de entorno, o generar y configurar otro.

2. Asegurarse de que Ollama está corriendo localmente y que está instalado
   `qwen2.5:7b` (comprobado previamente con `ollama list` y `ollama run`).

3. Obtener el archivo desde el repositorio GitHub de VITI (por git clone o
   inspeccionando y guardando `integrations/irenet-local/worker.py`).
   Desde la raíz del repositorio ejecutar:

       python integrations/irenet-local/worker.py

   También puede usarse `py -3`. El worker usa solo Python estándar,
   necesita Python 3.10+ y la variable de entorno de sesión.

4. En la web VITI pulsar `IRENET local: desactivado` para activarlo, con
   `IA Ollama` desactivado, y preguntar por un sistema de inventario.
   El modo local solo responde a preguntas desconocidas por AGR 006.

## Fallback y seguridad

- El puente está **apagado por defecto**. No desplegar con la clave vacía.
- Si el PC no está disponible, los trabajos expiran y el frontend lo informa.
- Cifrado en tránsito TLS hacia Render y cifrado de textos en la DB de VITI.
- Las respuestas locales son solo texto para no enviarlas por la ruta de voz
  externa de VITI. Comandos tradicionales y su voz siguen disponibles.
- Las tareas están ligadas al usuario del portal; otro administrador no puede
  consultar los resultados de ese usuario.
- La clave del worker solo permite reclamar y cerrar tareas de texto mediante
  endpoints dedicados. No autoriza acceso a los endpoints de clientes, pagos,
  archivos ni sistemas.
- No hay puertos entrantes en el router del hogar ni túneles HTTP abiertos.
- Para revocar un agente: desactivar IRENET_LOCAL_BRIDGE_ENABLED o rotar
  IRENET_LOCAL_BRIDGE_TOKEN en Render.
- No es todavía una integración del ejecutable Irenet.exe ni da autonomía
  para editar código o realizar tareas sobre los sistemas.

## Pruebas

- `php artisan test --filter=AgrIrenetLocalBridgeTest`
- `python -m unittest discover -s integrations/irenet-local -p 'test_*.py'`
- `npm run test:viti-irenet-local` en el repositorio frontend.

Antes de fusionar cambios a producción, revisar migración aditiva,
autorizaciones, pruebas de aislamiento y compatibilidad de frontend.
