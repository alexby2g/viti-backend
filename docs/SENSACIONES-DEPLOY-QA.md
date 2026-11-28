# Activación de Sensaciones QA (Render + Neon)

Estado: plan Render validado, sin aplicar. No se ha creado una base de datos Neon QA.
No usar la base VITI ni sus credenciales; no clonar datos reales. No cambiar main.

## 1. Verificar cuotas y coste antes de aprovisionar

- En Render: My Workspace > Billing > Monthly Included Usage. Revisar horas de instancias gratuitas, minutos de build, ancho de banda y límite de gasto. Las horas gratuitas son compartidas por todos los servicios; no activar QA si puede afectar Viti. El plan de un solo servicio fue aceptado por el planificador, lo que no demuestra que exista cuota mensual.
- En Neon: la organización actualmente conectada es Launch (facturación por consumo). Para cumplir un objetivo de cero gasto adicional, usar una organización Free separada si está disponible y autorizada. No cambiar de plan la organización de Viti. Si se acepta un coste de consumo, aprobarlo explícitamente antes de crear un proyecto. No copiar ningún dato productivo.

## 2. Proyecto de pruebas

Crear un proyecto nuevo llamado sensaciones-v4-qa, PostgreSQL 18, región US East compatible con Render Ohio, base sensaciones_qa, sin importar datos. Verificar organización y coste antes de crear. Usar credenciales propias y TLS (sslmode=require para Neon). Conservar DB_URL en el panel de Render, no en Git, chats o archivos compartidos.

## 3. Blueprint

Revisar el plan: https://dashboard.render.com/blueprint/exs-dc52m617lnhs73adf4gg/sync/exe-dc52m9k9v7es738uoec0

- Crea un solo web service Free, rama sensaciones-v4; no adopta servicios existentes.
- AutoSync y despliegue automático desactivados.
- APP_KEY: generar en un entorno confiable mediante `php artisan key:generate --show` y colocar directamente en Render. No reutilizar la clave de Viti.
- DB_URL: copiar exclusivamente la cadena de conexión del nuevo proyecto QA; exigir TLS.
- Antes de aplicar, verificar los dos valores y configurar DB_SSLMODE=require para Neon (la plantilla actual se diseñó también para Postgres interno de Render y usa prefer).
- Mantener RUN_MIGRATIONS=false, RUN_SEEDER=false, SENSACIONES_ENABLED=false y R2 deshabilitado durante el primer arranque.
- Revisar que el nuevo entorno no contenga secretos, SMTP, Firebase, buckets ni credenciales de producción. No activar tareas ni importar usuarios reales.
- Aprobar solo con cuotas verificadas y autorización de aplicar el plan revisado. Confirmar health check y logs; un estado de despliegue aceptado no equivale a servicio operativo.

## 4. Pruebas después de crear QA

Confirmar nombre de la base y proyecto en el proveedor. Ejecutar migrate --pretend y después migrate únicamente en QA. No usar migrate:fresh en Viti. Configurar el origen HTTPS exacto del preview y la API de QA, crear un superadmin de prueba con credenciales únicas, activar Sensaciones y verificar el catálogo y las restricciones de administración. Usar un bucket privado separado antes de activar subidas. Mantener noindex y no promover a main.

## Referencias

- https://render.com/docs/free
- https://render.com/docs/render-dashboard
- https://neon.com/blog/new-usage-based-pricing


## Alternativa sin aprovisionamiento cloud

La rama sensaciones-v4 permite revisar la portada en Vercel Preview sin API (modo VITE_SENSACIONES_OFFLINE_PREVIEW=1); el router redirige rutas privadas al portafolio y no presenta datos de clientes ni trabajos simulados como reales. El diseño muestra los servicios de ejemplo. No es un entorno autenticado ni una prueba del backend.

El workflow backend ejecuta la suite SensacionesPremiumTest con SQLite :memory: y PostgreSQL 18 temporal en GitHub Actions. No utiliza Neon ni secretos productivos. La suite comprueba que el destino PostgreSQL es 127.0.0.1/sensaciones_qa_ci y que DB_URL está vacío antes de ejecutar migraciones del módulo, y revierte las transacciones. Revisar resultados en Actions; el workflow no despliega servicios. El consumo de GitHub Actions está sujeto a sus propios límites. Esto sustituye por ahora la necesidad de aprovisionar una base de datos cloud para las pruebas unitarias/integración del módulo, pero no las pruebas completas de Viti ni de Render/R2.

No aplicar el Blueprint hasta disponer de una base QA persistente y de coste autorizado. No utilizar SQLite temporal para almacenar trabajo real en Render: se pierde al reiniciar o redeplegar.
