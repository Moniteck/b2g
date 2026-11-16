# Instrucciones para agentes

Sigue `resources/design/AGENTS.md` y `resources/design/DESIGN_GUIDE.md` en toda interfaz.

Todo PR de tipo módulo o sistema lleva su manual, enviado a CAP Gestión como borrador con el skill `cap-manual`; en el repo solo queda `docs/manuales/<slug>/guion.json`. Ver `docs/manuales/README.md`.

Sigue `database/DATABASE_GUIDE.md` en toda migración, seeder, modelo, vista, procedimiento o consulta SQL.

## Estructura de carpetas

Todo se agrupa por la vista del menú (`resources/js/config/navigation.js`), con el mismo nombre en el frontend y en el backend.

- **Frontend (`resources/js`)**
  - `Pages/<Vista>/Index.vue`: la pantalla principal; `Show.vue` y otras pantallas de la vista van al lado.
  - `Pages/<Vista>/Partials/`: modales, tarjetas, helpers (`*.js`) y estilos de esa vista. Si otra vista necesita una pieza, la importa desde la vista dueña (p. ej. `@/Pages/Quotes/Partials/WonModal.vue` desde Pipeline).
  - `Components/`: piezas genéricas que usan varias vistas, con subcarpetas para las de dominio (`Charts/`, `Mascot/`).
  - `Layouts/` es el marco; `composables/`, `lib/` y `config/` son lógica compartida; `pwa/` es el modo app.
  - Importaciones siempre con alias: `@/…` (resources/js) y `@design/…` (resources/design). Nunca `../`.
- **Backend (`app`)**
  - `Http/Controllers/<Dominio>/` y `Http/Requests/<Dominio>/`. Dominios: Admin, Auth, Calendar, Clients, Contracts, Dashboard, Directory, Documents, Finance, Inventory, Mcp, Pipeline, Pwa, Quotes, Shell, Signatures, Tenders y Tools.
  - `Services/<Dominio>/`: lógica de negocio. Ningún servicio queda suelto en la raíz.
  - `Models/` y `Policies/` quedan planos, como es convención en Laravel.
  - `Mcp/` contiene el servidor MCP y sus tools.
- **Rutas:** `routes/modules/<dominio>.php`, uno por dominio y en snake_case. Se cargan solos dentro de `auth`.
- **Pruebas:** `tests/Feature/<Dominio>/`, con el mismo nombre que el dominio del backend.
