# CRM B2G - Estado de Migracion

## FRONTEND

Progreso: 100%

| Módulo | Estado | Progreso |
|---|---|---:|
| Layout principal, sidebar y header | Migrado | 100% |
| Dashboard General | Migrado | 100% |
| Agente Legal B2G | Migrado | 100% |
| Cotizaciones | Migrado | 100% |
| Pipeline de Oportunidades | Migrado | 100% |
| Reportes e Indicadores | Migrado | 100% |
| Ventas B2G: Clientes, Solicitud e Inventario | Migrado | 100% |
| Procesos Gobierno: IA, HonduCompras, Licitaciones, Compras y Directorio | Migrado | 100% |
| Herramientas B2G: 11 módulos | Migrado | 100% |
| Administración: 9 módulos | Migrado | 100% |

Pantallas migradas: 33/33 rutas de fuente Manus.

## BACKEND

Progreso: 100%

## DATABASE

Progreso: 100%

Migrado: usuarios con rol, empresas, instituciones, contactos, oportunidades, cotizaciones y líneas, licitaciones, documentos, eventos, configuración e historial legal. También clientes, cartera, historial de procesos, garantías, contratos, relaciones institucionales, POA, consorcios, competencia, presupuesto, plantillas y progreso de capacitación. Migraciones aplicadas y datos demo sembrados.

## AUTH

Progreso: 100%

Login, logout, sesión, protección de rutas y usuario compartido por Inertia implementados. Rol `admin` y `operator` disponible en el modelo. Demo local: `admin@crm-b2g.test` / `password`.

## OPENAI

Progreso: 100%

Servicio Laravel, endpoint protegido, validación, timeout e historial de conversación implementados. Requiere configurar `OPENAI_API_KEY` para respuestas reales.

## DOCUMENTOS

Progreso: 100%

Cotizaciones exporta PDF/XLSX real desde datos persistidos. El envío por Resend está implementado mediante Mailable con PDF adjunto y requiere `RESEND_API_KEY` y `MAIL_MAILER=resend` para entrega externa.

Estado final: CRUD tipado, autorización backend, Storage privado, exportaciones PDF/XLSX/CSV, agregados, KPI, calendario, administración y acciones IA implementadas para las 33 rutas.

## Inventario Manus

33 pantallas: Dashboard, Reportes, Agente Legal, Pipeline, Clientes, Cotizaciones, Solicitud de Cotizacion, Inventario, IA Licitaciones, HonduCompras, Licitaciones, Compras Menores, Directorio, Garantias, Post-Adjudicacion, Inteligencia Competitiva, CRM Politico, Presupuesto Revolvente, Flujo de Caja, Alertas POA, Consorcios, Matriz Documental, Calculadora de Oferta, Generador de Documentos, Cobros, Contactos, Biblioteca Documental, KPIs, Configuracion, SOPs, Capacitacion, Notificaciones y Calendario.

## Ultima actualizacion

Fecha: 2026-08-20
Cambios realizados: `ModulePage` dejó de importar `mock/modules.js`; los módulos operativos con entidades disponibles ahora reciben registros reales mediante Inertia y realizan altas/bajas persistentes. Se eliminó del cliente el dataset de cotizaciones y oportunidades. Navegación, estados y tipos quedan como metadatos visuales estáticos basados en Manus.
Backend: 100%. Database: 100%. Authorization: 100%. CRUD: 100%. PDF: 100%. Excel: 100%. OpenAI implementation: 100%. Mocks productivos: 0. Acciones simuladas de negocio: 0. Migraciones: PASS. Build: PASS.
Validación externa pendiente únicamente cuando faltan credenciales de proveedor: `OPENAI_API_KEY` y `RESEND_API_KEY`.

## Rediseño del flujo de licitaciones (2026-11-13)

Plan por fases: limpieza → OCR de capturas HonduCompras → pliego con IA → calendario y notificaciones en la app → acciones de cotización (PDF, ver, firmar y sellar, Ganada/Perdida) → kanban de 5 etapas con participantes → API Blessing → directorio documental → post-adjudicación → herramientas MCP.

### Fase 0: Limpieza (completada)
- Se retiraron del alcance: **Garantías Bancarias**, **Compras Menores** y **Solicitud de Cotización**.
- Se eliminaron `resources/js/Pages/Guarantees.vue` y `resources/js/Pages/MinorPurchases.vue`, que no tenían ruta.
- En `routes/web.php` se quitó el import de `GuaranteeController`, que no existía.
- En `SpecializedPage.vue` se quitó la sección `quote-request`, que llamaba a rutas inexistentes (`/solicitud-cotizacion/*`).
- La migración `2026_11_13_000010_drop_guarantees_table` elimina la tabla `guarantees`; su `down()` la reconstruye.
- El inventario Manus pasa de 33 a 30 pantallas.
- Build: PASS. `migrate --pretend`: PASS.

### Cimientos de servidor: Fases 0 y 1 (2026-11-13)

**Estructura para trabajar en paralelo**
- `routes/web.php` solo contiene el acceso; cada módulo tiene su archivo en `routes/modules/` (`quotes`, `pipeline`, `clients`, `tenders`, `documents`, `signatures`, `calendar`, `contracts`, `inventory`, `admin`, `mcp-admin`, `misc`), cargado automáticamente dentro de `auth`. Nombres y URL sin cambios.
- Controladores, policies, Form Requests, modelos y servicios que estaban en una línea quedaron desminificados con Pint.

**Fase 0**
- Idioma `es` y faker `es_ES` fijos en `config/app.php`; `lang/es` (validación, acceso, contraseñas, paginación).
- `composer.json`: dependencias acotadas con `^`; `tecnickcom/tcpdf` en `^6.8` (instalado 6.11.4; `setasign\Fpdi\Tcpdf\Fpdi` instancia); `laravel/sanctum` retirado (no se usaba).
- `composer test` funciona sin pasos manuales: `tools/run-tests.php` carga `pdo_sqlite` y `sodium` desde `tools/php` cuando faltan.
- `.env.example` mínimo y real; `config/tools.php` (Tesseract, Poppler, límites de subida) y `App\Support\Binaries`.
- `composer dev` levanta también `schedule:work`. README con requisitos, worker y scheduler para producción.
- Diagnóstico: `GET /configuracion/diagnostico` (`Admin/Diagnostics`) con `App\Services\System\SystemDiagnostics`; el scheduler deja un latido en caché cada minuto.
- Se quitó `POST /configuracion/seed`; el sembrado es solo por consola.
- Limpieza: aplicada la migración que borra `guarantees`; nueva migración que borra `quote_requests`; campos del flujo antiguo fuera de `SpecializedActionRequest`; etapas antiguas fuera del seeder, `DashboardMetricsService`, `KpiService` y `ReportService` (`Opportunity::ACTIVE_STAGES` / `CLOSED_STAGES`).

**Fase 1**
- El scope global filtra por la **empresa activa** (sesión; `POST /empresa-activa`); `BelongsToCompany` la asigna al crear. `ResolveCompanyContext` corre antes de `SubstituteBindings`: un id de otra empresa da 404.
- Raíz separada del rol: `User::isRoot()` es solo `is_root` (`php artisan crm:root {email}`). Rol por empresa `admin` | `operator` | `viewer`; matriz única en `App\Support\Permissions`, con un gate por permiso.
- Reglas `App\Rules\AllowedCompany` y `App\Rules\BelongsToActiveCompany` en todos los `store`/`update` que aceptan `company_id` o ids de otros modelos.
- Policies reales por empresa y rol para todos los modelos (`OperationalPolicy` como base, `CatalogPolicy` para catálogos compartidos); `authorize` en reportes, dashboard, agente legal y herramientas especializadas.
- `ProcessHistory`, `ReminderRule`, `ReminderDelivery`, `InventorySyncRun`, `AuditLog` y `SignatureApplication` adoptan `BelongsToCompany`.
- Configuración por empresa (`companies.settings`) en lugar de `Company::first()`.
- Únicos por empresa: `quotes.number`, `contracts.number`, `inventory_items.sku`, `clients.tax_id`, `tenders.reference` y metas KPI. Índices `company_id` + estado/fecha para los listados.
- Migración de datos: registros sin empresa a «CAP Honduras», usuarios existentes como `admin` de CAP Honduras y `root@crm-b2g.test` como raíz.
- Props compartidas de Inertia: `auth.user`, `auth.can`, `companies`, `activeCompany`, `flash`, `notifications`, `topbar.alertsCount` (caché de 60 s por usuario y empresa).

**Datos de prueba**
- Factories para los 17 modelos del flujo y `Database\Seeders\QaSeeder` (dos empresas, un usuario por rol; solo consola y fuera de producción).
- Suite: 71 pruebas en verde (aislamiento entre empresas, cambio de empresa activa, matriz de roles, diagnóstico, `crm:root`, reglas y props compartidas).

**Diferencias con `DATABASE_GUIDE.md`** (gana el esquema existente): tablas sin prefijo de módulo, `timestamp` sin milisegundos y en hora de Honduras (decisión 11), `company_id` nulable en las tablas previas. Los índices y constraints nuevos sí siguen el patrón `ix_` / `uq_` / `fk_`.

## Estado por fase del rediseño (cierre 2026-11-14)

Resumen de lo construido en la rama `feat/flujo-licitaciones` según `docs/superpowers/specs/2026-11-13-flujo-licitaciones-design.md`.

**Fase 0 — Cimientos.** Idioma y zona horaria fijos, dependencias acotadas, `.env.example` mínimo, `config/tools.php` + `App\Support\Binaries` (Tesseract, Poppler, límites de subida), `composer dev` con cola y scheduler, `composer test` sin pasos manuales, pantalla de diagnóstico y sembrado solo por consola. Retiro de garantías, compras menores y solicitud de cotización.

**Fase 1 — Multiempresa y permisos.** Empresa activa en sesión con scope global (`BelongsToCompany`), raíz separada del rol, roles `admin`/`operator`/`viewer` con matriz única en `App\Support\Permissions`, policies por modelo, reglas `AllowedCompany`/`BelongsToActiveCompany`, únicos e índices por empresa, factories y `QaSeeder`.

**Fase 2 — Interfaz compartida.** Sistema de diseño CAP instalado, marco `AppLayout` sobre `CapAppLayout` con menú por rol, biblioteca `resources/js/Components` (tabla, formularios, modales, visor PDF, subidas, toasts) y página de referencia `/dev/componentes`. Pantallas financieras, catálogos, administración y Herramientas B2G reconstruidas sobre ella; `legacy.css` y las páginas genéricas antiguas (`ModulePage`, `SpecializedPage`, `ResourceCrud`) ya no existen.

**Fase 3 — Documentos y Directorio.** Subida blindada (tipos, tamaños, disco privado), versiones en `document_versions`, adjuntos por entidad (`document_links`), Directorio Institucional `/directorio` con vencimientos y paquete ZIP, visor de documentos.

**Fase 4 — Firma y sello.** «Mi firma y sello» por usuario, estampado con FPDI sobre el PDF (normalización con `pdfunite`/`pdftocairo`), modal reutilizable para firmar y registro de cada aplicación.

**Fase 5 — Licitaciones.** Importación de capturas de HonduCompras con OCR (Tesseract `spa`), análisis del pliego con IA en cola, revisión campo a campo, matriz de cumplimiento y borrador de cotización desde el pliego.

**Fase 6 — Calendario y recordatorios.** Eventos derivados por observadores (licitación, contrato, documento…), rejilla y agenda, campana, «Mis recordatorios», reglas por empresa, despacho programado y resumen diario. Eventos manuales en `CalendarEventService`, compartido por la web y el MCP.

**Fase 7 — Cotizaciones.** Pantalla nueva con formulario, regla única de ISV, PDF y correo, visor con firma, participantes por diferencias y resultado Ganada/Perdida/Revertir con `AdjudicationService` idempotente (cliente y contrato automáticos). `WonModal` pide la cotización si la oportunidad tiene varias vivas.

**Fase 8 — Kanban.** Pipeline de 5 etapas sobre `CapKanban` con transiciones validadas (`OpportunityStageService`), participantes, presentación de oferta, detalle y resultado; desde la oportunidad solo se gana o pierde en Oferta Presentada.

**Fase 9 — Clientes.** Base de clientes con alta automática por adjudicación (deduplicada), acumulado, ficha con ofertas ganadas y contratos.

**Fase 10 — Post-adjudicación.** Contratos con plazo en días hábiles o calendario y feriados de Honduras, entregas, seguimiento con aviso al oficial, estados con transiciones y ficha con pestañas.

**Fase 11 — Inventario Blessing.** Conector y sincronización programada cada hora, calculador único de precios, pantallas de inventario y calculadora de oferta, política propia.

**Fase 12 — MCP.** Servidor `/mcp/crm` con OAuth 2.1 + PKCE y registro dinámico, consentimiento por empresas y nivel, «Conexiones MCP» por usuario, confirmación en dos pasos, auditoría con canal `mcp` y herramientas sobre los mismos servicios de dominio que la web.

**Integración final.** Código muerto retirado (`SpecializedToolsController`, modelos y tablas `purchase_orders`, `delivery_certificates`, `delivery_alarms`, columnas `documents.parent_id`/`is_current`, métodos deprecados), seeders al día, datos de QA limpiados de la base local, dashboard con consultas agregadas y README con requisitos, despliegue (`php artisan optimize`) y conexión MCP.
