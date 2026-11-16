# Medición de rendimiento (k6, Lighthouse, Playwright)

Scripts para medir carga, tiempos y patrón de peticiones del CRM. Ninguno modifica datos salvo
`pipeline-modales.mjs` con `PERF_WRITE=1` (dos guardados reversibles; ver el script).
Los resultados van a `storage/app/qa/perf/` (no versionado).

## 1. Entorno de medición (no usar `artisan serve` de desarrollo)

Medir contra `php artisan serve` (un proceso, sin OPcache, Vite en caliente) no es representativo.
Monta una copia aislada parecida a producción:

```powershell
git worktree add --detach $env:TEMP\crm-perf HEAD
# copia encima los cambios sin confirmar si quieres medir el estado actual
robocopy vendor $env:TEMP\crm-perf\vendor /E /MT:16      # copia real: con un enlace el autoload apunta al repo original
cmd /c mklink /J $env:TEMP\crm-perf\node_modules node_modules
# .env de la copia: APP_ENV=production, APP_DEBUG=false, MAIL_MAILER=log, sin claves de terceros
cd $env:TEMP\crm-perf
composer dump-autoload -o --no-dev --no-scripts   # y extra.laravel.dont-discover con laravel/pail, laravel/pao, nunomaduro/collision
npx vite build                                     # con resolve.preserveSymlinks si node_modules es un enlace (fuentes MDI)
php artisan optimize
```

Servidores (en Windows `php -S` no admite `PHP_CLI_SERVER_WORKERS`): N procesos `php -S` con OPcache
y un balanceador Node con cola común (como FPM) y, opcionalmente, compresión y caché de `/build/assets`:

```powershell
.\tests\perf\start-servers.ps1 -AppPath $env:TEMP\crm-perf -Workers 8 -Compress -StaticCache   # :8089
.\tests\perf\start-servers.ps1 -Stop
```

JIT: `opcache.jit=tracing` tumba `php -S` en PHP 8.5.6 NTS de Windows (0xC0000005), por eso va desactivado.

Para el perfil por petición (consultas, tiempo SQL, memoria) la copia registra un proveedor temporal que
añade cabeceras `X-Perf-Queries`, `X-Perf-Sql-Ms`, `X-Perf-Ms`, `X-Perf-Mem-Mb` (y vuelca SQL con
`X-Perf-Log: 1`). No forma parte de la app: se crea solo en la copia.

## 2. Scripts

| Script | Qué hace |
| --- | --- |
| `screens.mjs` | Coste de servidor por pantalla (consultas, ms, MB, KB, props más pesadas) y de las llamadas JSON de las modales. `PERF_REPEAT`, `PERF_LOG=1`. |
| `crawl.mjs` | Recorre todas las pantallas con Playwright como un usuario (navegación Inertia), registra cada petición, duplicados, caché/compresión de estáticos y sondeos en reposo (`PERF_IDLE` s). |
| `pipeline-modales.mjs` | Peticiones y tiempo hasta ver contenido al abrir detalle, pestañas, Participantes, Ganada, Perdida, Editar. `PERF_WRITE=1` mide la cascada al guardar participantes. |
| `reportes.mjs`, `reportes-navegacion.mjs` | Reproducen la carga de `/reportes` (períodos, módulo, rango inválido, exportes) y la navegación desde el menú. |
| `lighthouse.mjs` | Lighthouse escritorio y móvil (acceso, Dashboard, Licitaciones, Pipeline, Cotizaciones, Calendario, Directorio) con la cookie de sesión. `PERF_LH_PAGES`, `PERF_LH_FORMS`. |
| `k6/crm.js` | Carga y estrés con k6: `-e SCENARIO=humo|rampa|pico`. Umbrales p95 < 500 ms y errores < 1 %. |
| `monitor.ps1` | CPU y memoria de PHP, MySQL y balanceador durante k6 (CSV). |
| `lb.mjs`, `start-servers.ps1`, `http.mjs`, `lib.mjs` | Infraestructura de medición. |

Todos aceptan `PERF_URL` (por defecto `http://127.0.0.1:8089`) y usan `qa.admin@crm-b2g.test`
(`PERF_EMAIL`, `PERF_PASSWORD`).

```powershell
$env:PERF_URL='http://127.0.0.1:8089'
node tests/perf/screens.mjs
node tests/perf/crawl.mjs
node tests/perf/pipeline-modales.mjs
node tests/perf/lighthouse.mjs

# k6 + monitor en paralelo
Start-Job { Set-Location $using:PWD; .\tests\perf\monitor.ps1 -Seconds 480 -Out storage\app\qa\perf\monitor-rampa.csv }
& 'C:\Program Files\k6\k6.exe' run -e BASE_URL=http://127.0.0.1:8089 -e SCENARIO=rampa tests/perf/k6/crm.js
```

k6 crea una sesión por usuario virtual: `DELETE FROM sessions WHERE user_agent LIKE '%k6%'` al terminar.
`POST /login` tiene limitador por IP (`throttle:20,1`, ahora `throttle:login`): con más de 20 usuarios virtuales la copia de medición debe
relajarlo (solo ahí).
