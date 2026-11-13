# CRM B2G

CRM para licitaciones públicas hondureñas: licitaciones, calendario y recordatorios,
cotizaciones, firma y sello, kanban de oportunidades, clientes, post-adjudicación e
integración MCP. Laravel 13, Inertia 3, Vue 3 y MySQL.

Reglas del proyecto: `MIGRATION_RULES.md`, `AGENTS.md` y `database/DATABASE_GUIDE.md`.
Avance: `MIGRATION_PROGRESS.md`. Diseño vigente: `docs/superpowers/specs/`.

## Requisitos del servidor

| Requisito | Detalle |
|---|---|
| PHP 8.3 o superior | Extensiones `pdo_mysql`, `sodium`, `gd`, `mbstring`, `fileinfo`, `zip`. Para las pruebas, además `pdo_sqlite`. |
| MySQL 8 | Base `crm_b2g` en `utf8mb4`. |
| Node 20 o superior | Solo para compilar la interfaz. |
| Tesseract 5 con idioma `spa` | Lee las capturas de HonduCompras y las páginas escaneadas de los pliegos. |
| Poppler | `pdftotext`, `pdftoppm` y `pdftocairo`: texto de los pliegos y normalización de PDF para firmar. |

Tesseract y Poppler se buscan en el `PATH`. Si no están ahí, indique su ubicación en `.env`
con `TESSERACT_PATH` (ejecutable) y `POPPLER_PATH` (carpeta de los binarios).

Límites de PHP (`php.ini`) para subir pliegos de hasta 40 MB:

```ini
upload_max_filesize = 40M
post_max_size = 48M
memory_limit = 256M
```

El servidor web debe permitir el mismo tamaño de cuerpo (en Nginx, `client_max_body_size 48m;`).

La pantalla **Configuración → Diagnóstico** (`/configuracion/diagnostico`, raíz o administrador
de empresa) comprueba todo lo anterior, además de la cola, el scheduler, la clave de OpenAI y
el correo.

## Instalación

```bash
composer install
cp .env.example .env        # y complete base de datos, correo y claves
php artisan key:generate
php artisan migrate
php artisan passport:keys   # llaves OAuth del servidor MCP
npm install
npm run build
```

`.env.example` contiene solo las variables necesarias. El idioma (`es`) y la zona horaria
(`America/Tegucigalpa`) son fijos en `config/app.php`.

Primer usuario raíz:

```bash
php artisan admin:create-user persona@empresa.hn --name="Nombre Apellido" --company="CAP Honduras" --role=admin
php artisan crm:root persona@empresa.hn
```

## Desarrollo

```bash
composer dev
```

Levanta en paralelo el servidor (`php artisan serve`), el worker de la cola, el scheduler
(`schedule:work`) y Vite. Sin el scheduler no se generan ni se envían recordatorios.

Datos de prueba, solo por consola (no hay sembrado desde la web):

```bash
php artisan db:seed                    # datos mínimos de demostración
php artisan db:seed --class=QaSeeder   # dos empresas y un usuario por rol (solo local)
```

## Pruebas

```bash
composer test
composer test -- --filter=CompanyIsolationTest
```

La suite usa sqlite en memoria. `composer test` ejecuta `tools/run-tests.php`, que carga
`pdo_sqlite` y `sodium` desde `tools/php` cuando el PHP de la máquina no los trae activados
(caso habitual en Windows); no hay que tocar el `php.ini` ni definir variables a mano.

## Producción: cola y scheduler

Los recordatorios, el análisis de pliegos y los correos corren en segundo plano. Hacen falta
dos procesos permanentes además del servidor web.

**Worker de la cola** (Supervisor, `/etc/supervisor/conf.d/crm-b2g-worker.conf`):

```ini
[program:crm-b2g-worker]
command=php /var/www/crm-b2g/artisan queue:work database --sleep=3 --tries=3 --timeout=300 --max-time=3600
autostart=true
autorestart=true
stopwaitsecs=330
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/crm-b2g/storage/logs/worker.log
```

Tras cada despliegue: `php artisan queue:restart`.

**Scheduler** (cron del usuario del servidor web):

```cron
* * * * * cd /var/www/crm-b2g && php artisan schedule:run >> /dev/null 2>&1
```

En Windows Server, los equivalentes son un servicio (NSSM) con `php artisan queue:work` y una
tarea programada cada minuto con `php artisan schedule:run`.

Si alguno de los dos se detiene, el diagnóstico lo muestra: «Tareas programadas» pasa a error
cuando el scheduler lleva más de 5 minutos sin correr, y «Cola de trabajos» avisa de trabajos
fallidos o acumulados. El latido del scheduler se guarda en la caché, que debe ser compartida
entre procesos (`CACHE_STORE=database`, no `array`).

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
