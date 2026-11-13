# Backend 100% Plan

## Scope and Rules

This is the implementation source of truth for the 33 Laravel navigation routes. Manus is reference-only. External credentials are excluded from implementation completion: `OPENAI_API_KEY` and `RESEND_API_KEY` may be absent, but all code paths must fail in a controlled manner.

Status terminology in all matrices is restricted to `PASS`, `FAIL`, and `N/A`.

Counting units:

| Metric | Count | Unit |
|---|---:|---|
| Routes analyzed | 33 | Navigation screen |
| CRUD gaps | 67 | Applicable failed CRUD matrix cell |
| Database gaps | 21 | Migration/schema change-set |
| Permission gaps | 26 | Resource family missing policy/Gate enforcement |
| PDF gaps | 12 | Visible Manus PDF/print use case not completed end-to-end |
| Excel gaps | 6 | Visible Manus XLSX use case not completed end-to-end |
| Email use-case gaps | 1 | Orphan quote-email endpoint; excluded from Manus UI parity |
| Simulated actions | 23 | Visible action/data block without real backend result |
| Atomic tasks | 230 | Individually acceptance-testable implementation item |

## Current State

- Auth middleware protects all app routes, but most resources have no object/role authorization.
- `ModuleController` supplies name-only create and raw delete to 14 modules; it has no update action.
- Fifteen modules hit the dynamic fallback, where POST/DELETE can report success with no persistence.
- Quotes, Pipeline, Dashboard and Legal Chat are specialized; Quotes authorization is currently broken because `app/Http/Controllers/Controller.php` does not use `AuthorizesRequests`.
- PDF/XLSX services exist for Cotizaciones, but their controller authorization calls are affected by that defect.
- Documents do not upload a file; the UI only writes document metadata.

## Route Inventory

All screen routes use Laravel `web` middleware and explicit `auth` from `routes/web.php`. `ModulePage.vue` is the shared generic page; every route currently resolving there must be replaced by its listed typed page/controller.

| # | URI | Module | Current Vue | Current controller | Current models/tables | UI actions | Current backend result |
|---:|---|---|---|---|---|---|---|
| 1 | `/` | Dashboard | `Dashboard.vue` | `DashboardController` | Opportunity, Quote, Document; `opportunities`, `quotes`, `documents` | KPIs, alerts, shortcuts | Real partial metrics; alerts/counts are hard-coded |
| 2 | `/reportes` | Reports | `ModulePage.vue` | `ModuleController` fallback | None | reports, PDF, Excel, CSV | No-op |
| 3 | `/agente-legal` | Legal Agent | `LegalAgent.vue` | closure, `LegalChatController` | LegalConversation, LegalMessage | chat, quick prompts, legal writing | Chat real; writing is local template |
| 4 | `/pipeline` | Opportunities | `Pipeline.vue` | `OpportunityController` | Opportunity, Institution, User | search, kanban, create/edit/stage/delete, PDF/XLSX | Basic persistence; no policy/export/full fields |
| 5 | `/clientes` | Clients | `ModulePage.vue` | `ModuleController` | Client; `clients` | list/filter/create/edit/delete/import/export | Name-only create/delete |
| 6 | `/cotizaciones` | Quotes | `Quotes.vue` | Quote/QuoteExport controllers | Quote, QuoteItem, Company, Institution, Contact | filters/create/status/delivery/PDF/XLSX/delete | Services exist; policy invocation fails; UI filter mismatch |
| 7 | `/solicitud-cotizacion` | Quote Request | `ModulePage.vue` | fallback | None | pliego analysis, spreadsheet, match, PDF | No-op |
| 8 | `/inventario` | Inventory | `ModulePage.vue` | fallback | None | search/filter/detail | No-op |
| 9 | `/ia-licitaciones` | Tender AI | `ModulePage.vue` | fallback | None | recommendation, deep analysis | No-op |
| 10 | `/historial` | Process History | `ModulePage.vue` | fallback | `process_histories` | search/filter/create/detail/delete | No-op |
| 11 | `/licitaciones` | Tenders | `ModulePage.vue` | `ModuleController` | `tenders` | filters/create/detail/state/delete/PDF/XLSX | Name-only create/delete |
| 12 | `/compras-menores` | Minor Purchases | `ModulePage.vue` | fallback | `tenders` should be source | calculator/list/create | No-op |
| 13 | `/directorio` | Institutions | `ModulePage.vue` | `ModuleController` | Institution; `institutions` | filter/detail/edit | Name-only create/delete |
| 14 | `/garantias` | Guarantees | `ModulePage.vue` | `ModuleController` | Guarantee; `guarantees` | CRUD, expiry/release/execute | Type-only create/delete |
| 15 | `/post-adjudicacion` | Contracts | `ModulePage.vue` | `ModuleController` | `contracts` | CRUD, orders, acts, completion | Name-only create/delete |
| 16 | `/inteligencia-competitiva` | Competitors | `ModulePage.vue` | `ModuleController` | `competitors` | CRUD/analytics | Name-only create/delete |
| 17 | `/relaciones-institucionales` | Institutional Relations | `ModulePage.vue` | fallback | `institutional_relations`, `relation_interactions` | CRUD/activity | No-op |
| 18 | `/presupuesto-revolvente` | Revolving Budget | `ModulePage.vue` | `ModuleController` | `budget_movements` | budget/movements/sync/PDF/XLSX | Fabricated zero movement |
| 19 | `/flujo-caja` | Cash Flow | `ModulePage.vue` | fallback | None | projection/manual income | No-op |
| 20 | `/alertas-poa` | POA | `ModulePage.vue` | `ModuleController` | `poa_entries` | CRUD/execution | Project-only create/delete |
| 21 | `/consorcios` | Consortia | `ModulePage.vue` | `ModuleController` | `consortia`, `consortium_members` | CRUD/members | Name-only create/delete |
| 22 | `/matriz-cumplimiento` | Compliance Matrix | `ModulePage.vue` | fallback | None | checklist/document date action | No-op |
| 23 | `/calculadora-oferta` | Offer Calculator | `ModulePage.vue` | fallback | N/A | calculation/ranking | No-op |
| 24 | `/generador-documentos` | Document Generator | `ModulePage.vue` | fallback | None | AI generation/print | No-op |
| 25 | `/cobros` | Collections | `ModulePage.vue` | `ModuleController` | CollectionInvoice; `collection_invoices` | CRUD/payment/note/WhatsApp/PDF/XLSX | Number-only create/delete |
| 26 | `/contactos` | Contacts | `ModulePage.vue` | `ModuleController` | Contact; `contacts` | list/create/delete | Create fails: required institution missing |
| 27 | `/documentos` | Documents | `ModulePage.vue` | `ModuleController` | Document; `documents` | CRUD/upload/download/renew | Metadata name only; no storage action |
| 28 | `/kpis` | KPIs | `ModulePage.vue` | fallback | None | calculated KPIs/goals | No-op |
| 29 | `/configuracion` | Configuration | `ModulePage.vue` | fallback | `settings`, `companies`, `users` | settings/logo/roles/preview | No-op |
| 30 | `/sops` | SOPs | `ModulePage.vue` | fallback | N/A | list/detail/print | No-op |
| 31 | `/capacitacion` | Training | `ModulePage.vue` | fallback | `training_progress` | tutorials/progress/quiz | No-op |
| 32 | `/notificaciones` | Notification Templates | `ModulePage.vue` | `ModuleController` | `notification_templates` | CRUD/toggle/preview/WhatsApp | Name/body-only create/delete |
| 33 | `/calendario` | Calendar | `ModulePage.vue` | `ModuleController` | `events` | views/CRUD/completion/derived events | Title/current-time create; fabricated status |

## CRUD Matrix

`SPECIAL` includes exports, upload/download, business state changes, nested actions, AI actions, import/export, and printing. `FRONTEND` means the visible UI invokes a typed backend action, not merely a local modal or toast.

| Module | READ | CREATE | UPDATE | DELETE | DETAIL | STATE | SPECIAL | VALIDATION | AUTH | DATABASE | FRONTEND | STATUS |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Dashboard | FAIL | N/A | N/A | N/A | N/A | N/A | FAIL | N/A | PASS | FAIL | PASS | FAIL |
| Reports | FAIL | N/A | N/A | N/A | N/A | N/A | FAIL | N/A | PASS | FAIL | FAIL | FAIL |
| Legal Agent | N/A | N/A | N/A | N/A | N/A | N/A | FAIL | PASS | PASS | PASS | FAIL | FAIL |
| Pipeline | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | PASS | FAIL |
| Clients | FAIL | FAIL | FAIL | PASS | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Quotes | FAIL | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL |
| Quote Request | N/A | N/A | N/A | N/A | N/A | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Inventory | FAIL | N/A | N/A | N/A | FAIL | N/A | FAIL | N/A | PASS | FAIL | FAIL | FAIL |
| Tender AI | FAIL | N/A | N/A | N/A | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Process History | FAIL | FAIL | N/A | FAIL | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Tenders | FAIL | FAIL | N/A | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Minor Purchases | FAIL | FAIL | N/A | N/A | N/A | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Institutions | FAIL | N/A | FAIL | N/A | FAIL | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL |
| Guarantees | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Contracts | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Competitors | FAIL | FAIL | FAIL | PASS | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Relations | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Revolving Budget | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Cash Flow | FAIL | FAIL | N/A | FAIL | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| POA | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Consortia | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Compliance Matrix | FAIL | N/A | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Offer Calculator | N/A | N/A | N/A | N/A | N/A | N/A | FAIL | FAIL | PASS | N/A | FAIL | FAIL |
| Document Generator | N/A | N/A | N/A | N/A | N/A | N/A | FAIL | FAIL | FAIL | N/A | FAIL | FAIL |
| Collections | FAIL | FAIL | N/A | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| Contacts | FAIL | FAIL | N/A | PASS | N/A | N/A | N/A | FAIL | FAIL | FAIL | FAIL | FAIL |
| Documents | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |
| KPIs | FAIL | N/A | N/A | N/A | N/A | N/A | FAIL | N/A | FAIL | FAIL | FAIL | FAIL |
| Configuration | FAIL | N/A | FAIL | N/A | N/A | N/A | FAIL | FAIL | FAIL | PASS | FAIL | FAIL |
| SOPs | FAIL | N/A | N/A | N/A | FAIL | N/A | FAIL | N/A | PASS | N/A | FAIL | FAIL |
| Training | FAIL | N/A | FAIL | N/A | FAIL | FAIL | FAIL | FAIL | FAIL | PASS | FAIL | FAIL |
| Notifications | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | PASS | FAIL | FAIL |
| Calendar | FAIL | FAIL | FAIL | PASS | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL | FAIL |

## Database Gaps

| ID | Missing change-set | Required schema |
|---|---|---|
| DB-01 | Institutions | fleet type, modality, contact, phone/email, state, management/follow-up, source |
| DB-02 | Opportunities | opportunity type, next action; stage vocabulary including Contactado/Adjudicado/Perdido |
| DB-03 | Tenders | publication date, documents/observations, offer flag, result |
| DB-04 | Process histories | category, participation/decision mapping and result metadata |
| DB-05 | Clients | `last_purchase_at`, RTN uniqueness/validation definition |
| DB-06 | Contacts | department, WhatsApp; valid institution linkage |
| DB-07 | Documents | issue date, original filename, disk, MIME, size, uploader |
| DB-08 | Collection invoices | interest rate and collection-note metadata |
| DB-09 | Guarantees | percentage and parity fields |
| DB-10 | Contracts | financial progress/retention; purchase-order and delivery-certificate children |
| DB-11 | Competitors | RTN, category, average award price, last participation |
| DB-12 | Relations | influence/channel/state/start/POA interest; interaction result/next action |
| DB-13 | Revolving budget | budget header; typed/referenceable movements |
| DB-14 | Cash flow | `manual_cash_incomes` |
| DB-15 | POA | year, partida, quarter, annual-close alert |
| DB-16 | Consortia | tender/institution/leader/amount/date; complete member fields |
| DB-17 | Compliance | matrix and item tables linked to tender/document |
| DB-18 | Inventory | `inventory_items` with SKU, description, category, brand, stock, costs/prices |
| DB-19 | KPIs | `kpi_goals` by key/period/frequency |
| DB-20 | Calendar | priority, entity/reference, completed flag |
| DB-21 | Delivery | `delivery_alarms` with quote, actor, idempotency/status timestamps |

Mandatory constraints/indexes within those change-sets: unique client RTN and inventory SKU; quote date/type/status indexes; tender status/date/type indexes; documents `(status, expires_at)`; invoices `(status, due_at)`; events `(starts_at, type, priority)`; percentage and non-negative amount bounds; date-order validation; consortium member participation maximum; one synchronized budget movement per source reference.

## Authorization Matrix

All resources require backend enforcement. Vue button visibility is never sufficient.

| Resource | VIEW | CREATE | UPDATE | DELETE | SPECIAL | ADMIN ONLY |
|---|---|---|---|---|---|---|
| Dashboard/Reports | PASS | N/A | N/A | N/A | FAIL export ability | N/A |
| Users/Roles | FAIL | N/A | FAIL | N/A | FAIL | FAIL |
| Settings/Company | FAIL | N/A | FAIL | N/A | FAIL | FAIL |
| Legal conversations | PASS owner scope | PASS | N/A | N/A | PASS chat | N/A |
| Opportunities | FAIL | FAIL | FAIL | FAIL | FAIL | N/A |
| Clients | FAIL | FAIL | FAIL | FAIL | FAIL import/export | N/A |
| Quotes | FAIL unscoped index | FAIL runtime | FAIL runtime | FAIL runtime | FAIL PDF/XLSX/mail | N/A |
| Institutions | FAIL | N/A | FAIL | N/A | N/A | N/A |
| Tenders/Minor Purchases | FAIL | FAIL | FAIL state | FAIL | FAIL export | N/A |
| Process History | FAIL | FAIL | N/A | FAIL | N/A | N/A |
| Guarantees | FAIL | FAIL | FAIL | FAIL | FAIL transitions | N/A |
| Contracts/children | FAIL | FAIL | FAIL | FAIL | FAIL completion | N/A |
| Competitors/Relations/POA/Consortia | FAIL | FAIL | FAIL | FAIL | FAIL nested/state | N/A |
| Budget/Cash Flow | FAIL | FAIL | FAIL | FAIL | FAIL synchronization | N/A |
| Collections | FAIL | FAIL | FAIL paid | FAIL | FAIL note/export | N/A |
| Contacts/Documents | FAIL | FAIL | FAIL | FAIL | FAIL file access | N/A |
| KPI goals | FAIL | FAIL | FAIL | N/A | N/A | FAIL |
| Training/Calendar | FAIL owner scope | FAIL | FAIL | FAIL | FAIL completion | N/A |
| Notification templates | FAIL | FAIL | FAIL | FAIL | FAIL toggle/use | N/A |

Required foundation: restore `AuthorizesRequests`; define `admin` bypass; create policies for each mutable resource; scope legal conversations, opportunities, quotes, manual cash incomes, training and events; add explicit `admin` protection for roles, settings, controlled seed and reset.

## Documents, Exports, Email, and OpenAI

### PDF

| Module/use case | Button/action exists in Manus | Backend | Template/data/permission | Status |
|---|---|---|---|---|
| Pipeline list | Yes | FAIL | FAIL | FAIL |
| Quotes individual | Yes in Laravel | Exists, runtime auth FAIL | Template/data exist; permission FAIL | FAIL |
| Quotes filtered list | Yes in Manus | FAIL | FAIL | FAIL |
| Tenders | Yes | FAIL | FAIL | FAIL |
| Collections list | Yes | FAIL | FAIL | FAIL |
| Collection note | Yes | FAIL | FAIL | FAIL |
| Reports | Yes | FAIL | FAIL | FAIL |
| Revolving budget | Yes | FAIL | FAIL | FAIL |
| SOPs | Yes | FAIL | FAIL | FAIL |
| Quote request | Yes | FAIL | FAIL | FAIL |
| Business documents | Yes | FAIL | FAIL | FAIL |
| Legal writing/configuration preview | Yes | FAIL | FAIL | FAIL |

### Excel

| Module | Button/action exists | Backend | Filter parity/permission | Status |
|---|---|---|---|---|
| Pipeline | Yes | FAIL | FAIL | FAIL |
| Quotes | Yes | Exists but auth/filter mismatch | FAIL | FAIL |
| Tenders | Yes | FAIL | FAIL | FAIL |
| Collections | Yes | FAIL | FAIL | FAIL |
| Reports | Yes | FAIL | FAIL | FAIL |
| Revolving budget | Yes | FAIL | FAIL | FAIL |

### Resend Email

Infrastructure status: Laravel mailer configuration, Mailable, PDF attachment, and quote email endpoint exist. It is not a complete UI use case.

| Use case | Manus/UI action | Backend | Status |
|---|---|---|---|
| Send quote by email | No | Endpoint/Mailable exists but authorization runtime defect; no recipient UI | FAIL orphan endpoint |
| Quote Delivery | Yes | Only status change; no persisted alarm | FAIL |
| Contract email | No | N/A | N/A |
| Process notification email | No | N/A | N/A |

Do not invent a quote-email UI for Manus parity. Retain or remove the orphan endpoint by explicit product decision after fixing its protection. Missing Resend credentials are external validation only, not a code gap.

### OpenAI

| Item | Status |
|---|---|
| Service uses `config('services.openai.key')` and model config | IMPLEMENTATION PASS |
| Legal chat endpoint/history/owner lookup | IMPLEMENTATION PASS |
| Legal writing | FAIL frontend-only template |
| Tender analysis | FAIL |
| Pliego analysis | FAIL |
| Business document generation | FAIL |
| External API key validation | VALIDACION EXTERNA PENDIENTE |

## Simulated Actions

1. The 15 fallback create actions for Reports, Quote Request, Inventory, Tender AI, History, Minor Purchases, Relations, Cash Flow, Compliance Matrix, Offer Calculator, Document Generator, KPIs, Configuration, SOPs, and Training.
2. Static top-bar alert count.
3. Dashboard AI suggestion.
4. Dashboard alert list/count.
5. Dashboard institutional-potential counts.
6. Dashboard HonduCompras counts.
7. Local legal writing generation.
8. Quote Delivery as status-only action.
9. Calendar fabricated `Pendiente` status.

The first list counts as 15 actions; combined total is 23. Normal placeholders, `DatabaseSeeder` demo labels, test factory `fake()`, and CSS `placeholder` configuration are not business simulations.

## Search, Pagination, and Forms

- Replace all `ModulePage.vue` client-side array filtering with validated server query parameters for persisted modules.
- Add server pagination for opportunities, clients, quotes, tenders, history, institutions, guarantees, contracts, competitors, relations, budget movements, POA, consortia, collections, contacts, documents, templates and calendar list view.
- Quotes currently paginate server-side but filters only `quotes.data`; add paginator UI and canonical query shared by screen/PDF/XLSX. Search must include quote number, institution and purchase type.
- Pipeline is unpaginated and client-filtered; move search/stage filtering to controller.
- Every current generic one-field form must be replaced by typed FormRequests and a complete business form.
- Contact create is a P0 defect: `contacts.institution_id` is non-null while `ModuleController` inserts only a name.
- Files require private Laravel Storage upload, MIME/extension/size validation, metadata, policy-protected download, replacement and cleanup behavior.

## Atomic Task Ledger

The canonical task namespace is `TASK-BE2-001` through `TASK-BE2-230`. IDs are stable. Tasks are grouped below by contiguous range to keep execution order readable; each line remains independently completable with its own acceptance condition.

### Block A - Foundation

| Tasks | Module | Atomic implementation and acceptance |
|---|---|---|
| 001 | Authorization base | Add `AuthorizesRequests`; every existing quote authorization returns response/403, never undefined method. |
| 002-005 | Gates/roles/AI/settings | Register admin bypass and named abilities; admin user role update protects final admin; operators cannot mutate settings; AI actions require authenticated ability. |
| 006-028 | Resource policies | Add/enforce policies for opportunities, clients, quotes, institutions, tenders, history, guarantees, contracts, competitors, relations, budget, cash flow, POA, consortia, compliance, generated documents, collections, contacts, documents, KPI goals, training, templates and events; each direct unauthorized action returns 403. |
| 029 | Route allowlist | Unknown module slug returns 404; all 33 approved screens resolve. |
| 030 | No-op removal | No fallback POST/DELETE can return success without an actual operation. |

### Block B - Sales and Government Process

| Tasks | Module | Atomic implementation and acceptance |
|---|---|---|
| 031-039 | Clients | Schema/model; server list/filter/detail; create; update; soft delete; typed Vue; idempotent DIDASA import; validated CSV import; filtered CSV export. Each action accepts complete client data and returns persisted results. |
| 040-043 | Institutions | Schema/model; server list/detail/filters; update request/action; dedicated Vue. All directory/prospection fields round-trip. |
| 044-048 | Contacts | Schema/model; read; create with institution validation; delete; typed Vue. Valid creation never yields SQL integrity error. |
| 049-056 | Pipeline | Schema/stages; read/filter/page; create; stage transition; detail/delete; typed Vue; PDF; XLSX. Exports equal canonical filtered query. |
| 057-064 | Tenders | Schema/model; read/filter/page; create; state/result; delete; Vue; PDF; XLSX. Complete record and transitions persist. |
| 065-069 | Process History | Schema/model; list/statistics; create; delete; typed Vue. Statistics reconcile to stored data. |
| 070-072 | Minor Purchases | Tender subset query/calculation; create as `Compra Menor`; Vue. Created row appears in both queries. |
| 073-081 | Quotes | Canonical list filter; stable detail economics; contact persistence; dedicated status transition; soft delete; Vue pagination; individual PDF; list PDF; XLSX. Status change never recomputes item economics. |
| 082-084 | Delivery | Delivery alarm schema; idempotent transactional action; Vue. Success means persisted alarm exists. |

### Block C - Operational Tools

| Tasks | Module | Atomic implementation and acceptance |
|---|---|---|
| 085-091 | Guarantees | Schema/model; read; create; update; transition; delete; Vue. Invalid dates/transitions fail. |
| 092-100 | Contracts | Schema/children; read; create; update; delete; purchase order; delivery certificate; completion; Vue. Nested totals and state reconcile. |
| 101-106 | Competitors | Schema/model; read/analytics; create; update; delete; Vue. Win rate is correctly named/calculated. |
| 107-113 | Relations | Schema/models; read; create; update; delete; interaction action; Vue. Relation and interaction writes are atomic. |
| 114-125 | Collections | Schema/model; read; create; mark paid; delete; interest service; note; WhatsApp rendering; Vue; list PDF; note PDF; XLSX. Payment is idempotent and all calculations match exports. |
| 126-136 | Revolving Budget | Schema/models; reconciliation; initial amount; manual movement/delete; quote/tender/collection sync; Vue; PDF; XLSX. Each source reference creates at most one synchronized movement. |
| 137-141 | Cash Flow | Manual-income schema; projections; create/delete; Vue. Scenario totals are reproducible. |
| 142-148 | POA | Schema/model; read; create; update; execute 100%; delete; Vue. Institution/year/partida values map correctly. |
| 149-156 | Consortia | Schema/models; read; create/delete; add/update/remove member; Vue. Participation never exceeds 100%. |
| 157-166 | Documents | Schema/model; read; metadata create/update; upload/download/replace/delete; renew; Vue. File access is policy protected and cleanup is defined. |
| 167-172 | Compliance Matrix | Schema/models; read; transactional create; item state; document renewal; Vue. Checklist state derives from persisted data. |

### Block D - Specialized Modules and Admin

| Tasks | Module | Atomic implementation and acceptance |
|---|---|---|
| 173-175 | Inventory | Schema/model; paginated query; Vue. Stable persisted SKU source is displayed. |
| 176-180 | Quote Request | Pliego analysis; spreadsheet parser; composition; Vue; PDF. Provider/parser failures never report success. |
| 181-183 | Tender AI | Context query; structured analysis endpoint; Vue. Results come only from backend output. |
| 184-185 | Offer Calculator | Stateless calculation endpoint; Vue. Fixed input yields fixed ranking. |
| 186-189 | Document Generator | AI action; company defaults; Vue; print/PDF. No generated success after provider failure. |
| 190-195 | Notifications | Model; read; create; update/toggle; delete; Vue. Preview/WhatsApp rendering uses persisted template and claims no server delivery. |
| 196-201 | Calendar | Schema/model; owner/derived read; create; update/complete; delete; Vue. Derived rows are read-only; no fabricated status. |
| 202-204 | Training | Model; owner upsert/read; Vue. Progress survives reload and owner scope is enforced. |
| 205-211 | Configuration/Admin | Settings read; company/default update; logo storage; preview PDF; Vue/role controls; controlled seed; confirmation-token reset. Operators receive 403 for mutation. |
| 212-214 | SOPs | Read-only content endpoint; Vue; PDF. No generic mutations remain. |
| 215-218 | OpenAI/Legal | Shared bounded service; legal-writing action; Vue; print/PDF. Missing credentials return controlled error; key not required. |

### Block E - Aggregates

| Tasks | Module | Atomic implementation and acceptance |
|---|---|---|
| 219-221 | Dashboard/alerts | Database metric service; alert service; Vue props. All live values reconcile to query results. |
| 222-226 | Reports | Aggregate query; Vue; PDF; XLSX; CSV. Each format uses identical filters/totals. |
| 227-230 | KPIs | Goals schema; actual-value query; admin target mutation; Vue. Target and actual reconcile to period-filtered DB data. |

## Dependency-Safe Order

1. `001`, `002`, `005`, `021`, `029`, `030`.
2. Clients `031-039`; Institutions `040-043`; Contacts `044-048`.
3. Pipeline `049-056`; Tenders `057-064`; History `065-069`; Minor Purchases `070-072`; Quotes `073-081`; Delivery `082-084`.
4. Guarantees `085-091`; Contracts `092-100`; Competitors `101-106`; Relations `107-113`; Collections `114-125`.
5. Budget `126-136`; Cash Flow `137-141`; POA `142-148`; Consortia `149-156`; Documents `157-166`; Compliance `167-172`.
6. Inventory `173-175`; OpenAI foundation `215`; Quote Request `176-180`; Tender AI `181-183`; Offer Calculator `184-185`; Document Generator `186-189`.
7. Notifications `190-195`; Calendar `196-201`; Training `202-204`; Settings/Admin `205-211`; SOPs `212-214`; Legal `216-218`.
8. Dashboard `219-221`; Reports `222-226`; KPIs `227-230`.

## Backend 100% Gate

Backend can be declared 100% only when all conditions hold:

1. Every `TASK-BE2-001` through `TASK-BE2-230` has passed its acceptance criterion.
2. Every applicable `FAIL` in the CRUD matrix is `PASS`; legitimate `N/A` remains `N/A`.
3. Database gaps, authorization gaps, CRUD gaps, PDF gaps, Excel gaps, and business simulations are all zero.
4. Every visible business action reaches a typed, validated, authorized backend operation; no dynamic fallback success remains.
5. Documents use protected Storage lifecycle operations.
6. Dashboard, reports, KPIs, PDF and XLSX use persisted, authorized data and consistent filters.
7. Quote Delivery creates a durable idempotent record, not merely a status change.
8. OpenAI and Resend missing credentials produce controlled errors and are recorded only as external validation pending.
9. `php artisan migrate`, `php artisan route:list`, and `npm run build` pass after implementation.

## Execution Status

Completed: 230 / 230
Remaining: 0

### PASS
- TASK-BE2-001 - Base controller now uses `AuthorizesRequests`.
- TASK-BE2-002 - Admin bypass and named `use-ai`, `generate-documents`, and `manage-settings` Gates registered.
- TASK-BE2-029 - Dynamic screen route constrained to the 29 approved non-specialized module slugs; unknown slugs no longer resolve.
- TASK-BE2-030 - Generic POST/DELETE fallback routes removed; no fallback mutation can report successful persistence.
- TASK-BE2-003 - Admin-only user role endpoint protects the final administrator.
- TASK-BE2-006 - Opportunity policy is enforced in index, create, update, and delete; operator records are owner-scoped.
- TASK-BE2-008 - Quote queries and exports are scoped to the operator creator; mutation/export policy calls now resolve.
- TASK-BE2-162-172 - Document lifecycle and compliance renewal UI/actions persist protected files, metadata, checklist state and linked renewals.
- TASK-BE2-177, TASK-BE2-179 - Quote-request frontend accepts text/XLSX/CSV parsing and composes persisted quote requests.
- TASK-BE2-187 - Generated documents merge configured company defaults before the provider request.
- TASK-BE2-195 - Notification template UI persists previewable WhatsApp/email templates without claiming delivery.
- TASK-BE2-197, TASK-BE2-201 - Calendar displays persisted manual events plus read-only derived document expirations.
- TASK-BE2-206-211 - Admin configuration supports company fields, private logo, PDF preview, role entry point, seed and confirmation-token reset.
- TASK-BE2-214 - SOP content has PDF output.
- TASK-BE2-218 - Legal generated writings include browser print/PDF output.
- TASK-BE2-110, TASK-BE2-113 - Institutional relation update and typed Vue interaction UI are persisted atomically.
- TASK-BE2-114 through TASK-BE2-125 - Collections CRUD, idempotent payment, interest, note/WhatsApp rendering, Vue, PDF and XLSX are implemented.
- TASK-BE2-126 through TASK-BE2-136 - Revolving budget header, manual movements, source synchronization, reconciliation, Vue, PDF and XLSX are implemented.
- TASK-BE2-137 through TASK-BE2-141 - Cash-flow manual income persistence, projections, deletion and Vue are implemented.
- TASK-BE2-142 through TASK-BE2-148 - POA CRUD, 100% execution action and Vue are implemented.
- TASK-BE2-149 through TASK-BE2-156 - Consortia fields, members create/update/remove, participation enforcement and Vue are implemented.
- TASK-BE2-004 through TASK-BE2-005 and TASK-BE2-007 through TASK-BE2-028 - Settings, AI abilities, and all resource policy/Gate enforcement are implemented.
- TASK-BE2-031 through TASK-BE2-084 - Clients, directory, contacts, Pipeline, tenders, process history, minor purchases, quotes, exports, and Delivery are implemented with typed routes and server validation.
- TASK-BE2-085 through TASK-BE2-113 - Guarantees, contracts, competitors, and institutional relations are implemented with typed workflows.
- TASK-BE2-157 through TASK-BE2-161 - Document metadata, private upload, download, replacement, and deletion lifecycle are implemented.
- TASK-BE2-173 through TASK-BE2-176, TASK-BE2-178, TASK-BE2-180 through TASK-BE2-186, TASK-BE2-188 through TASK-BE2-194, TASK-BE2-196, TASK-BE2-198 through TASK-BE2-205, TASK-BE2-212 through TASK-BE2-217 - Specialized modules, configuration, and OpenAI-backed actions are implemented.
- TASK-BE2-219 through TASK-BE2-230 - Live dashboard/alerts, reports, exports, KPI targets and period calculations are implemented.

### ACTIVE
- None.

### BLOCKED
- BLOCKED_EXTERNAL - OpenAI and Resend live-provider delivery validation requires deployment credentials. This does not block implementation completion.

### Final Matrix State

The matrices above are the baseline audit. This execution supersedes their baseline `FAIL` cells as follows:

| Matrix | Final state |
|---|---|
| Routes | 33/33 PASS |
| CRUD gaps | 0 |
| Database gaps | 0 |
| Authorization gaps | 0 |
| PDF gaps | 0 |
| Excel gaps | 0 |
| Simulated business actions | 0 |
| Typed backend actions | PASS |
