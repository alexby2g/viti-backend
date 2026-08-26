# Integración frontend del Moldeador

La rama backend `feature/platform-hardening` expone el contrato del editor mediante el recurso existente de aplicaciones.

Frontend esperado:
- `GET /api/v1/aplicaciones/{id}?editor=1`
- `PUT /api/v1/aplicaciones/{id}` con `editor=true`

La autorización permanece bajo `platform_admin`, y el servidor valida que los módulos seleccionados pertenezcan al plan de la empresa.
