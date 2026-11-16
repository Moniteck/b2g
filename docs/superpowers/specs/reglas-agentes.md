# Reglas comunes para los agentes de módulo

Proyecto: CRM B2G (Laravel 13, Inertia 3, Vue 3 `<script setup>`, Tailwind 4, MySQL `crm_b2g`).
Rama: `feat/flujo-licitaciones`. Varios agentes trabajan a la vez en el mismo árbol de archivos.

## Lecturas obligatorias antes de escribir

1. `docs/superpowers/specs/2026-11-13-flujo-licitaciones-design.md` (diseño aprobado; lee entero las secciones 1 a 6 y tu fase).
2. `resources/design/AGENTS.md` y `resources/design/DESIGN_GUIDE.md` (sistema de diseño CAP; el «Recetario» se copia tal cual).
3. `resources/js/Components/README.md` (biblioteca compartida: úsala; no crees un componente si ya existe uno equivalente).
4. `database/DATABASE_GUIDE.md` (si choca con el esquema existente en inglés, gana lo existente).
5. `AGENTS.md` y `MIGRATION_RULES.md`. La regla de «paridad con Manus» y la de «no ejecutar pruebas» ya no aplican.

## Idioma

Todo en español con tildes correctas: interfaz, mensajes, comentarios, commits. Identificadores de
código, tablas y columnas en inglés, como el resto del proyecto. Sin emojis en la interfaz (íconos `mdi-*`).

## Propiedad de archivos

- Solo editas los archivos de tu módulo, listados en tu encargo.
- Rutas: solo tu archivo en `routes/modules/` (se carga solo, ya dentro de `auth`; rutas estáticas antes de las que llevan `{parametro}`).
- Migraciones: siempre nuevas, con el prefijo que te asignaron. No edites migraciones existentes. Aplícalas con `php artisan migrate`. Nunca `migrate:fresh`, `db:wipe` ni borrado de datos.
- Archivos compartidos que puedes tocar SOLO con una edición mínima de una línea (nunca reescribirlos):
  `app/Providers/AppServiceProvider.php` (añadir tu policy a `$policies` o registrar un observador) y
  `resources/js/config/statuses.js` (añadir estados).

- No edites `resources/design/**`, `resources/js/Layouts/**`, `resources/js/Components/**` ni `resources/js/config/navigation.js`.
  Si te falta algo ahí, resuélvelo en tu módulo y anótalo en tu informe.
- Si necesitas un cambio en el módulo de otro agente, no lo hagas: anótalo en tu informe con archivo y motivo.

## Servidor (backend)

- Multiempresa: los modelos con `company_id` usan `BelongsToCompany`; el scope ya filtra por empresa activa.
  Valida `company_id` con `new AllowedCompany('create')` e ids relacionados con `new BelongsToActiveCompany(Modelo::class)`.
- Permisos (RBAC): `App\Support\Permissions` es la matriz única (`viewer`, `operator`, `admin`, raíz).
  Toda acción de controlador llama a `authorize` (policy o gate con guiones, p. ej. `use-ai`).
  Las páginas reciben un objeto `can` con los permisos del registro o de la pantalla, y la interfaz oculta lo que no se puede.
- Form Requests para validar; mensajes en español; errores controlados (422 con mensaje, nunca 500).
- Transacciones y bloqueo (`lockForUpdate`) en toda operación que no deba duplicarse; botones idempotentes.
- Fechas en hora de Honduras (`America/Tegucigalpa`), sin convertir a UTC.
- Binarios y límites: `App\Support\Binaries` (`tesseract()`, `poppler('pdftotext')`, `maxPdfKilobytes()`, `maxImageKilobytes()`).
- Auditoría: registra las acciones relevantes con `App\Services\AuditLogger`.
- Rendimiento: sin N+1 (`with`, `withCount`), paginación en listas, columnas seleccionadas, índices para los filtros,
  props pesadas con `Inertia::defer`/perezosas, respuestas de lista por debajo de ~300 ms en local.
- Sin secretos en el código y sin variables nuevas en `.env` salvo que sean imprescindibles (anótalas en el informe; no edites `.env`).

## Interfaz

- Marco: `<AppLayout title="…">`. Contenido con los componentes de `resources/js/Components/`.
- Un botón primario por pantalla. Cinco estados en cada vista: vacío, sin resultados, cargando, error, parcial.
- Modales con `BaseModal`; confirmaciones con `useConfirm()`; subidas con `FileDropzone` + `useUpload()`; PDF con `PdfViewer`.
- Errores de validación bajo cada campo (`lib/errors.js`); botones deshabilitados mientras se envía; toasts vía `flash`.
- Claro y oscuro, escritorio y móvil (las modales van a pantalla completa en móvil). Sin hex ni `rgba(` a mano.
- Formato con `lib/format.js` (`money`, `date`, `dateTime`, `relativeDays`).
- Los nombres de las props que envía el controlador y los que lee la página deben coincidir: verifícalo abriendo la pantalla.

## Pruebas y validación (obligatorias)

- Pruebas de feature en `tests/Feature/<Modulo>/` con las factories y los helpers de `tests/TestCase.php`
  (`memberOf`, `actingInCompany`, `inCompany`). Cubre: flujo feliz, validación, permisos por rol (viewer no escribe),
  aislamiento entre empresas e idempotencia donde aplique. Ejecuta `composer test` al final y pega el resumen real.
- Validación visual con Playwright (`node_modules/playwright`, Chromium instalado). Servidor en `http://localhost:8000`
  (Vite con HMR en 5173). **No detengas esos servidores y no ejecutes `npm run build`.**
  Escribe tu script en `tests/visual/<modulo>.mjs` (mira `tests/visual/capturas.mjs` como ejemplo), que inicie sesión,
  recorra tus pantallas y abra **cada modal**, a 1440×900 y 390×844, en claro y oscuro, guardando en
  `storage/app/qa/capturas/<modulo>/`. Ejecuta el flujo real (crear, editar, subir un archivo, eliminar), no solo capturas.
  **Lee las capturas como imagen** y corrige hasta que se vean bien: sin desbordes, sin texto cortado, sin scroll
  horizontal en móvil, contraste correcto en oscuro. Cero errores en la consola del navegador.
  Otros agentes editan `resources/js` a la vez y la recarga de Vite puede dejar la página en blanco: tu script debe
  esperar a que la página esté estable y reintentar el paso si falla.
- Usuarios de prueba (contraseña `QaB2g#2026`): `qa.root@crm-b2g.test`, `qa.admin@crm-b2g.test` (admin de CAP Honduras),
  `qa.operador@crm-b2g.test`, `qa.lector@crm-b2g.test` (solo lectura), `qa.adminb@crm-b2g.test` (admin de Empresa Demo B).
  Prueba al menos con admin y con lector.
- Corre la lista «Antes de terminar una pantalla» de la guía de diseño.

## Git

- Commits pequeños en español. Solo tus archivos: `git add <rutas>` y `git commit -m "…" -- <rutas>` (nunca `git add -A` ni `git commit -a`).
- Si git dice que el índice está bloqueado, espera unos segundos y reintenta.
- Cada mensaje termina con: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`

## Informe final (conciso)

1. Qué quedó hecho y cómo lo verificaste (resumen real de pruebas, rutas de las capturas clave).
2. Lo que otros módulos pueden reutilizar de ti: componentes, servicios, rutas, con un ejemplo de una línea.
3. Lo que necesitas de otros módulos o del coordinador (archivo y motivo).
4. Pendientes, riesgos y decisiones tuyas que el usuario debería revisar.
5. Variables de `.env` nuevas, si las hay.

## Íconos (subconjunto MDI)

Los íconos `mdi-*` son un subconjunto generado (`resources/css/mdi.css` y `resources/fonts/mdi/mdi-subset.woff`).
Si usas un ícono nuevo, ejecuta `node scripts/mdi-subset.mjs` (1 s) y confirma los dos archivos generados junto con tu cambio.
`tests/Feature/Shell/IconSubsetTest.php` falla con la lista de los que faltan.
