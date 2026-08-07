# VITI Core V16 - Notificaciones internas

- Contador de mensajes no leídos para administrador y cliente.
- Badge rojo en Mi buzón / Buzón de clientes.
- Campana superior con listado de mensajes recientes no leídos.
- Aviso emergente cuando llega un nuevo mensaje mientras VITI está abierto.
- Actualización automática cada 20 segundos y al volver a la pestaña.
- Al abrir una conversación se marcan como leídos únicamente los mensajes recibidos.
- Opción para marcar todas las notificaciones del buzón como leídas.
- Acceso directo desde la notificación a la conversación correspondiente.
- Sin tablas nuevas: usa el campo mensajes.leido_at existente.
- No requiere migrate ni migrate:fresh.
