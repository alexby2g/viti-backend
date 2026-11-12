# AGR 006 + Ollama Cloud (modo optativo)

## Diseño

AGR 006 conserva sus comandos, navegación y consultas Laravel. El usuario puede activar **IA Ollama** en la interfaz. Solo los mensajes que el motor determinista no reconozca se envían a Ollama Cloud a través del backend.

El modelo recibe únicamente el mensaje actual y hasta seis turnos escritos en el modo IA. No recibe automáticamente registros privados de VITI, archivos, clientes, proyectos ni tokens. La respuesta es únicamente texto, sin acciones ni permisos de escritura.

## Configurar el servicio Render

En el servicio viti-core-api-alexby2g → Environment, agregar estas variables:

- AGR_OLLAMA_CLOUD_ENABLED=true
- OLLAMA_API_KEY=(clave API creada en su propia cuenta Ollama)
- AGR_OLLAMA_CLOUD_MODEL=gemma4:31b (o el modelo disponible en la cuenta)

Guardar los secretos únicamente en Render. No enviarlos por chat ni incorporarlos a GitHub.

El endpoint utilizado es HTTPS https://ollama.com/api/chat; localhost:11434 en Render NO representa el Ollama instalado en el equipo Windows. Para integrar un Ollama local hará falta un agente saliente autenticado y no exponer el puerto 11434.

## Pruebas y límites

- Ejecutar php artisan test --filter=AgrOllamaGatewayTest y el CI completo.
- Probar en el navegador la pregunta: «Explícame los requisitos de un sistema de inventario».
- Probar los comandos existentes: «resumen» y «abrir solicitudes».
- Activación explícita por sesión, desactivada inicialmente.
- Solo usuarios administradores autenticados, con límite 8 peticiones/min y 90/hora.
- Respuestas Ollama de solo lectura y sin ejecución de acciones.
- Sin modelo/configuración: devuelve 503 sin llamar a Ollama.
- No enviar secretos ni información confidencial a Ollama Cloud. Revisar sus políticas y costos.
- Futuras herramientas con acceso a datos requerirán permisos Laravel y aprobación por acción.
