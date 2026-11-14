# CRM B2G

CRM para licitaciones públicas hondureñas: licitaciones, calendario y recordatorios,
cotizaciones, firma y sello, kanban de oportunidades, clientes, post-adjudicación,
inventario Blessing e integración MCP con ChatGPT y Claude. Laravel 13, Inertia 3, Vue 3 y MySQL.

Reglas del proyecto: `MIGRATION_RULES.md`, `AGENTS.md` y `database/DATABASE_GUIDE.md`.
Avance por fase: `MIGRATION_PROGRESS.md`. Diseño vigente: `docs/superpowers/specs/`.
Biblioteca de interfaz: `resources/js/Components/README.md`.

## Requisitos del servidor

| Requisito | Detalle |
|---|---|
| PHP 8.3 o superior | Extensiones `pdo_mysql`, `mbstring`, `openssl`, `sodium` (OAuth de Passport), `gd` (firma y sello), `fileinfo`, `zip` y `xml`/`dom` (Excel y PDF), `curl`. Para las pruebas, además `pdo_sqlite`. OPcache activo. |
| MySQL 8 | Base `crm_b2g` en `utf8mb4`. |
| Node 20 o superior | Solo para compilar la interfaz (`npm run build`). |
| Tesseract 5 con el idioma `spa` | Lee las capturas de HonduCompras y las páginas escaneadas de los pliegos. En Debian/Ubuntu: `apt install tesseract-ocr tesseract-ocr-spa`. |
| Poppler | `pdftotext`, `pdftoppm`, `pdftocairo`, `pdfunite` y `pdfinfo`: texto de los pliegos, páginas a imagen y normalización de PDF antes de firmar. En Debian/Ubuntu: `apt install poppler-utils`. |
| Cola y scheduler | Dos procesos permanentes además del servidor web (ver más abajo). |

Tesseract y Poppler se buscan en el `PATH`. Si no están ahí, indique su ubicación en `.env`
con `TESSERACT_PATH` (ejecutable) y `POPPLER_PATH` (carpeta de los binarios).

Límites de PHP (`php.ini`) para subir pliegos de hasta 40 MB y procesarlos:

```ini
upload_max_filesize = 40M
post_max_size = 48M
memory_limit = 512M
opcache.enable = 1
```

El servidor web debe permitir el mismo tamaño de cuerpo (en Nginx, `client_max_body_size 48m;`).

La pantalla **Configuración → Diagnóstico** (`/configuracion/diagnostico`, raíz o administrador
de empresa) comprueba todo lo anterior, además de la cola, el scheduler, la clave de OpenAI y
el correo.

## Variables de entorno

`.env.example` contiene solo lo necesario; el resto tiene valor por defecto en `config/`. El
idioma (`es`) y la zona horaria (`America/Tegucigalpa`) son fijos en `config/app.php`.

| Variable | Para qué |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Aplicación. En producción: `APP_ENV=production`, `APP_DEBUG=false` y `APP_URL=https://<dominio>` (lo usan los enlaces de correo y el MCP). |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` las tres: la caché debe ser compartida entre procesos (el latido del scheduler vive ahí). |
| `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `RESEND_API_KEY` | Correo. `log` en desarrollo; `resend` y su clave en producción. |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | Análisis de pliegos y agente legal. Sin clave, la pantalla avisa y se trabaja a mano. |
| `TESSERACT_PATH`, `POPPLER_PATH` | Opcionales: solo si los binarios no están en el `PATH`. |
| `MCP_REDIRECT_DOMAINS` | Opcional: dominios de redirección OAuth permitidos (por defecto ChatGPT y Claude). |

## Instalación

```bash
composer install
cp .env.example .env        # y complete base de datos, correo y claves
php artisan key:generate
php artisan migrate
php artisan passport:keys   # llaves OAuth del servidor MCP (storage/oauth-*.key)
npm install
npm run build
```

Primer usuario (y raíz del grupo):

```bash
php artisan admin:create-user persona@empresa.hn --name="Nombre Apellido" --company="CAP Honduras" --role=admin
php artisan crm:root persona@empresa.hn
```

## Desarrollo

```bash
composer dev
```

Levanta en paralelo el servidor (`php artisan serve`), el worker de la cola, el scheduler
(`schedule:work`) y Vite. Sin el scheduler no se generan ni se envían recordatorios; sin el
worker no se analizan pliegos ni salen correos.

Datos de prueba, solo por consola (no hay sembrado desde la web):

```bash
php artisan db:seed                    # empresa e instituciones; en local, además usuario y datos demo
php artisan db:seed --class=QaSeeder   # dos empresas y un usuario por rol (nunca en producción)
```

`QaSeeder` es idempotente y crea, con la contraseña `QaB2g#2026`: `qa.root@crm-b2g.test` (raíz),
`qa.admin@crm-b2g.test`, `qa.operador@crm-b2g.test` y `qa.lector@crm-b2g.test` (admin, operador y
solo lectura de CAP Honduras) y `qa.adminb@crm-b2g.test` (admin de Empresa Demo B). Cada empresa
queda con licitaciones, una oportunidad por etapa, cotizaciones, participantes y un contrato.
`DatabaseSeeder` solo crea `admin@crm-b2g.test` / `password` cuando `APP_ENV=local`.

## Pruebas

```bash
composer test
composer test -- --filter=CompanyIsolationTest
```

La suite usa sqlite en memoria. `composer test` ejecuta `tools/run-tests.php`, que carga
`pdo_sqlite` y `sodium` desde `tools/php` cuando el PHP de la máquina no los trae activados
(caso habitual en Windows); no hay que tocar el `php.ini` ni definir variables a mano.

Revisión visual con Playwright (servidor en `http://localhost:8000`): `node tests/visual/<modulo>.mjs`;
las capturas quedan en `storage/app/qa/capturas/<modulo>/`.

## Producción

### Despliegue

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize          # caché de configuración, rutas, eventos y vistas
php artisan queue:restart     # el worker toma el código nuevo
php artisan up
```

`php artisan optimize` equivale a `config:cache`, `route:cache`, `event:cache` y `view:cache`.
Con la configuración en caché, `env()` solo se lee dentro de `config/`: tras cambiar `.env`
vuelva a ejecutar `php artisan optimize` (o `php artisan optimize:clear` para volver atrás).
No la use en desarrollo.

### Cola y scheduler

Los recordatorios, el análisis de pliegos, la sincronización de Blessing y los correos corren en
segundo plano. Hacen falta dos procesos permanentes además del servidor web.

**Worker de la cola** (Supervisor, `/etc/supervisor/conf.d/crm-b2g-worker.conf`):

```ini
[program:crm-b2g-worker]
command=php /var/www/crm-b2g/artisan queue:work database --sleep=3 --tries=3 --timeout=600 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=630
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/crm-b2g/storage/logs/worker.log
```

**Scheduler** (cron del usuario del servidor web):

```cron
* * * * * cd /var/www/crm-b2g && php artisan schedule:run >> /dev/null 2>&1
```

En Windows Server, los equivalentes son un servicio (NSSM) con `php artisan queue:work` y una
tarea programada cada minuto con `php artisan schedule:run`.

Si alguno de los dos se detiene, el diagnóstico lo muestra: «Tareas programadas» pasa a error
cuando el scheduler lleva más de 5 minutos sin correr, y «Cola de trabajos» avisa de trabajos
fallidos o acumulados.

## Conectar ChatGPT y Claude (MCP)

El CRM expone un servidor MCP remoto en:

```
https://<dominio>/mcp/crm
```

Requisitos: `APP_URL` con el dominio público en HTTPS, `php artisan passport:keys` ejecutado y,
si el cliente redirige desde otro dominio, añadirlo a `MCP_REDIRECT_DOMAINS`.

- **Claude** (claude.ai o Claude Desktop): Configuración → Conectores → *Añadir conector
  personalizado* → pegue la URL `https://<dominio>/mcp/crm`.
- **ChatGPT**: Configuración → Conectores (modo desarrollador) → *Crear* → URL
  `https://<dominio>/mcp/crm`, autenticación OAuth.

El cliente se registra solo (registro dinámico OAuth) y abre la pantalla de consentimiento del
CRM: la persona inicia sesión, elige las empresas y el nivel (solo lectura o lectura y escritura)
y aprueba. Desde ahí, el permiso efectivo es el de la conexión más su rol en cada empresa. Cada
usuario ve y revoca sus conexiones en **Conexiones MCP** (`/configuracion/mcp`); las acciones
que cambian datos piden confirmación en dos pasos y quedan en la auditoría con canal `mcp`.

## Multiempresa y permisos

- Cada registro pertenece a una empresa del grupo. Las pantallas muestran la **empresa activa**,
  que se elige en la cabecera (`POST /empresa-activa`).
- **Raíz** (`users.is_root`, solo por `php artisan crm:root`) opera en todas las empresas y es la
  única que las crea o elimina.
- El **rol por empresa** (`company_user.role`) decide dentro de cada una: `admin`, `operator`
  (escribe, no elimina ni administra) y `viewer` (solo lectura). La matriz está en
  `app/Support/Permissions.php`.

## Estructura para módulos

- Rutas: un archivo por módulo en `routes/modules/`, cargado automáticamente dentro de `auth`.
- Modelos con `company_id`: trait `App\Models\Concerns\BelongsToCompany`.
- Validación de ids: `App\Rules\AllowedCompany` y `App\Rules\BelongsToActiveCompany`.
- Políticas: se registran en `AppServiceProvider::$policies`.
- Servicios de dominio compartidos por la web y el MCP: `AdjudicationService` (ganar, perder,
  revertir), `OpportunityStageService`, `ContractService`, `CalendarEventService`, `QuoteService`.
