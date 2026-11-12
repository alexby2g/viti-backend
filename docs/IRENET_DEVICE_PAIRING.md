# IRENET + VITI: emparejamiento de solo lectura

Esta fase incorpora credenciales **exclusivas de dispositivo**, para que IRENET en Windows consulte un resumen agregado de VITI sin exponer el puente local del escritorio a Internet.

## API

- POST /api/v1/irenet/dispositivos — requiere sesión de superadministrador, recibe JSON {"nombre":"Mi equipo Windows"}, devuelve un token ivt_... **una sola vez**.
- GET /api/v1/irenet/dispositivos — lista los dispositivos del superadministrador actual sin tokens ni hashes.
- DELETE /api/v1/irenet/dispositivos/{id} — revoca inmediatamente uno de sus propios dispositivos.
- GET /api/v1/irenet/context — requiere el encabezado Authorization: Bearer ivt_...; devuelve solo conteos operativos.

La credencial vence en 14 días, admite hasta cinco dispositivos activos por usuario y se puede revocar. Se guarda solamente el hash SHA-256 de un token criptográficamente aleatorio de 256 bits. Si la cuenta superadministradora queda deshabilitada, también se bloquea el acceso.

La credencial de IRENET **no** es un token de Laravel Sanctum: no permite acceder a otras APIs, iniciar sesión, leer registros de clientes ni modificar datos.

## Ejemplo de solicitud HTTPS

Nunca enviar el token en una URL ni registrarlo en archivos de proyecto.

~~~http
GET /api/v1/irenet/context HTTP/1.1
Host: api.tu-dominio-viti.example
Authorization: Bearer ivt_TOKEN_EMITIDO
Accept: application/json
~~~

Respuesta ilustrativa:

~~~json
{
  "data": {
    "tipo": "resumen_operativo",
    "solo_lectura": true,
    "consultado_at": "2026-11-12T10:00:00-04:00",
    "metricas": {
      "clientes": 0,
      "empresas": 0,
      "solicitudes_en_revision": 0,
      "proyectos_activos": 0,
      "soportes_abiertos": 0
    }
  }
}
~~~

## Reglas de seguridad

1. Usar HTTPS con verificación de certificado. Nunca publicar el servidor local IRENET ni abrir el puerto 8765 a Internet.
2. Crear y revocar dispositivos únicamente mediante un superadministrador con sesión activa.
3. La consulta no incluye nombres, teléfonos, correos, documentos, pagos individuales ni contenido de proyectos.
4. Cada solicitud valida el dispositivo, su vigencia, su revocación y el estado del superadministrador.
5. La integración no ejecuta comandos Windows, no sincroniza memoria ni realiza acciones de escritura empresariales.

## Pruebas

Con base de datos aislada:

~~~bash
php artisan test --filter=IrenetDeviceConnectionTest
php artisan route:list --path=api/v1/irenet
~~~

En producción, **no ejecutar migrate:fresh**. Después de aprobar los PR y verificar un respaldo, la migración de la nueva tabla se ejecutaría de forma incremental con php artisan migrate --force.

La integración de IA avanzada y de acciones en Windows será una fase distinta, con permisos, auditoría y confirmaciones adicionales.
