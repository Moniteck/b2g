# Biblioteca compartida de interfaz

Piezas con las que se arma cada pantalla del CRM B2G sobre el sistema de diseño CAP
(`resources/design/`, que no se edita). Todas funcionan en claro y oscuro, en móvil y escritorio, y con
teclado. Ejemplos vivos: `Pages/Dev/Components.vue` (`/dev/componentes`).

## Reglas

1. **Antes de escribir interfaz**, lee `resources/design/AGENTS.md` y el «Recetario» de
   `resources/design/DESIGN_GUIDE.md`. Si existe un componente aquí, se usa; no se arma otro a mano.
2. **Un solo botón primario visible** por pantalla o formulario (`variant="primary"`). Con la lista
   vacía, el del estado vacío reemplaza al de la barra de acciones.
3. **Los cinco estados** de toda pantalla: vacío, sin resultados, cargando (esqueleto, nunca spinner a
   pantalla completa), error y parcial (una sección falla y el resto sigue).
4. **Sin hex, `rgb(`, `rgba(` ni `text-[…px]`**: solo clases `cap-*` y variables `--cap-*`. Toda clase de
   color física lleva su `dark:`; los alias de rol (`bg-cap-card`, `text-cap-text-soft`…) cambian solos.
5. **Sin emojis**: íconos Material Design (`mdi-*`), `-outline` por defecto, siempre junto a un texto o
   con `aria-label`.
6. **Sin `confirm()`, `prompt()` ni `alert()`**: `useConfirm()` para confirmar, una `BaseModal` para pedir
   datos, toasts (`flash` del servidor o `useToasts()`) para el resultado de una acción y `CapAlert`
   para lo que condiciona la pantalla.
7. **Errores de validación bajo su campo** (`FormField :error`), foco en el primero (`focusFirstError`);
   resumen arriba solo si hay más de tres. El primario muestra `loading` mientras envía.
8. **Cifras y fechas con `lib/format.js`**; montos a la derecha con `tabular-nums`; celdas sin valor «—».
9. **Nada de `window` ni `$router` en plantillas**: enlaces con `Link`/`AppButton href`, descargas con
   `AppButton external`, navegación con `router` importado en el `<script setup>`.
10. **Ningún nombre de empresa escrito a mano**: sale de `design/company.js` o de los datos del servidor.
11. Textos de interfaz en español con tildes; identificadores en inglés. El margen de la página lo pone
    el marco: no agregues otro contenedor con relleno alrededor del contenido.
12. Antes de entregar una pantalla, corre la lista «Antes de terminar una pantalla» de la guía y
    `node tests/visual/capturas.mjs` (claro y oscuro, 1440 y 390 px, cero errores de consola).

## Marco: `Layouts/AppLayout.vue`

```vue
<AppLayout title="Licitaciones" :sections="[{ label: 'Lista', href: '/licitaciones', active: true }, { label: 'Calendario', href: '/calendario' }]">
    <PageHeader description="Procesos vigentes de la empresa activa">
        <template #actions><AppButton variant="primary" icon="plus" @click="open = true">Nueva licitación</AppButton></template>
    </PageHeader>
    <!-- el resto del contenido; ya va en `flex flex-col gap-6` -->
</AppLayout>
```

Props opcionales: `title` (cabecera de escritorio, barra superior móvil y pestaña del navegador),
`breadcrumbs`, `sections` (2 a 5 vistas hermanas), `backHref` (flecha «Volver» en móvil), `fabLabel`
(+ evento `fab`). Slots: `header-actions`, `topbar-actions`, `search`.

**El marco es persistente.** `Layouts/AppShell.vue` es el layout por defecto de Inertia (`app.js`): se
monta una sola vez y no se vuelve a montar al navegar (sidebar, cabecera, barra móvil, hoja «Más»,
toasts, confirmación, paleta ⌘K, asistente de empresa, franja de modo soporte). `<AppLayout>` dentro de
la página solo publica sus datos en el marco (`usePageMeta` de `Layouts/shell.js`) y envuelve el
contenido; las páginas no cambian. Sin sesión (acceso, errores) no hay marco.
- Durante una visita lenta la página no se congela: barra de progreso y, a los 300 ms, un esqueleto.
- `useShell()` da acceso al marco desde una página: `openCompanyWizard()`, `openSearch()`.
- Menú (14 entradas con subniveles), tarjetas de `/administracion`, menú del avatar (`accountMenu`) y
  barra móvil en `config/navigation.js`.
- Búsqueda global: `GET /buscar?q=` (`SearchController`) + `Layouts/Partials/CommandPalette.vue`.
- Rutas «solo JSON» (detalle de una modal): márcalas con
  `->middleware(\App\Http\Middleware\RedirectBrowserToScreen::class.':/pantalla?ver={param}')` y al
  abrirlas en el navegador redirigen a su pantalla en lugar de mostrar JSON crudo.
- Errores 403/404/419/429/500/503: `Pages/Errors/Show.vue` (`App\Support\ErrorPage`).
- Íconos: subconjunto generado (`node scripts/mdi-subset.mjs` tras usar un ícono `mdi-*` nuevo).

## Qué usar para qué

| Necesito… | Componente |
|---|---|
| Botón | `AppButton` · solo ícono: `IconButton` |
| Título de acciones de la página | `PageHeader` |
| Buscador + filtro de la lista | `Toolbar` (un buscador por pantalla) |
| Listado | `DataTable` + `Pagination` |
| Estado de un registro | `StatusBadge` |
| Cifra de tablero | `StatCard` |
| Formulario | `FormField` + `TextInput`, `TextArea`, `SelectInput`, `DateInput`, `DateTimeInput`, `MoneyInput`, `Checkbox`, `Toggle`, `SearchSelect` |
| Subir archivos | `FileDropzone` + `useUpload()` |
| Ver un PDF o imagen | `PdfViewer` |
| Modal | `BaseModal` |
| Confirmar algo irreversible | `useConfirm()` |
| Menú `⋮` de una fila | `DropdownMenu` |
| Repartir una ficha en secciones | `Tabs` |
| Datos de un registro | `DescriptionList` · historial: `Timeline` |
| Sin datos / sin resultados / error | `EmptyState` / `ErrorState` |
| Persona | `Avatar` · explicación breve: `Tooltip` |
| Aviso en línea, filtro de 2 a 5 opciones, esqueleto, kanban | `CapAlert`, `CapSegmented`, `CapSkeleton`, `CapKanban` de `resources/design/vue/` |

## Ejemplos

**AppButton / IconButton** — `variant`: `primary`, `secondary` (por defecto), `danger`.
```vue
<AppButton variant="primary" icon="content-save-outline" type="submit" :loading="form.processing">Guardar cambios</AppButton>
<AppButton href="/licitaciones">Cancelar</AppButton>
<AppButton external href="/licitaciones/export/pdf" icon="file-pdf-box">PDF</AppButton>
<IconButton icon="pencil-outline" label="Editar contacto" @click="edit(row)" />
```

**PageHeader / Toolbar**
```vue
<PageHeader :description="`${total} cotizaciones`"><template #actions>…</template></PageHeader>
<Toolbar v-model:search="filters.q" v-model:filter="filters.status" :filters="[{ label: 'Todas', value: 'all', count: 24 }, { label: 'Vencidas', value: 'overdue', count: 3 }]">
    <template #actions><AppButton icon="file-excel-outline">Exportar</AppButton></template>
</Toolbar>
```

**DataTable + Pagination** — columnas declarativas; por debajo de `lg` se vuelve tarjetas sola.
```vue
<DataTable :columns="[{ key: 'number', label: 'Cotización', primary: true, sortable: true }, { key: 'total', label: 'Total (L)', align: 'right', format: (v) => money(v, 'HNL', { symbol: false }) }, { key: 'status', label: 'Estado' }]"
    :rows="quotes.data" :loading="loading" :error="loadError" :filtered="Boolean(filters.q)" clickable
    empty-title="Aún no hay cotizaciones" @row-click="show" @retry="reload" @clear-filters="reset">
    <template #cell-status="{ value }"><StatusBadge :value="value" /></template>
    <template #actions="{ row }"><DropdownMenu :label="`Acciones de ${row.number}`" :items="actionsOf(row)" /></template>
    <template #empty-action><AppButton variant="primary" icon="plus" @click="create">Nueva cotización</AppButton></template>
</DataTable>
<Pagination :paginator="quotes" :only="['quotes']" />
```

**StatusBadge** — con `status` semántico o con `value` del dominio (mapa en `config/statuses.js`).
```vue
<StatusBadge status="warning" label="Vence en 3 días" />
<StatusBadge :value="tender.status" />
```

**StatCard** — una `featured` por fila; `count` anima la cifra al montar y vuelve a animarla cuando la prop cambia.
```vue
<StatCard variant="featured" label="Pipeline estimado" :count="248300000" format="currency-compact" hint="38 oportunidades" href="/pipeline" />
<StatCard variant="alert" label="Cartera vencida" :value="money(182450.75)" href="/cobros" />
```

**FormField + controles** — el control toma solo su id, `aria-invalid` y `aria-describedby`.
`DateInput` y `DateTimeInput` son propios (no el control nativo): se escriben y se ven `dd/mm/aaaa` y
`14:30` (con ayuda «2:30 p. m.»), abren un calendario (popover; hoja inferior en móvil) y su `v-model`
sigue siendo `AAAA-MM-DD` / `AAAA-MM-DDTHH:mm`. `Tabs` conserva montadas las pestañas ya abiertas.
```vue
<div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
    <FormField label="Nombre" required :error="form.errors.name"><TextInput v-model="form.name" /></FormField>
    <FormField label="Cierre" :error="form.errors.closing_at"><DateTimeInput v-model="form.closing_at" /></FormField>
    <FormField label="Monto" :error="form.errors.amount"><MoneyInput v-model="form.amount" v-model:currency="form.currency" :currencies="['HNL', 'USD']" /></FormField>
    <FormField label="Modalidad" optional><SelectInput v-model="form.modality" :options="modalities" /></FormField>
    <FormField label="Notas" class="md:col-span-2"><TextArea v-model="form.notes" maxlength="500" /></FormField>
    <Checkbox v-model="form.requires_bond" label="Requiere garantía" />
    <Toggle v-model="form.reminders" label="Recordatorios por correo" />
</div>
```

**SearchSelect** — local con `options`; remoto con `url` (GET `?q=`) o `search` (función async).
```vue
<SearchSelect v-model="form.institution_id" :options="institutions" option-value="id" option-label="name" />
<SearchSelect v-model="form.product_id" url="/inventario/buscar" option-value="id" option-label="name" option-description="sku" :selected="quote.product" />
```

**FileDropzone + useUpload**
```vue
<script setup>
const files = ref([]);
const uploader = useUpload();
const send = () => uploader.upload(`/licitaciones/${tender.id}/pliego`, files.value, { field: 'file', data: { kind: 'pliego' } });
</script>
<FileDropzone v-model="files" :uploader="uploader" accept=".pdf" :max-size-mb="40" multiple />
<AppButton variant="primary" :loading="uploader.uploading.value" :disabled="!files.length" @click="send">Subir pliego</AppButton>
```

**PdfViewer** — `page-overlay` es la capa por página para colocar firma y sello.
```vue
<PdfViewer :src="`/documentos/${document.id}/vista`" :name="document.name" height="h-[70vh]" @page-rendered="onPage">
    <template #page-overlay="{ page, width, height, scale }"><SignatureBox v-if="page === target.page" :scale="scale" /></template>
</PdfViewer>
```

**BaseModal** — `size`: `sm`, `md`, `lg`, `xl`, `full`; `as="form"` para enviar con Enter; `flush` para visores.
```vue
<BaseModal v-model:open="open" as="form" size="lg" title="Nueva licitación" :persistent="form.processing" @submit="save">
    <!-- campos -->
    <template #footer>
        <AppButton @click="open = false">Cancelar</AppButton>
        <AppButton variant="primary" type="submit" :loading="form.processing">Crear licitación</AppButton>
    </template>
</BaseModal>
```
Una modal puede abrir otra encima (ganada/perdida sobre la ficha) y también una confirmación; Esc cierra
solo la de arriba. Los combos y menús dentro de una modal no se recortan.

**useConfirm** — verbo y objeto; nunca «Sí/No» ni «Aceptar».
```js
import { useConfirm } from '@/composables/useConfirm.js';
import { visit } from '@/lib/http.js';
const confirm = useConfirm();
const remove = (row) => confirm({ verb: 'Eliminar', object: `el contacto ${row.name}`, noun: 'contacto',
    message: 'Se quitará del directorio de la institución. No se puede deshacer.',
    action: () => visit('delete', `/contactos/${row.id}`) });
```

**DropdownMenu**
```vue
<DropdownMenu :label="`Acciones de ${row.number}`" :items="[
    { label: 'Ver', icon: 'eye-outline', href: `/cotizaciones/${row.id}` },
    { label: 'Descargar PDF', icon: 'download-outline', href: row.pdf_url, external: true },
    { label: 'Eliminar', icon: 'trash-can-outline', danger: true, separator: true, onSelect: () => remove(row) },
]" />
```

**Tabs / DescriptionList / Timeline**
```vue
<Tabs v-model="tab" :tabs="[{ value: 'datos', label: 'Datos' }, { value: 'docs', label: 'Documentos', count: 4 }]">
    <template #datos><DescriptionList :items="[{ key: 'status', label: 'Estado', value: tender.status }, { label: 'Monto', value: money(tender.amount) }]">
        <template #status="{ item }"><StatusBadge :value="item.value" /></template>
    </DescriptionList></template>
    <template #docs><Timeline :items="[{ title: 'Pliego subido', meta: 'Ana Muñoz, 5 mar 2026, 8:15 a. m.', icon: 'file-document-outline' }]" /></template>
</Tabs>
```

**EmptyState / ErrorState**
```vue
<EmptyState icon="gavel" title="Aún no hay licitaciones" description="Importa una captura o créala a mano."><template #action><AppButton variant="primary">Nueva licitación</AppButton></template></EmptyState>
<EmptyState variant="no-results" @clear="resetFilters" />
<ErrorState compact title="No se pudieron cargar las entregas" :code="requestId" @retry="reload" />
```

**Avatar / Tooltip**
```vue
<Avatar :name="user.name" :initials="user.initials" size="sm" />
<Tooltip text="Solo un administrador puede cerrar el período."><AppButton disabled>Cerrar período</AppButton></Tooltip>
```

**CapKanban** (`resources/design/vue/CapKanban.vue`, paquete del sistema de diseño: **no se edita**).
Props: `columns` (`[{ id, title, addLabel?, emptyTitle?, emptyHint?, wipLimit? }]`), `cards` (`[{ id, column, title, … }]`),
`label`, `pickerLabel`. Eventos: `move` (`{ cardId, from, fromIndex, to, index }`: la tarjeta solo cambia de
columna cuando el padre actualiza `cards`; si no, el movimiento queda rechazado), `add`, `column-menu`, `card-menu`.
Slot `#card="{ card }"` para dibujar la tarjeta propia. Para extenderlo sin tocar el paquete:

- Contenido: usa el slot `#card` y guarda el registro completo aparte (busca por `card.id`), como `Pages/Pipeline/Index.vue`.
- Ocultar acciones de cabecera («+» y «⋯»), que no tienen prop: CSS `:deep` con alcance a un contenedor propio,
  apuntando a `.cap-kanban__action` y al atributo `data-cap-kanban-column="<id>"` de la columna.
```vue
<div class="pipeline-board" :data-readonly="can.create ? undefined : 'true'"><CapKanban … /></div>
<style scoped>
.pipeline-board :deep(.cap-kanban__action[aria-label='Opciones de la columna']),
.pipeline-board :deep([data-cap-kanban-column='adjudicado'] .cap-kanban__action),
.pipeline-board[data-readonly='true'] :deep(.cap-kanban__action) { display: none; }
</style>
```
- Si una necesidad se repite en más de un tablero, pídela como prop al paquete de diseño en lugar de sumar más `:deep`.

## Utilidades

- `lib/format.js`: `money(v, 'HNL' | 'USD')` → «L 12,345.67», `number`, `quantity`, `percent`, `date(v, 'short' | 'medium' | 'long')`
  → «05/03/2026» / «5 mar 2026», `time`, `dateTime`, `dateRange`, `relativeDays` → «en 3 días», `daysUntil`,
  `relativeTime` (solo notificaciones), `fileSize`, `text`. Todo en `es-HN` y `America/Tegucigalpa`.
- `lib/errors.js`: `fieldError(errors, 'items.0.price')`, `errorsFor(errors, 'items.0')`, `errorList`,
  `focusFirstError(root)`, `httpErrorMessage(status)`.
- `lib/http.js`: `visit(method, url, data)` (visita de Inertia como promesa), `getJson`, `postJson`.
- `lib/ui.js`: clases del recetario (`card`, `tableLink`, `textLink`, `iconButton`, `inputClass()`).
- `composables/useToasts` (de `design/vue/composables`): `useToasts().show({ status: 'ok', title, detail })`.
  El servidor los dispara solo con `->with('success' | 'error' | 'warning', 'texto')`.
- `config/statuses.js`: `statusOf('Vigente')` → `'ok'`. Los estados nuevos del dominio se agregan ahí.
- `config/navigation.js`: menú, pestañas del móvil y rutas del marco.
