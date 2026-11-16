# Módulo de documentos: piezas reutilizables

Adjuntos de cualquier registro y visor de documentos. El backend está en
`app/Http/Controllers/Documents/AttachmentController.php`, `app/Services/Documents/` y
`routes/modules/documents.php`.

## `AttachmentPanel.vue`

Lista, sube, muestra y quita los archivos adjuntos de una entidad. Se refresca solo tras subir o
eliminar.

```vue
<script setup>
import AttachmentPanel from '@/Pages/Directory/Partials/AttachmentPanel.vue';
</script>

<AttachmentPanel entity-type="contract" :entity-id="contract.id" :can-edit="can.update"
    :categories="['Contrato', 'Adenda', 'Orden de compra', 'Acta de recepción', 'Factura', 'Otro']" />
```

| Prop | Por defecto | Qué hace |
|---|---|---|
| `entityType` | — | `quote`, `tender`, `opportunity`, `contract` o `client` |
| `entityId` | — | id de la entidad (de la empresa activa) |
| `canEdit` | `false` | muestra «Agregar PDF» y «Eliminar» (el servidor lo vuelve a comprobar) |
| `accept` | `'.pdf'` | tipos que admite la zona de subida (`'.pdf,.jpg,.png'`…) |
| `maxSizeMb` | `40` | tope visible en la zona (el servidor aplica `Binaries::maxPdfKilobytes()`) |
| `categories` | `[]` | `[{ value, label }]` o textos; si hay, se pide la categoría al subir |
| `title`, `addLabel`, `emptyText` | `Adjuntos`, `Agregar PDF`… | textos |
| `compact` | `false` | menos relleno (dentro de una pestaña o una modal) |

Eventos:

- `view` — `{ id, name, previewUrl, downloadUrl, mime, fileName, signed }`. Si la pantalla lo
  escucha, la pantalla muestra el documento en su propio visor (por ejemplo, el visor grande de la
  modal «Ver» de la cotización). Si **no** lo escucha, el panel abre su propio
  `DocumentViewerModal`.
- `changed` — `{ action: 'uploaded' | 'deleted', items }` tras subir o quitar.
- `loaded` — la lista cada vez que se carga.

Slot `actions` por fila (`{ item, reload }`) para añadir acciones de otro módulo:

```vue
<AttachmentPanel ref="panel" entity-type="quote" :entity-id="quote.id" :can-edit="can.update" @view="show">
    <template #actions="{ item, reload }">
        <IconButton icon="draw" :label="`Firmar y sellar ${item.name}`" @click="sign(item).then(reload)" />
    </template>
</AttachmentPanel>
```

Expone `reload()` y `openUpload()` (`panel.value.reload()` tras firmar fuera del panel).

Cada elemento de la lista trae: `id` (documento), `linkId`, `name`, `fileName`, `extension`,
`category`, `description`, `mime`, `size`, `version`, `signed`, `signedAt`, `signedBy`,
`uploadedBy`, `uploadedAt`, `previewUrl`, `downloadUrl`, `versionsUrl`.

## `DocumentViewerModal.vue`

Modal lista para ver un PDF o una imagen, con barra de acciones.

```vue
<DocumentViewerModal v-model:open="viewerOpen" :document="current" :can-delete="can.update" @delete="removeAttachment">
    <template #actions="{ document }">
        <AppButton icon="draw" @click="sign(document)">Firmar y sellar</AppButton>
    </template>
    <template #aside>
        <!-- opcional: lista lateral de adjuntos en escritorio -->
    </template>
</DocumentViewerModal>
```

Props: `open` (v-model), `document` (`{ id, name, previewUrl, downloadUrl, mime, fileName? }`),
`title`, `description`, `canDelete`, `deleteLabel` (`Eliminar`), `deleting` (spinner en Eliminar).
Eventos: `delete` (con el documento; la pantalla confirma con `useConfirm()` y borra), `closed`.
Los tipos sin vista previa (Word, Excel) muestran un aviso y el botón Descargar.

## API del servidor

| Método y ruta | Uso | Permiso |
|---|---|---|
| `GET /adjuntos/{tipo}/{id}` | `{ data: [...], can: { edit } }` | `view` de la entidad |
| `POST /adjuntos/{tipo}/{id}` | `file` o `files[]`, `category`, `description`. JSON 201 con la lista; con Inertia vuelve atrás con toast. Errores 422 bajo `file` o `files.N`; si uno falla no se guarda ninguno | `update` de la entidad, o `manageAttachments` si su policy lo define |
| `DELETE /adjuntos/{tipo}/{id}/{document}` | quita el adjunto; si el documento no queda enlazado a nada se borra con sus versiones | ídem |
| `GET /documentos/{id}/vista` · `/download` | archivo vigente en línea o como descarga (streaming, `nosniff`, `Range`) | `view` del documento |
| `GET /documentos/{id}/versiones` | historial (`previewUrl` y `downloadUrl` por versión) | `view` |

Nuevos tipos de entidad: una línea en `App\Services\Documents\Attachables::TYPES`.

Desde PHP (otro servicio que guarda un archivo de una entidad):

```php
app(DocumentService::class)->create(['name' => 'Acta', 'kind' => 'contrato'], $file, $user, $contract, ['category' => 'Acta de recepción']);
app(DocumentService::class)->storeDerivedVersion($document, $pdfBytes, 'acta-firmada.pdf', $user); // versión firmada
```
