# Levanta N procesos `php -S` (OPcache activo; JIT opcional con -Jit) sobre el directorio `public` de una copia
# de la app compilada para producciÃ³n, mÃ¡s el balanceador tests/perf/lb.mjs delante.
# No toca el entorno de desarrollo (artisan serve :8000 / Vite :5173).
#
#   .\tests\perf\start-servers.ps1 -AppPath C:\ruta\worktree -Workers 8 -BasePort 8090 -LbPort 8089 [-Compress] [-StaticCache] [-NoOpcache]
#   .\tests\perf\start-servers.ps1 -Stop
param(
    [string]$AppPath = (Resolve-Path "$PSScriptRoot\..\.."),
    [int]$Workers = 8,
    [int]$BasePort = 8090,
    [int]$LbPort = 8089,
    [switch]$Compress,
    [switch]$StaticCache,
    [switch]$NoOpcache,
    # JIT 'tracing' tumba php -S en PHP 8.5.6 NTS Windows (0xC0000005 en la 2.a petición): por defecto desactivado.
    [string]$Jit = 'disable',
    [switch]$Stop
)

$pidFile = Join-Path $env:TEMP 'crm-b2g-perf-pids.txt'

if ($Stop) {
    if (Test-Path $pidFile) {
        Get-Content $pidFile | ForEach-Object { Stop-Process -Id ([int]$_) -Force -ErrorAction SilentlyContinue }
        Remove-Item $pidFile
    }
    Write-Host 'Servidores de mediciÃ³n detenidos.'
    return
}

if (-not $env:PHP_INI_SCAN_DIR) { $env:PHP_INI_SCAN_DIR = "$env:USERPROFILE\.php\conf.d" }

$router = Join-Path $AppPath 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$public = Join-Path $AppPath 'public'
$opcache = if ($NoOpcache) { @('-d', 'opcache.enable_cli=0') } else {
    @('-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=256', '-d', 'opcache.interned_strings_buffer=32',
      '-d', 'opcache.max_accelerated_files=40000', '-d', 'opcache.validate_timestamps=0',
      '-d', "opcache.jit=$Jit", '-d', 'opcache.jit_buffer_size=64M', '-d', 'realpath_cache_size=4096K', '-d', 'realpath_cache_ttl=600')
}

$pids = @()
for ($i = 0; $i -lt $Workers; $i++) {
    $port = $BasePort + $i
    $argsList = $opcache + @('-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", '-t', $public, $router)
    $p = Start-Process -FilePath 'php' -ArgumentList $argsList -WorkingDirectory $public -WindowStyle Hidden -PassThru `
        -RedirectStandardError (Join-Path $env:TEMP "crm-b2g-perf-php-$port.err") -RedirectStandardOutput (Join-Path $env:TEMP "crm-b2g-perf-php-$port.out")
    $pids += $p.Id
}

$lbArgs = @("$PSScriptRoot\lb.mjs", "--port=$LbPort", "--backends=$BasePort-$($BasePort + $Workers - 1)")
if ($Compress) { $lbArgs += '--compress' }
if ($StaticCache) { $lbArgs += '--static-cache' }
$lb = Start-Process -FilePath 'node' -ArgumentList $lbArgs -WindowStyle Hidden -PassThru `
    -RedirectStandardOutput (Join-Path $env:TEMP 'crm-b2g-perf-lb.out') -RedirectStandardError (Join-Path $env:TEMP 'crm-b2g-perf-lb.err')
$pids += $lb.Id

$pids | Set-Content $pidFile
Write-Host "PHP en $BasePort..$($BasePort + $Workers - 1), balanceador en http://127.0.0.1:$LbPort (PIDs en $pidFile)"
