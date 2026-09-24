# Sincronía frontend ↔ backend (VITI)

Auditoría del contrato entre `alexby2g/viti-frontend` (Quasar/Vue en Vercel) y
`alexby2g/viti-backend` (Laravel 12 en Render), revisada el **2026-09-24**.

## Cómo se revisó

No hay PHP en el entorno de trabajo, así que el contrato se extrajo por análisis
estático de ambos repos:

- **Backend:** se parsearon los 10 archivos de `routes/` **y los 4 Service
  Providers que también registran rutas** (`Billing`, `Branding`,
  `PeluqueriaApp`, `Saas`), resolviendo prefijos anidados, cadenas fluidas
  multi-línea, `apiResource` y las closures compartidas
  (`->group($serviceTechnicalRoutes)`).
- **Frontend:** se extrajeron todas las llamadas `api.get/post/put/delete` de
  `src/`, resolviendo bases dinámicas (`/apps/...` vs `/mi/apps/...`), ternarios
  y objetos de endpoints.

Resultado: **546 llamadas → 392 endpoints únicos** del lado del frontend, contra
**537 rutas** del lado del backend.

## Estado: alineados

| Área | Endpoints frontend | Cubiertos por backend |
|---|---|---|
| `/apps/servicio-tecnico` y `/mi/apps/servicio-tecnico` | 64 | 64 ✅ |
| `/portal/electrofrio` | 10 | 10 ✅ |
| `/soporte` | 6 | 6 ✅ |
| `/publico/*` (solicitudes e invitaciones) | 9 | 9 ✅ |
| `/auth/*` | 6 | 6 ✅ |
| `/apps/electrofrio` y `/mi/apps/electrofrio` | 118 | 112 ✅¹ |
| Peluquería (admin + cliente) | 43 | 37 ✅¹ |
| Resto (`/mi/*`, core, SaaS, branding) | 148 | 142 ✅¹ |

¹ Los 14 pendientes son variables que no se pueden resolver de forma estática
(`let endpoint` que se asigna dentro de `if`, del tipo
`${base}/${endpoint}/${id}`). Revisados a mano, todos apuntan a rutas que sí
existen (`clientes`, `servicios`, `personal`, `citas`, `atenciones`,
`materiales`, `ordenes`, `tecnicos`, `equipos`).

**Conclusión: no hay endpoints del frontend que queden sin ruta en el backend.**
Los 171 endpoints del backend que el frontend no consume son rutas de
administración, móvil y compatibilidad heredada; no son un problema.

## Hallazgos corregidos

### 1. El logotipo de la plataforma no se servía desde R2 (bug real)

`PlatformBrandingController` devolvía `logo_path` pero **no** `logo_url`, a
diferencia de `Cliente`, `Empresa` y `Usuario`, que sí exponen su URL ya
resuelta. El frontend (`stores/branding.js`) hacía:

```js
logoUrl: state => state.logo_path ? mediaUrl(state.logo_path) : ''
```

y `mediaUrl` sin `VITE_MEDIA_URL` cae en `/storage/...`, es decir, al disco local
de Render. Con `PUBLIC_FILESYSTEM_DRIVER=s3` el archivo vive en R2, así que el
logo daba 404 en producción.

**Arreglo:** accessor `logo_url` en `App\Models\PlatformBranding` (misma lógica
que los otros modelos) y `logo_url` incluido en el payload del controlador.
Cubierto por dos tests nuevos en `tests/Feature/PlatformBrandingTest.php`.

> Requiere un cambio de una línea en el frontend para aprovecharlo (el backend
> ya es compatible con ambos):
> `logoUrl: state => state.logo_url || mediaUrl(state.logo_path)`

### 2. CORS cerrado a los despliegues de preview de Vercel

`FRONTEND_URLS` y `SANCTUM_STATEFUL_DOMAINS` solo aceptaban
`viti-frontend.vercel.app`. Cualquier preview (`viti-frontend-git-rama.vercel.app`,
`viti-frontend-abc123.vercel.app`) recibía el preflight bloqueado y no podía
iniciar sesión.

**Arreglo (opt-in, no cambia nada si no se configura):**
- `config/cors.php` ahora lee `FRONTEND_URL_PATTERNS` para
  `allowed_origins_patterns`.
- `render.yaml` define el patrón acotado al proyecto y añade el comodín
  `*.vercel.app` a `SANCTUM_STATEFUL_DOMAINS` (Sanctum compara con `Str::is`,
  así que el comodín funciona).

### 3. Verificaciones realizadas

- `npm run lint` → 0 errores (6 warnings de variables sin usar).
- `npm run build` → **Build succeeded**.
- Los 299 archivos PHP de `app/`, `config/`, `routes/`, `bootstrap/` y
  `database/` parsean correctamente.

## Lo que no pude comprobar

El entorno de trabajo tiene el tráfico de salida filtrado: `curl` a
`viti-core-api-alexby2g.onrender.com` y a `viti-frontend.vercel.app` falla, así
que **no verifiqué el estado en vivo** de Render, Neon ni Vercel. Conviene
confirmar a mano:

- `GET https://viti-core-api-alexby2g.onrender.com/api/health` → `{"status":"ok"}`
- `GET https://viti-core-api-alexby2g.onrender.com/api/v1/auth/status`
- Que el dominio real de Vercel coincide con `viti-frontend.vercel.app` (si es
  otro, hay que actualizarlo en `render.yaml` y en `FRONTEND_URL_PATTERNS`).

## Deuda menor (sin impacto hoy)

- Los scripts `scripts/check-viti-*.mjs` del frontend asumen una carpeta hermana
  `backend/`; al estar los repos separados fallan en local. No corren en el CI
  (`ci.yml` solo ejecuta lint y build), así que no rompen nada.
- Las rutas están repartidas entre 10 archivos en `routes/` y 4 Service
  Providers. Funciona, pero cuesta encontrarlas; unificar sería una tarea aparte.
