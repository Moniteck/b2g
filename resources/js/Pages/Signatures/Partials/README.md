# Módulo de firmas: `SignDocumentModal`

Firma y sello son **personales**: cada usuario sube los suyos en `/firmas` («Mi firma y sello») y solo
él puede verlos y aplicarlos. Firmar exige el permiso `sign_documents` (operador y administrador) en la
empresa del documento y permiso de verlo.

## Firmar un documento del sistema (cotizaciones, contratos…)

```vue
<script setup>
import { ref } from 'vue';
import SignDocumentModal from '@/Pages/Signatures/Partials/SignDocumentModal.vue'; 

const signing = ref(null); // { id, name, previewUrl }
const open = ref(false);

function sign(document) {
    signing.value = { id: document.id, name: document.name, previewUrl: `/firmas/documentos/${document.id}/vista` };
    open.value = true;
}

function onSigned(document) {
    // document: { id, name, version, signed_at, signed_by, previewUrl, downloadUrl }
    router.reload({ only: ['quote'] });
}
</script>

<SignDocumentModal v-model:open="open" :document="signing" @signed="onSigned" />
```

- `previewUrl` puede ser cualquier ruta autorizada que sirva el PDF vigente; `/firmas/documentos/{id}/vista`
  ya comprueba `view` sobre el documento.
- Solo se firman PDF. El resultado es **una versión nueva** del documento (el original queda como versión
  anterior) con `signed_at`/`signed_by`; firma y sello van en la misma versión.
- Si el usuario ya firmó ese documento, la modal pide confirmación antes de volver a firmar.
- Si no tiene firma o sello, la modal le deja subirlos ahí mismo sin cerrarse.
- Muéstrala solo si `auth.can.sign_documents` es verdadero; el servidor lo vuelve a comprobar.

## Firmar un PDF suelto

```js
const file = files.value[0];
signing.value = { id: null, name: file.name, previewUrl: URL.createObjectURL(file), file, saveToDocuments: true, documentName: 'Oferta firmada' };
```

Con `saveToDocuments` el PDF se guarda en los documentos de la empresa activa (original como versión 1 y
firmado como versión 2) y `signed` recibe ese documento; sin él, el PDF firmado queda solo para el
firmante y `signed` recibe `null` y, como segundo argumento, la respuesta completa
(`response.application.downloadUrl`). Libera la URL con `URL.revokeObjectURL` al cerrar.

## Contrato

| | |
|---|---|
| Props | `open` (`v-model:open`), `document` (`{ id, name, previewUrl }` o `{ id: null, name, previewUrl, file, saveToDocuments?, documentName? }`) |
| Eventos | `signed(document \| null, response)`, `update:open` |
| Colocación | Firma y sello arrastrables y redimensionables (ratón y táctil; teclado: flechas, Mayús + flechas, «+» y «−»). Página por defecto: la última; opción «Aplicar en todas las páginas». Parte de la última posición que usó el usuario. |

## Crear la firma o el sello: `AssetCreatorModal`

`AssetCard` ya la abre («Crear firma», «Crear sello», «Subir imagen», «Cambiar»), también dentro de
`SignDocumentModal` cuando al usuario le falta la firma o el sello. Para abrirla desde otro sitio:

```vue
<AssetCreatorModal v-model:open="open" type="firma" tab="draw" :company="{ name, tax_id }" @saved="reload" />
```

| Pestaña | Qué hace |
|---|---|
| `draw` (firma) | `SignaturePad.vue`: pointer events (ratón, lápiz, dedo), curvas suavizadas y grosor según velocidad o presión; color azul tinta o negro, fino/medio/grueso, deshacer, limpiar, línea guía. En un teléfono en horizontal la modal ocupa la pantalla. |
| `type` (firma) | Nombre con 6 fuentes manuscritas OFL de `./fonts` (cargadas al abrir la modal), tamaño e inclinación. |
| `stamp` (sello) | Sello circular o rectangular con nombre de la empresa activa, RTN y leyenda, en 5 tintas. |
| `upload` | PNG o JPG; «Quitar el fondo blanco» para fotos sobre papel. |

Todas exportan un **PNG transparente recortado** (`ink.js › trimCanvas`, máx. 2400 px por lado) y lo
suben por `POST /firmas/activos` con la misma validación (2 MB). `company` sale de la prop `company`
de `/firmas` o de `company` en `GET /firmas/estado`. Utilidades reutilizables en `ink.js`
(`trimCanvas`, `canvasToFile`, `removeLightBackground`, `loadHandwritingFonts`) y `renderers.js`
(`renderTyped`, `renderStamp`).

## Endpoints JSON (`routes/modules/signatures.php`)

| Método y ruta | Uso |
|---|---|
| `GET /firmas/estado?document_id=` | `{ assets: { firma, sello }, last_placements, can_sign, company: { name, tax_id }, document: { already_signed, last_signed_at } }` |
| `POST /firmas/activos` | Subir o reemplazar (`type`: `firma` \| `sello`, `file`: PNG o JPG ≤ 2 MB) |
| `DELETE /firmas/activos/{type}` | Quitar (el historial de firmas aplicadas se conserva) |
| `GET /firmas/activos/{type}/imagen` | Imagen propia (nadie más la recibe) |
| `POST /firmas/firmar` | `{ document_id, use_signature, use_seal, all_pages, confirm, placements: [{ type, page, x, y, width, height }] }` en % de la página, origen arriba a la izquierda. 201 con `{ message, document, application }`; 409 si ya lo firmó y no confirmó; 422 con mensaje si el PDF no se puede abrir. |
| `POST /firmas/firmar-archivo` | Igual, multipart con `file` (PDF), `save_to_documents`, `document_name`; `placements` como JSON. |
| `GET /firmas/documentos?q=` | PDF de la empresa activa para el buscador. |
| `GET /firmas/aplicadas/{id}/descargar` · `/vista` | PDF firmado de una firma aplicada. |

En el servidor: `App\Services\Documents\SignatureService::signDocument($document, $user, $options)` y
`::stamp($pdfPath, ['firma' => $png], $placements)` para estampar sin guardar.
