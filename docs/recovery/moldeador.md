# Recuperación del Moldeador de Aplicaciones

## Objetivo
Recuperar la funcionalidad histórica del Moldeador sobre `main` sin fusionar PR antiguas completas ni agregar una segunda fuente de configuración.

## Alcance
- módulos efectivos por aplicación;
- herencia del plan o selección restringida;
- catálogo canónico de módulos;
- branding independiente por aplicación;
- auditoría de cambios.

## Regla de seguridad
Una aplicación nunca puede habilitar un módulo que el plan VITI de su empresa no permite. El frontend solo representa las capacidades; el backend valida y persiste.

## Validación
La implementación debe conservar intactas las rutas normales de AppHub y las aplicaciones entregadas. El modo editor se activa explícitamente con `editor=1` o los campos del editor.
