<?php

/*
|--------------------------------------------------------------------------
| Lanzador de pruebas
|--------------------------------------------------------------------------
|
| La suite usa sqlite en memoria. Si el PHP de la máquina no carga
| `pdo_sqlite` o `sodium` (caso habitual en Windows), se vuelve a lanzar PHP
| añadiendo `tools/php` a los directorios de configuración, sin tocar el
| php.ini del equipo. En servidores donde ya están cargadas no cambia nada.
|
| Uso: composer test            (suite completa)
|      composer test -- --filter=NombreDePrueba
|
*/

$root = dirname(__DIR__);
$missing = array_filter(['pdo_sqlite', 'sodium'], fn (string $extension) => ! extension_loaded($extension));

if ($missing !== [] && getenv('CRM_TEST_RELAUNCHED') !== '1') {
    // El separador inicial conserva el directorio de configuración por defecto.
    putenv('PHP_INI_SCAN_DIR='.PATH_SEPARATOR.$root.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'php');
    putenv('CRM_TEST_RELAUNCHED=1');
} elseif ($missing !== []) {
    fwrite(STDERR, 'Faltan extensiones de PHP para las pruebas: '.implode(', ', $missing).PHP_EOL);
    exit(1);
}

$arguments = array_map('escapeshellarg', array_slice($argv, 1));
$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.DIRECTORY_SEPARATOR.'artisan').' test '.implode(' ', $arguments);

passthru($command, $exitCode);

exit($exitCode);
