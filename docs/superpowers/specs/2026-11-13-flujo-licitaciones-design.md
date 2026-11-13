# Diseño: flujo de licitaciones B2G completo y blindado

Fecha: 2026-11-13 · Estado: borrador para aprobación

## 1. Propósito

El CRM B2G debe acompañar una licitación pública hondureña de principio a fin sin que se
pierda una fecha ni un documento:

1. El usuario sube la **captura de HonduCompras** y el **pliego**; el sistema lee los datos y las fechas.
2. Las fechas viven en el **calendario** y generan **recordatorios insistentes**, sobre todo el
   cierre de aclaraciones (último día para hacer preguntas).
3. Se arma la **cotización**, se le adjuntan PDF, se **firma y sella** y se presenta.
4. Se registran los **participantes** y sus montos; el resultado es **Ganada** o **Perdida**.
5. Si se gana, nacen el **cliente** y el **contrato** de post-adjudicación, que se sigue hasta el cierre.
6. Todo lo anterior también se puede operar por **MCP** desde ChatGPT o Claude.

Por qué ahora: el código de casi todo esto existe a medias (hecho el 2026-11-13, sin commits),
pero una auditoría mostró que casi ninguna pantalla nueva funciona de punta a punta. Este diseño
cubre terminar lo que falta y cerrar los huecos encontrados.

## 2. Objetivos, no objetivos y anti-objetivos

**Objetivos**

- Cada punto del pedido original funciona de punta a punta en el navegador, con datos reales.
- Ningún dato cruza de una empresa del grupo a otra.
- Ninguna fecha leída por OCR o IA se guarda sin que una persona la confirme.
- Toda acción que falla muestra un mensaje claro en español; nada falla en silencio.
- Subidas de archivo, firma y resultado comercial resisten doble clic, reintentos y archivos dañados.
- Pruebas automáticas cubren los flujos críticos y el aislamiento entre empresas.

**No objetivos**

- Descargar datos de HonduCompras automáticamente (scraping). La entrada es la captura que sube el usuario.
- Firma electrónica con certificado digital. "Firmar y sellar" es estampar imágenes sobre el PDF.
- Firmas administradas por empresa, autorización previa con código, revocación por administrador.
- Recordatorios por WhatsApp o SMS. Canales: correo y campana dentro de la app.
- Módulos de Garantías Bancarias, Compras Menores y Solicitud de Cotización (se eliminan).
- Rediseñar módulos que el pedido no toca (CRM político, POA, consorcios, cobros, etc.). Solo se
  garantiza que vuelvan a abrir tras arreglar los permisos.

**Anti-objetivos** (sería un fracaso aunque "funcione")

- Una fecha de cierre equivocada en el calendario porque el OCR o la IA la leyó mal y nadie la revisó.
- Un recordatorio que no llega, o que sigue llegando de una licitación cancelada.
- Un PDF original sobrescrito por su versión firmada, o un PDF firmado que pierde páginas.
- Un usuario que ve, firma o modifica algo de otra empresa o con la firma de otra persona.
- Un cliente o contrato duplicado por un doble clic.

## 3. Restricciones

- Reglas del proyecto (`MIGRATION_RULES.md`): un único `.env`; no instalar paquetes sin necesidad;
  sin rutas absolutas de Windows ni configuración de una PC concreta; sin secretos en el código;
  mantener `MIGRATION_PROGRESS.md` al día; paridad visual con el estilo actual de la app.
- La regla 6 dice no ejecutar la suite completa automáticamente. Este trabajo sí escribe y ejecuta
  pruebas: se corren las pruebas de la fase en curso y, al cerrar cada fase, la suite completa.
  Es un cambio a esa regla y queda sujeto a la aprobación de este documento.
- Stack existente: Laravel 13, Inertia 3, Vue 3, MySQL (`crm_b2g`), cola `database`, dompdf, Passport, `laravel/mcp`.
- IA: `OpenAIService` existente. Sin `OPENAI_API_KEY` todo debe fallar de forma controlada y
  permitir la carga manual.
- Herramientas del sistema necesarias en cada servidor: Tesseract 5 con idioma `spa` y Poppler
  (`pdftotext`, `pdftoppm`, `pdftocairo`). Sus rutas se configuran por variable de entorno.
- Antes de tocar interfaz o base de datos, el constructor carga las guías `cap-design` y
  `cap-database`. Si chocan con la paridad visual o con el esquema existente (tablas y columnas
  en inglés), gana lo existente y se anota la diferencia.

## 4. Estado actual (resumen de la auditoría)

| Área | Situación hoy |
|---|---|
| Arranque | `AppServiceProvider` sin `namespace`: Laravel lo ignora. No hay gates, ni policies manuales, ni limitador `mcp`, ni configuración de Passport. Calendario, Notificaciones, Configuración e IA dan 403 a todos. `GET /` da 500 (`routes/web.php:29`). |
| Multiempresa | Registros creados sin `company_id` quedan invisibles (licitaciones, KPIs, etc.). `company_id` se acepta del cliente sin comprobar acceso. El binding de rutas se resuelve antes de fijar la empresa. `role = admin` equivale a raíz de todo el grupo. |
| Cotizaciones | Listado, Ganada/Perdida, participantes y subida existen. No hay formulario de crear ni editar. La modal "Ver" no tiene visor, Firmar ni Eliminar. Participantes pierde datos al guardar. Ganada no es idempotente y no crea el contrato. |
| Firma | No funciona: FPDI sin FPDF y TCPDF 7 incompatible. El estampado dejaría una sola página. La página `/firmas` no existe. |
| Documentos y clientes | `Documents.vue`, `Clients.vue` y `Contracts.vue` leen claves distintas a las que envía el servidor: tablas en blanco. Límite real de subida: 2 MB. |
| Licitaciones | Sin OCR ni pliego. La pantalla no tiene los campos de fechas críticas. Sus fechas no llegan al calendario. |
| Calendario y recordatorios | Tabla, no calendario. El envío de correo revienta. Sin campana. Sin aviso el mismo día. Zona horaria UTC. El scheduler no corre en desarrollo. Avisa de registros borrados. |
| Kanban | 5 columnas correctas. Cualquier transición permitida. No sincroniza con la cotización. El botón de participantes nunca aparece. |
| Post-adjudicación | Backend con campos; pantalla rota; sin subida de PDF; sin eventos de entregas. |
| Blessing | Solo columnas. No hay cliente de API. Tabla vacía. |
| MCP | 19 herramientas escritas, servidor inoperante: limitador indefinido, nadie crea `McpConnection`, varias escrituras sin policy. |
| Pruebas | La suite muere por el `namespace`. Solo existe `UserFactory`. Nada de lo nuevo está cubierto. |
| Restos | Migración que borra `guarantees` pendiente; tabla `quote_requests`; accesos a Compras Menores en el Dashboard; etapas antiguas en seeder y métricas. |

## 5. Enfoques considerados

**A. Cimientos primero y luego el flujo en orden (elegido).** Se arregla la base (arranque,
permisos, multiempresa, piezas de interfaz compartidas) y después se construye siguiendo el
recorrido real: licitación → calendario → cotización → firma → kanban → cliente → contrato →
inventario → MCP. Cada fase deja algo que el usuario puede probar en el navegador.
A cambio, las funciones nuevas más visibles (OCR, pliego) no llegan el primer día.

**B. Funciones nuevas primero, blindaje al final.** Daría OCR y pliego antes, pero se
construirían sobre permisos que no cargan y un aislamiento entre empresas roto; cada pantalla
nueva heredaría los defectos y habría que rehacerla. Descartado.

**C. Rehacer desde cero los módulos rotos.** El backend existente (servicios de adjudicación,
documentos, calendario, tenancy) está razonablemente bien planteado; los fallos son de cableado,
de pantallas y de casos límite. Reescribir tiraría trabajo útil. Descartado.

El usuario delegó el cómo; se sigue el enfoque A.

## 6. Decisiones

**Del usuario**

- Firma y sello son **personales**: cada usuario sube los suyos y firma con ellos, desde su
  pantalla de firma y desde la cotización.
- La captura de HonduCompras tiene el formato de la ficha de expediente (tabla Expediente,
  Entidad, Unidad de Compra, Objeto, fechas, Modalidad, Etapa, Contacto…).
- El pliego de referencia es un PDF escaneado de 41 páginas con capa de texto.
- Se eliminan Solicitud de Cotización, Compras Menores y Garantías Bancarias.
- El cliente se registra solo al ganar una oferta.
- Kanban: Prospecto | Oportunidad | Oferta Presentada | Adjudicado | No Adjudicado.

**Mías (propuestas; se pueden cambiar al revisar)**

1. Las listas muestran **la empresa activa**, elegida con un selector en la cabecera, no la mezcla
   de todas las empresas del usuario.
2. `role = admin` deja de ser raíz del grupo. Raíz es solo `is_root`. El rol por empresa
   (`company_user.role`) decide dentro de cada empresa.
3. **Directorio Institucional** (`/directorio`) pasa a ser el directorio de documentos de la
   empresa (RTN, DNI, solvencias…), absorbiendo el actual "Directorio Documental". El catálogo
   de instituciones compradoras se conserva como **Instituciones** en Administración, porque
   las cotizaciones y licitaciones lo necesitan.
4. En la modal "Ver" de una cotización, **Eliminar** borra el PDF adjunto que se está viendo.
   El PDF generado de la cotización no se elimina; eliminar la cotización entera es una acción
   aparte en la fila, solo en estado Borrador.
5. **Revertir** un resultado Ganada/Perdida: solo administrador de la empresa, con motivo
   obligatorio, y solo si el contrato creado no tiene actividad.
6. Las **garantías que exige el pliego** se guardan como datos del análisis y como ítems de la
   matriz documental. No hay módulo de garantías.
7. "Compra Menor" se conserva como valor de modalidad (HonduCompras lo usa); lo que se elimina
   es el módulo.
8. Acciones sensibles por MCP (Ganada/Perdida, firmar, eliminar) usan **confirmación en dos
   pasos**: la primera llamada devuelve un resumen y un código de un solo uso; la segunda lo confirma.
9. Recordatorios: además de las anticipaciones, **aviso el mismo día**, **repetición diaria en
   los últimos 3 días** y **resumen diario** por correo a las 7:00 a. m. El usuario puede marcar
   un plazo como "atendido" para detener sus avisos.
10. Al confirmar una licitación importada se ofrece crear su oportunidad en Prospecto (marcado por defecto).

11. **Fechas**: la app usa la zona `America/Tegucigalpa` y guarda las fechas en hora de Honduras
    (el país no tiene horario de verano). No se convierten a UTC. Las fechas ya guardadas se
    capturaron como hora local, así que se dejan como están.
12. **Datos existentes**: la base actual es local y de demostración (1 empresa "CAP Honduras",
    3 usuarios `admin`, sin membresías, 1 cotización, 2 oportunidades, 1 documento). Los cambios
    de esquema van en migraciones nuevas; no se editan las ya aplicadas. Una migración de datos
    asigna a CAP Honduras todo registro con `company_id` nulo, hace a los tres usuarios
    administradores de CAP Honduras y marca `root@crm-b2g.test` como raíz.
13. **Licitación, oportunidad y cotización**: una licitación tiene una oportunidad; una
    oportunidad puede tener varias cotizaciones (revisiones o alternativas). El resultado es
    por oportunidad: al marcar una cotización como Ganada, las demás cotizaciones vivas de esa
    oportunidad pasan a Sustituida; al marcar Perdida, todas quedan Perdida. No hay adjudicación
    por lotes: una adjudicación parcial se registra con el monto adjudicado real, que puede ser
    menor al total cotizado. Hay un contrato por oportunidad ganada, también cuando no tenía
    cotización. Mover la tarjeta a Oferta Presentada pone su cotización en Presentada, y
    marcar la cotización como Presentada mueve la tarjeta.
14. **"Atendido"** es por plazo: detiene los avisos de ese plazo para todos y registra quién lo
    marcó. El **resumen diario** va a cada usuario con los plazos de los que es responsable o
    creador; los administradores de la empresa reciben todos.
15. **Revertir resultado**: el contrato pasa a Cancelado (no se borra), el cliente se conserva
    y se descuenta el monto de su acumulado, la oportunidad vuelve a Oferta Presentada y los
    recordatorios de la oferta que sigan vigentes se reprograman. "Actividad" que impide
    revertir: documentos subidos, entregas o seguimientos en el contrato.
16. **Archivos de referencia**: la captura de ejemplo y el pliego están en la carpeta
    `Referencias` del escritorio del usuario (el pliego es
    `Pliegos de Condiciones LPN-007 (1).pdf`; la captura es la imagen que el usuario envió en
    la conversación de diseño). La primera tarea de la Fase 5 los copia a `tests/fixtures`:
    la captura completa y, del pliego, solo el texto extraído por página (el PDF pesa 13 MB).

**Pendientes del usuario** (ver sección 10)

- Regla de ISV del precio de Blessing.
- Datos de la API de Blessing.

## 7. Diseño por fase

Cada fase termina con sus pruebas en verde, verificación en el navegador y una entrada en
`MIGRATION_PROGRESS.md`.

### Fase 0 — Cimientos

- `namespace App\Providers;` en `AppServiceProvider`. Corregir `GET /`.
- `config/app.php`: zona horaria `America/Tegucigalpa` e idioma `es` (decisión 11).
- Dependencias PDF: acotar con `^` las que están en `*`; fijar `tecnickcom/tcpdf:^6.8` para que
  FPDI funcione; quitar `laravel/sanctum` si se confirma que no se usa.
- Entorno de pruebas: que `composer test` cargue `pdo_sqlite` sin pasos manuales. Reparar las
  5 pruebas existentes que fallan.
- Subidas: documentar y validar al arrancar los límites de PHP (`upload_max_filesize`,
  `post_max_size`, `memory_limit`). Una pantalla de diagnóstico para administradores muestra si
  Tesseract, Poppler, la cola, el scheduler y los límites están bien.
- `.env.example` real: MySQL, zona horaria, OpenAI, Resend, Passport, MCP, rutas de Tesseract y
  Poppler, Blessing.
- `composer dev` levanta también `schedule:work`. README con worker y scheduler para producción.
- Quitar `POST /configuracion/seed` de la interfaz web (queda solo por consola). Reemplazar la
  contraseña literal de `AdministratorAccessTest` por una generada.
- Limpieza de módulos eliminados: ejecutar la migración que borra `guarantees`; migración para
  `quote_requests`; quitar accesos y contadores de Compras Menores en `Dashboard.vue`; campos
  del antiguo flujo en `SpecializedActionRequest` y `SpecializedPage`; `config/pipeline.js`;
  etapas y estados antiguos en seeder, `DashboardMetricsService`, `KpiService` y `ReportService`.
- Git: primer commit del estado actual antes de cambiar nada, y un commit por tarea después.
  (El commit lo autoriza el usuario al aprobar el plan.)
- Formato: Pint solo sobre los archivos que se toquen. Los controladores minificados en una
  línea se desminifican cuando se editan.

### Fase 1 — Multiempresa y permisos

- `BelongsToCompany` asigna `company_id` de la empresa activa al crear si no viene.
- Regla de validación reutilizable "empresa permitida" en todo `store`/`update` que acepte
  `company_id`, y "pertenece a la empresa" para ids relacionados (licitación, cotización,
  oportunidad, contacto, documento, responsable).
- `ResolveCompanyContext` corre antes de `SubstituteBindings`, de modo que un id de otra
  empresa da 404.
- El scope filtra por empresa activa. Selector de empresa en `AppLayout`; la elección se guarda en sesión.
- Separar raíz de administrador (decisión 2). Comando de consola para marcar un usuario como raíz.
- Policies: registrar las de `OperationalPolicy`; añadir `authorize` en los controladores que no
  lo tienen (reportes, dashboard, agente legal, inventario, IA, calculadora, capacitación, SOP);
  `ProcessHistory` gana `company_id` y policy real; `KpiGoalPolicy` usa el rol por empresa.
- Modelos con `company_id` sin el trait lo adoptan (`ReminderRule`, `ReminderDelivery`,
  `InventorySyncRun`, `AuditLog`, firmas). Instituciones y contactos siguen como catálogo compartido.
- Configuración por empresa en lugar de `Company::first()`.
- Reparar datos existentes: asignar empresa a los registros con `company_id` nulo.
- Pruebas de aislamiento: para cada recurso, un usuario de la empresa A no lista, ve, edita ni
  borra lo de la B, ni crea en ella.

### Fase 2 — Piezas de interfaz compartidas

Componentes Vue reutilizables, con el estilo actual de la app, que usan todas las fases siguientes:

- `BaseModal` (título, cuerpo, pie, cierre con Esc y clic fuera, foco atrapado).
- `ConfirmDialog` para toda eliminación o acción irreversible. Se retiran `confirm()` y `prompt()`.
- `FileDropzone`: arrastrar o elegir, tipos y tamaño máximos visibles, barra de progreso, errores por archivo.
- `PdfViewer`: vista de un PDF servido por una ruta autorizada.
- `ToastHost` y manejo uniforme de errores de validación del servidor bajo cada campo.
- Estados vacío, cargando y error; botones deshabilitados mientras se envía.
- `ResourceCrud` muestra errores de validación. Se corrigen los usos de `window` y `$router` en plantillas.

### Fase 3 — Documentos y Directorio Institucional

- Un solo camino de subida (`DocumentService`): valida tipo real y tamaño, comprueba que el PDF
  se puede abrir (un PDF truncado o cifrado se rechaza con mensaje claro), guarda en disco
  privado, todo en transacción; si algo falla no quedan archivos huérfanos.
- Límites: PDF hasta 40 MB, imágenes hasta 10 MB. Configurables.
- Descarga y vista previa por streaming, con policy y cabecera `nosniff`.
- `/directorio` (Directorio Institucional): documentos de la empresa por categoría (RTN, DNI
  del representante, Solvencia fiscal, Solvencia municipal, IHSS, INFOP, RAP, Constancia ONCAE,
  Escritura, Poder, Permiso de operación, Otros), con emisión, vencimiento y estado
  (Vigente, Por vencer, Vencido).
  Acciones con modal: subir, ver, reemplazar (nueva versión), renovar (nueva fecha y archivo),
  historial de versiones, descargar, eliminar. Filtros y paginación.
- El vencimiento de un documento crea su evento y sus recordatorios (30, 7 y 1 día).
- Selección múltiple "Descargar para oferta": arma un ZIP con los documentos vigentes elegidos.
- `/documentos` redirige a `/directorio`. Instituciones pasa a `/instituciones`.

### Fase 4 — Firma y sello por usuario

- Cada usuario tiene como máximo una **firma** y un **sello** activos. Se reutiliza
  `signing_assets` con `user_id` como dueño y `type` (`firma`, `sello`).
- Pantalla **Mi firma y sello** (`/firmas`): subir PNG o JPG (hasta 2 MB, se recomienda fondo
  transparente), vista previa, reemplazar, quitar. Nadie más ve ni usa esas imágenes; se sirven
  por ruta autorizada desde disco privado.
- En la misma pantalla, **Firmar un documento**: subir un PDF o elegir uno del sistema, colocar
  firma y sello, firmar y descargar o guardar.
- **Colocación**: visor con la página elegida (por defecto la última) y firma y sello
  arrastrables y redimensionables. Las posiciones se guardan en porcentaje de la página. Se
  puede firmar solo con firma, solo con sello o con ambos, y aplicar a una página o a todas.
- **Estampado** (`SignatureService` reescrito): FPDI sobre TCPDF 6; importa todas las páginas
  con su tamaño y orientación; firma y sello en una sola operación y una sola versión nueva;
  detecta el tipo de imagen. El original nunca se sobrescribe.
- **PDF que FPDI no puede abrir** (xref comprimido, PDF 1.5+): se normaliza con Poppler
  (`pdftocairo -pdf`) y se reintenta. Si sigue sin abrir, o está cifrado, error 422 con mensaje claro.
  El constructor debe verificar este respaldo con el pliego de referencia antes de darlo por bueno.
- **Registro**: `signature_applications` guarda quién, cuándo, documento, versión, páginas y
  posiciones. Un documento ya firmado por el mismo usuario pide confirmación antes de volver a firmarse.
- Borrar una firma no borra el historial de firmas ya aplicadas.
- Se retira el código de autorización previa y de firmas por empresa.

### Fase 5 — Licitaciones: captura y pliego

**Pantalla.** `Tenders.vue` se rehace como página propia: lista con filtros, y ficha con todos
los datos, fechas críticas, documentos, análisis del pliego y enlaces a su oportunidad y cotizaciones.

**Campos nuevos en `tenders`**: unidad de compra, fecha y hora de inicio, tipo de fuente,
fuente, etapa en HonduCompras, tipo de adquisición, lugar de recepción de ofertas, valor de
pliegos, vigencia de la oferta en días.

**Importar captura de HonduCompras**

1. El usuario sube una imagen (PNG, JPG o WebP) desde "Importar captura".
2. Tesseract (`-l spa`) la lee. Un analizador busca las etiquetas conocidas de la ficha
   (Expediente, Entidad, Unidad de Compra, Objeto, Fecha de Inicio, Fecha Recepción Ofertas,
   Fecha Cierre Aclaratorias, Tipo Fuente, Fuente, Modalidad, Etapa, Tipo Adquisición, Lugar
   Recepción Ofertas, Valor Pliegos, Contacto) tolerando errores de lectura, e interpreta las
   fechas `dd/mm/aaaa hh:mm:ss a.m./p.m.` en hora de Honduras.
3. **Pantalla de revisión**: la captura a un lado y los campos al otro, cada uno marcado como
   leído, dudoso o no encontrado. El usuario corrige y confirma. Sin confirmación no se guarda nada.
4. La entidad se empareja con el catálogo de instituciones por nombre aproximado; el usuario
   elige o crea.
5. Si ya existe una licitación con ese expediente en la empresa, se ofrece actualizarla en vez de duplicarla.
6. La captura queda guardada como documento de la licitación.
7. Si Tesseract no está instalado o no lee nada útil, se avisa y se abre el formulario manual.

Prueba de referencia: sobre la captura de ejemplo (expediente CPR-SIT-025-2022) el analizador
debe obtener las tres fechas y la entidad sin intervención; el expediente puede salir marcado como dudoso.

**Importar pliego**

1. El usuario sube el PDF del pliego en la ficha de la licitación. Se guarda como documento.
2. Un trabajo en cola extrae el texto por página con `pdftotext`. Si una página casi no tiene
   texto, se convierte a imagen con `pdftoppm` y se lee con Tesseract.
3. La IA recibe el texto completo, por tramos si no cabe, marcado como dato no confiable (nunca
   como instrucciones), y devuelve JSON con, para cada dato: valor, **cita textual** y **página**.
4. Datos a extraer: número y nombre del proceso, entidad; cierre de aclaraciones; fecha, hora y
   lugar de presentación; fecha y hora de apertura; vigencia de la oferta; garantías exigidas
   (tipo, porcentaje, vigencia); plazo de entrega o contractual; documentos a presentar; lista
   de productos o servicios (descripción, cantidad, unidad); criterios de evaluación; contacto
   para consultas.
5. El servidor comprueba que cada cita aparece de verdad en la página indicada. Lo que no se
   pueda verificar se marca "sin evidencia" y nunca se aplica solo.
6. **Pantalla de revisión**: cada dato con su cita y un enlace que abre el PDF en esa página.
   Si una fecha del pliego difiere de la que vino de la captura, se muestran las dos y el
   usuario elige. Al confirmar se actualizan la licitación, el calendario y los recordatorios.
7. Desde la revisión: "Crear matriz documental" con los documentos exigidos y "Crear cotización
   borrador" con la lista de productos.
8. Estados visibles del análisis: En cola, Procesando, Listo para revisar, Confirmado, Fallido
   (con motivo y botón de reintentar). Sin clave de OpenAI: mensaje claro y carga manual.

Prueba de referencia: con el texto del pliego LPN-007 la revisión debe proponer cierre de
aclaraciones 17/09/2026, presentación 30/09/2026 y apertura 30/09/2026 10:15 a. m.

El texto extraído y el resultado se guardan en `document_extractions`. El pliego se envía a
OpenAI; son documentos públicos, pero se deja indicado en la pantalla.

### Fase 6 — Calendario y recordatorios

**Calendario** (`Calendar.vue`, sale de `SpecializedPage`)

- Rejilla mensual con navegación, vista de agenda y filtro por tipo.
- Cada licitación aparece como una barra de **inicio a fin** (fecha de inicio → recepción de
  ofertas), con marcas en cierre de aclaraciones y apertura. Cada contrato, como barra de
  inicio a fin, con marcas en entregas.
- Colores por tipo: aclaraciones, presentación, apertura, contrato, entrega, documento, manual.
- Clic en un evento: modal con detalle, enlace al registro de origen, "Marcar como atendido",
  y para eventos manuales, editar y eliminar. Modal para crear evento manual con sus recordatorios.
- La construcción de la rejilla (componente propio o librería) queda a criterio del constructor.

**Eventos derivados.** Observadores sobre licitación, oportunidad, contrato, entrega,
seguimiento y documento crean y actualizan sus eventos al guardar. Al cambiar una fecha, el
evento se actualiza en su sitio (no se borra) y los recordatorios pendientes se reprograman
conservando el historial de enviados. Al eliminar, cancelar, cerrar o marcar perdida, los
recordatorios pendientes se cancelan.

**Recordatorios**

| Tipo de plazo | Avisos por defecto |
|---|---|
| Cierre de aclaraciones | 7, 3, 2 y 1 día antes; el mismo día a las 8:00 a. m. |
| Presentación de ofertas | 7, 3, 2 y 1 día antes; el mismo día a las 7:00 a. m. |
| Apertura | 1 día antes y el mismo día |
| Fin de contrato | 30, 15, 7 y 1 día antes |
| Entrega de contrato | 7, 3 y 1 día antes; el mismo día |
| Próxima acción de seguimiento | el mismo día |
| Vencimiento de documento | 30, 7 y 1 día antes |

- Se programan al crear o editar el evento, no solo en la corrida nocturna. Si un plazo se
  carga con menos de un día de margen, se envía un aviso inmediato.
- Destinatarios: responsable del registro, quien lo creó y administradores de la empresa.
- Canales: correo y **campana** en la cabecera, con contador, lista, marcar leído, y enlace al
  registro. Página "Mis recordatorios" con historial.
- **Resumen diario** a las 7:00 a. m. (hora de Honduras): plazos de hoy y de los próximos 7 días.
- Reglas configurables por empresa (anticipaciones, canales, destinatarios extra) en Configuración.
- Sin duplicados: clave única por evento, anticipación, canal y destinatario; envío con bloqueo.
- Se arregla el despacho (`->after()` inexistente), la bandeja deja de reencolarse cada minuto,
  y un correo fallido se reintenta con espera y queda visible como fallido.
- El Dashboard muestra "Plazos de esta semana".

### Fase 7 — Cotizaciones

- **Formulario de crear y editar** (modal amplia): empresa, institución, contacto, licitación u
  oportunidad vinculada, líneas con buscador de inventario, y totales. Editable solo en Borrador o Emitida.
- **Acciones por fila**: Ver, Agregar PDF, Participantes, Ganada, Perdida, y en un menú:
  editar, cambiar estado, enviar por correo, eliminar (solo Borrador).
- **Agregar PDF**: `FileDropzone`, solo PDF, varios a la vez, con descripción opcional.
- **Modal Ver**: visor del PDF de la cotización y, a un lado, la lista de sus PDF adjuntos;
  al elegir uno se muestra en el visor. Pie: **Firmar y sellar**, **Agregar PDF**, **Descargar**,
  **Eliminar**, **Cerrar**.
  - Firmar y sellar abre la colocación de la Fase 4 sobre el PDF que se está viendo. Si el
    usuario no tiene firma ni sello, se le ofrece subirlos ahí mismo. La versión firmada queda
    como adjunto marcado "Firmado por … el …".
  - Eliminar sigue la decisión 4, con confirmación.
  - La lista se refresca tras subir, firmar o eliminar.
- **Ganada**: modal con monto adjudicado y fecha. En una transacción con bloqueo: comprueba que
  el resultado sigue Pendiente; crea o reutiliza el cliente (restaurándolo si estaba eliminado);
  crea el contrato en "Pendiente de contrato" con monto, institución y contacto; mueve la
  oportunidad a Adjudicado; marca la licitación; cancela los recordatorios de la oferta.
  Repetir la acción no duplica nada.
- **Perdida**: modal que exige **quién ganó** y **el valor de su oferta** (se elige de los
  participantes o se escribe), más motivo opcional. Mueve la oportunidad a No Adjudicado y
  registra al ganador como participante.
- **Revertir resultado**: decisión 5.
- **Participantes** (un solo componente, usado aquí y en el kanban): carga los existentes;
  agregar, editar y quitar filas; nombre, RTN, monto, moneda, si incluye ISV, observaciones;
  una fila es "nuestra oferta" y, conocido el resultado, una es "ganador". Guarda por
  diferencias, no borrando todo. Permitido también en estado Presentada. Muestra la posición
  de nuestra oferta y la diferencia contra la más baja.
- Numeración de cotizaciones única **por empresa**. RTN de cliente único por empresa.

### Fase 8 — Kanban

- Columnas: Prospecto | Oportunidad | Oferta Presentada | Adjudicado | No Adjudicado.
- Transiciones permitidas: avanzar de una en una; retroceder una etapa; desde Oferta Presentada
  a Adjudicado o a No Adjudicado. Salir de Adjudicado o No Adjudicado solo con "Revertir resultado".
- Arrastrar a **Oferta Presentada** abre la modal de participantes; exige al menos nuestra oferta con su monto.
- Arrastrar a **Adjudicado** abre la misma modal de Ganada; a **No Adjudicado**, la de Perdida.
  Ambas pasan por `AdjudicationService`, así que kanban y cotización siempre quedan iguales.
  Una oportunidad sin cotización también puede cerrarse: se pide el monto y, si se gana, se crea el cliente igual.
- Los participantes se vinculan a la oportunidad (`opportunity_id`) además de a la cotización,
  de modo que el botón "Participantes" aparece en la tarjeta desde Oferta Presentada.
- **Tarjeta**: institución, monto, fecha clave y días restantes. En No Adjudicado: pestaña
  "Quién ganó" con ganador, valor de su oferta, diferencia contra la nuestra y motivo.
- **Detalle de oportunidad** (modal con pestañas): Datos, Participantes, Resultado, Documentos, Historial.
- El tablero carga todas las oportunidades activas (sin cortar a 30) con búsqueda y filtros.
- La herramienta MCP de cambio de etapa sigue las mismas reglas.

### Fase 9 — Base de clientes

- Sin botón de alta. Texto: "Los clientes se registran al ganar una oferta".
- `Clients.vue` alineada con el servidor; edición de datos de contacto; ficha con sus
  cotizaciones ganadas, contratos y monto acumulado. Exportación CSV.

### Fase 10 — Post-adjudicación

- `Contracts.vue` se rehace: lista con filtros por estado y ficha con pestañas.
- **Datos del contrato**: número, institución, cliente, cotización de origen, **monto
  adjudicado**, fecha de firma, **plazo** (días hábiles o calendario, y desde qué hito corre),
  inicio y fin, **persona responsable del contacto** (contacto de la institución), **oficial
  que da seguimiento** (usuario de la empresa), **estado**.
- **Estados**: Pendiente de contrato, En ejecución, Entrega parcial, Producto entregado,
  Cerrado, Suspendido, Cancelado. Transiciones con reglas; cerrar exige producto entregado.
- **Documentos**: subir varios PDF por contrato, clasificados (Contrato, Adenda, Orden de
  compra, Acta de recepción, Factura, Otro), con las mismas acciones de ver, firmar, descargar y eliminar.
- **Entregas**: descripción, cantidad, fecha comprometida, fecha real, estado y evidencia.
- **Seguimientos**: línea de tiempo con tipo, nota, fecha y próxima acción con su fecha.
- **Calendario y recordatorios**: inicio, fin, cada entrega y cada próxima acción generan
  eventos; los avisos van al oficial y a los administradores.
- Días hábiles con feriados de Honduras, en una tabla editable por un administrador.
- Fecha fin calculada a partir del plazo, con opción de fijarla a mano sin que se pise.
- Número de contrato único por empresa. Se retiran las columnas duplicadas `starts_at`/`ends_at`.

### Fase 11 — Inventario Blessing

- Cliente de API (`BlessingClient`) detrás de una interfaz, con URL, token y lista de precios
  por empresa. El token se guarda cifrado; editar la empresa con el campo vacío no lo borra.
- Sincronización: botón "Sincronizar ahora" y tarea programada. Inserta o actualiza por
  (empresa, id de Blessing), marca como inactivo lo que ya no viene, y registra cada corrida
  (inicio, fin, altas, cambios, errores) en `InventorySyncRun`. SKU único por empresa.
- `Inventory.vue`: búsqueda, filtros por categoría y existencia, fecha de última
  sincronización y, por producto: **precio sugerido**, campo **cantidad**, subtotal, **ISV** y total.
  Botón para llevar los productos elegidos a una cotización.
- Un único calculador de precios para inventario, cotizaciones, calculadora de oferta y MCP,
  de modo que el ISV se calcule igual en todas partes.
- Si la API no responde: se conserva el último inventario con un aviso de antigüedad.
- **Bloqueado** hasta tener los datos de la API y la regla de ISV (sección 10). Mientras tanto
  se construye todo contra la interfaz, con un cliente simulado solo en pruebas.

### Fase 12 — MCP

- Ya resuelto en Fase 0: limitador `mcp` y configuración de Passport.
- **Conexiones**: al aprobar el acceso OAuth se crea la `McpConnection`. Pantalla de
  consentimiento en español que muestra quién pide acceso y permite elegir empresas y nivel
  (solo lectura, o lectura y escritura). Página "Conexiones MCP" para ver y revocar.
- Scopes `mcp:read` y `mcp:write`, verificados en cada herramienta.
- Toda herramienta aplica la misma policy que la pantalla web y valida que los ids pertenezcan
  a la empresa. Las escrituras llaman a los mismos servicios que la web.
- Confirmación en dos pasos para acciones sensibles (decisión 8). Firmar por MCP usa solo la
  firma del usuario dueño de la conexión.
- Herramientas marcadas como de solo lectura, destructivas o idempotentes según corresponda.
- Texto de terceros (pliegos, OCR, notas, nombres de archivo) se devuelve delimitado como dato.
- Subida por MCP limitada a 10 MB por archivo, con errores controlados.
- Auditoría con canal `mcp` y la conexión usada.
- `http://localhost` permitido como redirección solo fuera de producción. Tokens: 1 día de acceso, 30 de refresco.

**Herramientas nuevas**

| Área | Herramientas |
|---|---|
| Licitaciones | crear, actualizar, importar captura (devuelve borrador para revisar), subir pliego, consultar análisis, confirmar análisis |
| Calendario | crear evento, actualizar, marcar atendido, listar recordatorios |
| Cotizaciones | cambiar estado, listar adjuntos, eliminar adjunto |
| Pipeline | crear oportunidad, detalle con participantes |
| Contratos | detalle, actualizar, subir documento, registrar entrega, registrar seguimiento, cambiar estado, cerrar |
| Documentos | directorio de la empresa, detalle, subir, renovar, documentos por vencer |
| Inventario | sincronizar, estado de la última sincronización |
| Catálogos | instituciones, contactos |

Las herramientas existentes se corrigen para cumplir lo anterior.

### Fase 13 — Auditoría, pruebas y cierre

- Auditoría de: inicio y cierre de sesión, cambios de rol y membresías, subida, descarga, firma
  y eliminación de documentos, cambios de estado y resultado, contratos, eventos, conexiones
  MCP. Pantalla de consulta para administradores con filtros.
- Factories para todos los modelos del flujo. Pruebas de feature por fase y una prueba de
  recorrido completo: captura → pliego → calendario → cotización → firma → Ganada → cliente → contrato → cierre.
- Archivos de referencia en `tests/fixtures`: la captura de ejemplo y el texto extraído del pliego LPN-007.
- Verificación manual en navegador de cada pantalla y modal, con evidencias.
- Manual de usuario del flujo (guía `cap-manual`).
- Lista de despliegue: Tesseract con `spa`, Poppler, límites de PHP, worker de cola, scheduler,
  zona horaria, claves de OpenAI y Resend, llaves de Passport.

## 8. Modales nuevas (inventario)

| Pantalla | Modales |
|---|---|
| Licitaciones | Importar captura · Revisión de captura · Subir pliego · Revisión del análisis · Ficha de licitación · Confirmar eliminación |
| Calendario | Detalle de evento · Crear o editar evento · Reglas de recordatorio |
| Cotizaciones | Crear o editar · Ver (visor y adjuntos) · Agregar PDF · Firmar y sellar · Participantes · Ganada · Perdida · Revertir resultado · Enviar por correo · Confirmar eliminación |
| Kanban | Detalle de oportunidad con pestañas · Participantes · Ganada · Perdida |
| Directorio Institucional | Subir · Ver · Reemplazar · Renovar · Historial de versiones · Confirmar eliminación |
| Mi firma y sello | Subir firma · Subir sello · Firmar un documento |
| Post-adjudicación | Crear o editar contrato · Subir documento · Entrega · Seguimiento · Cambiar estado · Cerrar contrato |
| Inventario | Detalle de producto · Resultado de sincronización |
| Cabecera | Campana de recordatorios · Selector de empresa |
| Administración | Conexiones MCP · Feriados · Diagnóstico del sistema |

## 9. Orden de entrega

El trabajo es grande; se planifica y entrega en cinco bloques, cada uno con su plan detallado:

1. **Cimientos**: Fases 0, 1 y 2.
2. **Documentos y firma**: Fases 3 y 4.
3. **Licitaciones y calendario**: Fases 5 y 6.
4. **Ciclo comercial**: Fases 7, 8, 9 y 10.
5. **Integraciones y cierre**: Fases 11, 12 y 13.

## 10. Pendiente de respuesta del usuario

1. **ISV de Blessing.** El pedido dice "precio sugerido × cantidad + ISV"; el código actual
   asume que el precio ya incluye el impuesto. Hay que saber si el precio sugerido de Blessing
   viene sin ISV (y se suma el 15 %) o con ISV incluido. Hasta tener respuesta se asume lo que
   dice el pedido: precio sin ISV, se suma el 15 %.
2. **API de Blessing.** URL, forma de autenticación y documentación o ejemplo de respuesta del
   listado de productos (campos de SKU, descripción, existencia, precio sugerido, impuesto).
3. **Servidor de producción.** El diseño necesita instalar Tesseract y Poppler y mantener un
   worker de cola y un scheduler. Eso es posible en un servidor propio o VPS, pero no en un
   hosting compartido ni en plataformas gestionadas sin binarios del sistema. Hasta tener
   respuesta se asume un servidor propio (Linux o Windows) con acceso para instalar programas.
   Si no es así, el OCR, la lectura del pliego y la normalización de PDF para firmar pasan a un
   servicio externo y cambian las Fases 0, 4 y 5.

## 11. Lo que queda a criterio del constructor

- Nombres de clases, rutas internas y estructura de archivos, siguiendo las convenciones del proyecto.
- Implementación de la rejilla del calendario y del visor con arrastre (componente propio o librería).
- Tamaño de los tramos de texto que se envían a la IA y el modelo concreto de OpenAI.
- Algoritmo de emparejamiento aproximado de etiquetas del OCR y de nombres de institución.
- Diseño exacto de índices y migraciones, siguiendo `cap-database`.
