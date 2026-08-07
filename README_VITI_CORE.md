# VITI Core

VITI ya no contiene el flujo de una empresa técnica específica. Es la plataforma interna de AGR Studio para:

1. Registrar a la persona cliente.
2. Registrar su empresa o microempresa.
3. Crear una solicitud de sistema.
4. Entregar al cliente un enlace público con el cuestionario de 70 preguntas.
5. Analizar la solicitud y convertirla en proyecto.
6. Controlar las fases: levantamiento, análisis, diseño, desarrollo, beta, pruebas, ajustes, implementación, finalizado y mantenimiento.
7. Integrar la aplicación entregada dentro de VITI.
8. Supervisar soporte, mejoras y mantenimiento.
9. Subir imágenes, documentos, referencias y entregables.
10. Descargar reportes y cuestionarios en PDF.

## Base completamente limpia

El comando solicitado es destructivo y borra las tablas anteriores:

```bash
php artisan migrate:fresh --seed
```

Después de ejecutarlo no existirán usuarios, clientes, empresas, solicitudes, proyectos ni aplicaciones de demostración.

El único contenido instalado por el seeder es el **Cuestionario para Definir un Sistema**, porque es parte estructural de VITI y debe existir en la base de datos.

## Instalación local con Laragon

```bash
cd C:\laragon\www\viti\backend
composer install
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
php artisan optimize:clear
php artisan serve --host=localhost --port=8000
```

En otra consola:

```bash
cd C:\laragon\www\viti\frontend
npm install
npm run dev
```

Abrir:

```text
http://localhost:9000
```

Al no existir usuarios, VITI redirige automáticamente a:

```text
/configuracion-inicial
```

Ahí se crea el primer superadministrador usando nombre, usuario, teléfono y contraseña. El correo no es obligatorio.

## Flujo de trabajo

```text
Cliente
  ↓
Empresa
  ↓
Solicitud de sistema
  ↓
Cuestionario público
  ↓
Revisión y aprobación
  ↓
Proyecto
  ↓
Beta y pruebas
  ↓
Aplicación integrada
  ↓
Mantenimiento y soporte
```

## Cuestionario público

Al crear una solicitud, VITI genera un enlace seguro como:

```text
http://localhost:9000/solicitar/<token-seguro>
```

El cliente puede abrirlo sin iniciar sesión, guardar un borrador y enviarlo cuando complete las preguntas obligatorias y acepte la declaración final.

## Archivos

Para las imágenes y documentos se usa el disco `public`. Por eso debe ejecutarse una vez:

```bash
php artisan storage:link
```

Tipos permitidos: imágenes, PDF, Word, Excel, texto y ZIP, con un máximo de 10 MB por archivo.

## Reportes PDF

- Directorio de clientes.
- Empresas registradas.
- Cuestionario completo de una solicitud.
- Resumen e historial de un proyecto.

## Seguridad

- Sesión web mediante cookie HttpOnly y Laravel Sanctum.
- Protección CSRF.
- Contraseñas Argon2id.
- Límite de intentos de inicio de sesión.
- Primer administrador creado mediante asistente inicial.
- Enlaces públicos con tokens aleatorios de 48 caracteres.
- Auditoría de acciones administrativas.
- Los usuarios de soporte y administración no pueden gestionar usuarios internos ni consultar auditoría; esas funciones quedan reservadas al superadministrador.
