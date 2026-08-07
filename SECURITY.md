# Seguridad de VITI Core

- La SPA usa Laravel Sanctum con cookie de sesión HttpOnly.
- Las credenciales no se almacenan en `localStorage`.
- Las solicitudes de escritura usan protección CSRF.
- Las contraseñas se protegen con Argon2id.
- El inicio de sesión está limitado por usuario/teléfono e IP.
- La primera cuenta solo puede crearse cuando la tabla de usuarios está vacía.
- Los cuestionarios públicos usan tokens aleatorios de 48 caracteres y pueden deshabilitarse desde la solicitud.
- Las acciones administrativas importantes se registran en auditoría.
- En producción use HTTPS, `APP_DEBUG=false`, cookies seguras y credenciales diferentes a las de desarrollo.
