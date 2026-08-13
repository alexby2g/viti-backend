# VITI · Procedimiento de respaldo y recuperación

Este documento define el procedimiento mínimo para recuperar la base de datos PostgreSQL de VITI sin poner en riesgo la base activa.

## Principios

1. Nunca ejecutar `pg_restore` directamente sobre la base de datos de producción.
2. Toda copia debe tener checksum SHA-256 coincidente y superar `pg_restore --list` antes de considerarse utilizable.
3. La primera restauración siempre se realiza en una base PostgreSQL aislada y vacía.
4. No eliminar respaldos verificados durante una incidencia.
5. Las credenciales de producción no se escriben en documentación, logs, comandos compartidos ni tickets.
6. Un respaldo no se considera probado únicamente porque exista en almacenamiento: debe existir evidencia de restauración exitosa.

## Estados de una copia

- `running`: VITI está generando o verificando la copia.
- `verified`: el archivo remoto conserva su checksum, tamaño esperado y PostgreSQL puede interpretar el archive.
- `failed`: alguna etapa de creación o verificación falló.

## Política inicial de retención

Mientras VITI no tenga un historial suficiente de simulacros periódicos:

- conservar como mínimo 7 copias verificadas;
- considerar saludable una copia verificada durante las últimas 48 horas;
- objetivo inicial de conservación: 30 días;
- borrado automático de copias: desactivado.

La retención podrá automatizarse cuando exista evidencia recurrente de restauraciones exitosas.

## Simulacro de recuperación en CI

El workflow `Backend CI` ejecuta un job independiente llamado `backup-restore-drill`.

El job:

1. inicia PostgreSQL 17 en un contenedor aislado;
2. ejecuta todas las migraciones de VITI;
3. crea un dato centinela exclusivo del simulacro;
4. genera un archive con `pg_dump --format=custom`;
5. valida el archive mediante `pg_restore --list`;
6. crea una segunda base de datos vacía;
7. restaura el archive en esa segunda base;
8. comprueba el historial de migraciones, la tabla `system_backups` y el dato centinela.

Un fallo en cualquiera de estas etapas debe bloquear la integración del PR.

## Recuperación ante una incidencia real

### 1. Contener

- detener cambios de esquema y despliegues no esenciales;
- identificar la hora aproximada del incidente;
- no borrar registros ni archivos para "limpiar" el problema;
- registrar qué versión de frontend y backend estaba desplegada.

### 2. Seleccionar una copia

Desde el Centro de Salud de VITI:

- seleccionar la última copia `verified` anterior al incidente;
- ejecutar nuevamente la verificación de integridad;
- registrar fecha, tamaño y checksum SHA-256.

Si la copia no supera la verificación, utilizar la copia verificada inmediatamente anterior.

### 3. Preparar un destino aislado

Crear una base PostgreSQL temporal con una versión compatible con producción. La base debe estar vacía y no debe recibir tráfico de usuarios.

### 4. Validar el archive antes de restaurar

Ejecutar:

```bash
pg_restore --list viti-backup.dump
```

El comando debe terminar correctamente y listar objetos del esquema esperado.

### 5. Restaurar en la base aislada

Ejecutar únicamente contra la base temporal:

```bash
pg_restore \
  --no-owner \
  --no-privileges \
  --dbname=<BASE_AISLADA> \
  viti-backup.dump
```

No utilizar el nombre o la URL de producción en esta etapa.

### 6. Validar funcionalmente

Como mínimo comprobar:

- tabla `migrations` y última migración;
- usuarios y empresas esperadas;
- solicitudes y proyectos;
- pagos y comprobantes;
- aplicaciones VITI y sus relaciones;
- conversaciones y documentos relevantes;
- aislamiento multiempresa;
- acceso de Superadmin;
- ausencia de errores de integridad referencial.

Después ejecutar una prueba controlada del flujo principal:

`Login → Empresa → Solicitud → Proyecto → Pago → Aplicación → Mensaje`

### 7. Decisión de recuperación

Solo después de validar la base aislada se define cómo recuperar producción. La decisión debe considerar:

- cuánto dato posterior al respaldo se perdería;
- si es posible corregir producción sin reemplazarla;
- si conviene promover una base recuperada o ejecutar una migración/corrección puntual;
- ventana de mantenimiento requerida.

## Objetivos iniciales

Estos valores son metas operativas iniciales, no garantías contractuales:

- RPO objetivo: hasta 24 horas cuando el respaldo diario esté automatizado.
- RTO objetivo inicial: 2 a 4 horas para diagnóstico, restauración aislada y validación.

Los objetivos deberán revisarse después de los primeros simulacros reales de recuperación.

## Evidencia mínima de un simulacro

Registrar:

- fecha;
- commit del backend;
- versión PostgreSQL;
- duración de `pg_dump`;
- duración de `pg_restore`;
- tamaño del archive;
- resultado de SHA-256;
- resultado de `pg_restore --list`;
- resultado de comprobaciones de datos;
- observaciones y correcciones necesarias.

## Regla final

La existencia de un archivo no equivale a tener recuperación ante desastres. VITI considera confiable el proceso únicamente cuando una copia puede verificarse y restaurarse en un entorno aislado de forma repetible.
