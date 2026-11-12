# Integración VITI + IRENET — fase 1 (API de mensajes)

## Alcance implementado

- Se conserva el motor de comandos AGR actual: AgrAssistantService, AgrMemoryService y AgrProjectConversionService.
- Se agrega POST /api/v1/assistant/messages para recibir mensajes del asistente de la interfaz.
- La ruta hereda auth:sanctum, throttle:api y platform_admin de routes/api.php.
- El cuerpo es JSON: {"message":"ayuda"}. Se acepta una cadena no vacía de máximo 500 caracteres.
- La respuesta usa el contrato existente (intent, message, data, meta y, para la memoria, memory).
- El comando anterior GET /api/v1/dashboard?agr=... sigue disponible temporalmente por compatibilidad.
- No se modifica la base de datos ni se instala ninguna dependencia nueva.

## Lo que NO está implementado todavía

Esta primera fase no integra IRENET Windows, no conecta modelos Ollama/AnythingLLM y no permite controlar el equipo desde VITI. Solo prepara un canal conversacional web específico, preservando el comportamiento actual del asistente.

## Orden de despliegue

1. Ejecutar php artisan test --filter=AssistantMessageEndpointTest en un entorno de pruebas.
2. Desplegar y comprobar el backend antes de desplegar el frontend.
3. Verificar una sesión de administrador con POST /api/v1/assistant/messages y el comando "ayuda".
4. Desplegar el frontend, confirmar que AGR 006 conserva comandos de navegación, voz y respuestas.
5. Registrar incidencias y solo después planificar el retiro de la consulta por GET.

No ejecutar migrate:fresh ni reseedear datos en producción.

## Próxima fase propuesta: adaptador para IRENET

- Autenticar cada dispositivo del asistente con credenciales revocables, vinculadas a usuario y permisos.
- Mantener IRENET en Windows y hacer que establezca una conexión saliente segura hacia VITI, sin exponer su servidor local a Internet.
- Separar comandos de lectura (permitidos según rol) y comandos que modifican datos (siempre requieren confirmación).
- No enviar prompts ni resultados sensibles a registros de acceso ni a proveedores externos sin controles de privacidad.
- Ejecutar acciones de VITI únicamente mediante la API y la autorización existentes; nunca mediante acceso directo a su base de datos.
- Añadir pruebas de aislamiento entre empresas, denegación de acciones y pérdida de conexión.
