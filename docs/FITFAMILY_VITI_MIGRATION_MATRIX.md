# FitFamily → VITI: matriz de migración

Estado: trabajo técnico sobre `feat/fitfamily-unified`.

## Regla de arquitectura

FitFamily mantiene Store y Admin como frontends independientes, pero consume el backend Laravel de VITI y la misma PostgreSQL. Sus datos de negocio deben quedar aislados por `empresa_id` y, cuando aplique, `aplicacion_id`.

El backend Node/Express + Prisma del ZIP se conserva únicamente como respaldo/referencia durante la transición.

## Matriz funcional

| Función del FitFamily de referencia | Destino | Acción |
|---|---|---|
| Catálogo por categorías | `fitfamily_categorias` + `fitfamily_productos` | Adaptar |
| Producto visible/disponible | columnas `visible`, `disponible` + `stock` | Adaptar y endurecer |
| Precio calculado en servidor | `FitFamilyController` | Mantener y reforzar |
| Carrito | `fitfamily_carritos` + `fitfamily_carrito_items` | Mantener/adaptar |
| Checkout | `fitfamily_pedidos` + detalles | Mantener/adaptar |
| Número de pedido | `fitfamily_pedidos.numero` | Mantener |
| Seguimiento público | endpoint público FitFamily | Adaptar al contrato VITI |
| Caja | frontend Admin/Caja + pedidos | Portar experiencia |
| Estados NEW→CONFIRMED→PREPARING→READY→DELIVERED | estados VITI | Mapear; no copiar enum Prisma |
| Cancelación con devolución de stock | controlador FitFamily | Mantener y probar transacción |
| Admin productos | rutas admin FitFamily | Mantener |
| Admin categorías | rutas admin FitFamily | Mantener |
| Estadísticas del día | dashboard FitFamily | Adaptar a VITI |
| Login JWT del ZIP | Sanctum VITI | Eliminar |
| User propio del ZIP | `usuarios` VITI | Eliminar |
| Role ADMIN/CASHIER propio | roles/permisos VITI | Adaptar |
| PostgreSQL propia | PostgreSQL VITI | Eliminar |
| Prisma | Eloquent/DB de Laravel | Eliminar |
| Socket.IO | capa de eventos/notificaciones VITI | Reemplazar/adaptar; no duplicar backend |
| `imageUrl` libre | Storage de VITI + referencia de archivo | Reemplazar |
| Docker Compose de PostgreSQL | infraestructura VITI | No migrar |

## Diferencias que debemos corregir

1. El ZIP usa `Category.name` y `slug` globalmente únicos. En VITI deben quedar dentro del tenant FitFamily.
2. El ZIP no tiene `empresa_id` ni `aplicacion_id`. VITI sí debe aplicarlos a todas las consultas de FitFamily.
3. El ZIP permite cambiar estados de pedido de forma más libre. VITI debe conservar transiciones válidas y registrarlas en auditoría.
4. El ZIP confía en un JWT separado. La integración usa Sanctum y la autorización de VITI.
5. El ZIP usa URL de imagen. La integración final debe poder usar Storage de VITI.
6. El ZIP tiene un flujo `READY` explícito. El contrato actual del módulo VITI usa `entregado` como siguiente paso desde `preparando`; debemos resolver el estado operativo `listo` sin perder la experiencia de Caja.

## Criterio de aceptación de la migración

- Store carga catálogo desde VITI.
- Store agrega productos al carrito y calcula totales usando datos del backend.
- Checkout crea pedido aislado en la empresa FitFamily.
- Admin puede gestionar categorías y productos de FitFamily sin acceder a otra empresa.
- Caja/Admin puede ver y avanzar pedidos respetando transiciones.
- Cancelar un pedido devuelve stock dentro de una transacción.
- Seguimiento del pedido funciona sin exponer datos de otras empresas.
- Las imágenes no dependen de URLs arbitrarias cuando se complete Storage.
- El backend independiente de FitFamily sigue disponible como respaldo hasta completar estas pruebas.
