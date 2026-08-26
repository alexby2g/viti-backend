# VITI platform audit notes

This branch contains hardening changes identified during a cross-layer review of the VITI platform.

## Verified observations
- Client portal routes reference `ClientPortalController` and the controller exists under `app/Http/Controllers`.
- Frontend uses Vue 3 + Quasar and requires Node >= 22.22.0.
- AGR already exposes health, priorities, workflow recommendations, permissions, incident/recovery and learning services.

## Rule for future changes
Prefer integrating existing capabilities before adding new modules or duplicate services.
