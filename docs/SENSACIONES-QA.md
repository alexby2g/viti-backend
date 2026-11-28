# Sensaciones 4.0 — Fase de integración 2

No desplegar en producción hasta aprobar QA. Las ramas main y los datos de Viti no se deben modificar durante las pruebas.

## Configuración de prueba
- Base de datos PostgreSQL aislada (copia saneada o nueva), no la de producción.
- Bucket R2 privado separado de producción; configurar las variables existentes de private_uploads con credenciales limitadas al bucket de prueba.
- SENSACIONES_ENABLED=true
- SENSACIONES_DIRECT_UPLOADS=true solo tras verificar el almacenamiento.
- php artisan migrate --pretend y revisar el SQL; php artisan migrate en la base de prueba.
- No usar migrate:fresh sobre datos existentes.
- Autorizar el origen HTTPS exacto del frontend de prueba en CORS R2; AllowedMethods PUT, GET, HEAD; AllowedHeaders Content-Type (y los encabezados realmente exigidos por la firma); revisar preflight.
- Configurar regla de ciclo de vida para eliminar objetos bajo sensaciones/staging/ después de 1 día. No aplicar al prefijo sensaciones/media/. Las filas de sesiones vencidas requieren limpieza futura.
- El flujo directo usa PUT firmado por 15 minutos, tamaño declarado 250 MiB máximo (15 MiB fotos), copia a clave nueva y verifica tamaño y firma de encabezado. El encabezado no equivale a validar todo el archivo ni a escanear malware. Solo superadministradores pueden cargar. No habilitar cargas de terceros.
- Probar la compatibilidad AWS SDK/R2 (incluidos checksums y firma), expiración, CORS, reproducción Range y acceso a originales; deshabilitar cargas directas si alguna prueba falla.
- Los archivos públicos se sirven mediante URLs temporales de 5 minutos; retirar un archivo no invalida una URL ya emitida hasta que expire.
- Para una vista previa Vercel, no usar la API de producción: cambiar rewrites y VITE_API_URL al backend de prueba y configurar Sanctum para el origen de prueba.

## Criterios de aceptación
1. Con módulo desactivado, catálogo no disponible; rutas Viti existentes sin cambios.
2. Anónimo y usuario no superadmin no pueden subir ni publicar.
3. Borrador no se muestra ni se puede abrir sin autorización.
4. Carga directa de MP4 >3 MiB: progreso, verificación y borrador; no se publica automáticamente.
5. Tamaño discrepante, firma vencida, tipo inválido y sesión de otro usuario se rechazan.
6. Álbum solo fotos, proyecto fotos/videos, comparador exactamente dos fotos en orden.
7. Retirar una foto impide que aparezca en colecciones públicas; verificar expiración de URL previa.
8. Compilar frontend con npm ci && npm run build y ejecutar pruebas en entorno aislado.
9. Verificar regresiones de Viti (clientes, empresas, portal, aplicaciones).

Pendiente: importador de datos Sensaciones 3.0, marca y SEO, video de presentación, watermark real, pruebas R2, limpieza automatizada, despliegue y cambio de dominio.

## Fase 3 — Premium
- Ejecutar `php artisan test --filter=SensacionesPremiumTest` solo en testing con SQLite :memory:.
- Configurar `SENSACIONES_SITE_URL` con la URL HTTPS pública verificada, únicamente para el sitemap. No usar dominio de producción en staging indexable; proteger previews y bloquear su indexación. El sitemap se sirve en `/api/v1/sensaciones/sitemap.xml`; enviar la URL definitiva desde Search Console después de publicar. Los títulos y descripciones actuales se aplican en Vue; SSR, Open Graph para bots y SEO por proyecto siguen pendientes.
- En `/sensaciones/studio/marca` elegir una foto y video publicados, colores, servicios y WhatsApp internacional (solo dígitos). No hay número de contacto preconfigurado.
- `SENSACIONES_WATERMARK_AVAILABLE=true` únicamente tras instalar/verificar GD con JPEG, PNG y WebP; probar consumo de memoria, transparencia y orientación EXIF. Marca de agua opcional, máximo provisional 6 MP por foto y 1 MP por logo; fuera del límite no se sirve el original cuando está activada. El logo seleccionado está excluido; originales permanecen privados. No retirar el logo mientras la función esté activa. URLs temporales ya emitidas antes de habilitar la marca pueden seguir válidas hasta expirar. Sin caché de derivados por ahora; no usar en alto tráfico sin pruebas de carga. El logo en el reproductor no se incrusta en el video.
- La aplicación aún requiere pruebas integrales, importación de datos 3.0, validación de R2 y despliegue aislado. No promover main.
