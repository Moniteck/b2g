<?php

namespace App\Services\System;

use App\Support\Binaries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Comprueba que el servidor tiene lo que el CRM necesita para funcionar:
 * binarios de OCR y PDF, límites de PHP, cola, scheduler, IA y correo.
 *
 * Cada comprobación devuelve:
 *   key     identificador estable
 *   label   nombre para mostrar
 *   status  ok | warning | error
 *   detail  explicación en español, con la acción a tomar si falla
 *   meta    datos sueltos (versión, valores, conteos)
 *
 * Reutilizable: `report()` para la pantalla, o cada método público por separado
 * (p. ej. `tesseract()['status'] === 'ok'` antes de ofrecer la importación por OCR).
 */
class SystemDiagnostics
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /** Clave de caché del latido que escribe el scheduler cada minuto. */
    public const SCHEDULER_HEARTBEAT = 'system.scheduler.heartbeat';

    /** Minutos sin latido a partir de los cuales el scheduler se da por detenido. */
    public const SCHEDULER_STALE_MINUTES = 5;

    /**
     * Lo registra el scheduler (ver routes/console.php).
     */
    public static function recordSchedulerHeartbeat(): void
    {
        Cache::forever(self::SCHEDULER_HEARTBEAT, now()->toIso8601String());
    }

    /**
     * @return array{checks: array<int, array<string, mixed>>, summary: array<string, int>, generated_at: string}
     */
    public function report(): array
    {
        $checks = [
            $this->tesseract(),
            $this->poppler(),
            $this->phpLimits(),
            $this->queue(),
            $this->scheduler(),
            $this->openAi(),
            $this->mailer(),
        ];

        $summary = [self::OK => 0, self::WARNING => 0, self::ERROR => 0];

        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }

        return ['checks' => $checks, 'summary' => $summary, 'generated_at' => now()->toIso8601String()];
    }

    public function tesseract(): array
    {
        $binary = Binaries::tesseract();
        $language = (string) config('tools.tesseract.language', 'spa');
        $version = $this->run([$binary, '--version']);

        if ($version === null) {
            return $this->check('tesseract', 'Tesseract (OCR)', self::ERROR,
                'No se encontró Tesseract. Instálelo o indique su ruta en TESSERACT_PATH. Sin él no se pueden leer capturas de HonduCompras.',
                ['path' => $binary, 'installed' => false, 'has_language' => false]);
        }

        $languages = preg_split('/\R/', trim((string) $this->run([$binary, '--list-langs']))) ?: [];
        $hasLanguage = in_array($language, array_map('trim', $languages), true);

        return $this->check('tesseract', 'Tesseract (OCR)', $hasLanguage ? self::OK : self::ERROR,
            $hasLanguage
                ? "Instalado con el idioma «{$language}»."
                : "Tesseract está instalado pero falta el idioma «{$language}». Instale el paquete de idioma español.",
            ['path' => $binary, 'installed' => true, 'has_language' => $hasLanguage, 'version' => $this->firstLine($version)]);
    }

    public function poppler(): array
    {
        $binaries = [];

        foreach ((array) config('tools.poppler.binaries', []) as $name) {
            $output = $this->run([Binaries::poppler($name), '-v']);
            $binaries[$name] = ['installed' => $output !== null, 'version' => $output !== null ? $this->firstLine($output) : null];
        }

        $missing = array_keys(array_filter($binaries, fn (array $binary) => ! $binary['installed']));

        return $this->check('poppler', 'Poppler (PDF)', $missing === [] ? self::OK : self::ERROR,
            $missing === []
                ? 'pdftotext, pdftoppm y pdftocairo están disponibles.'
                : 'Faltan: '.implode(', ', $missing).'. Instale Poppler o indique su carpeta en POPPLER_PATH. Sin ellos no se pueden leer pliegos ni normalizar PDF para firmar.',
            ['binaries' => $binaries]);
    }

    public function phpLimits(): array
    {
        $maxUpload = max((int) config('tools.uploads.max_pdf_mb'), (int) config('tools.uploads.max_image_mb'));

        $limits = [
            'upload_max_filesize' => $this->limit('upload_max_filesize', $maxUpload),
            'post_max_size' => $this->limit('post_max_size', $maxUpload + (int) config('tools.php.post_margin_mb', 8)),
            'memory_limit' => $this->limit('memory_limit', (int) config('tools.php.memory_limit_mb', 256)),
        ];

        $low = array_keys(array_filter($limits, fn (array $limit) => ! $limit['ok']));

        return $this->check('php_limits', 'Límites de PHP', $low === [] ? self::OK : self::ERROR,
            $low === []
                ? "Permiten subir archivos de hasta {$maxUpload} MB."
                : 'Valores insuficientes en php.ini: '.implode(', ', array_map(
                    fn (string $key) => "{$key} = {$limits[$key]['current']} (mínimo {$limits[$key]['required']})", $low,
                )).'.',
            ['limits' => $limits, 'ini_file' => php_ini_loaded_file() ?: null]);
    }

    public function queue(): array
    {
        $connection = (string) config('queue.default');
        $pending = $this->count((string) config("queue.connections.{$connection}.table", 'jobs'));
        $failed = $this->count((string) config('queue.failed.table', 'failed_jobs'));

        [$status, $detail] = match (true) {
            $connection === 'sync' => [self::WARNING, 'La cola está en modo «sync»: los trabajos se ejecutan dentro de la petición. Use «database» en producción.'],
            $failed > 0 => [self::WARNING, "Hay {$failed} trabajo(s) fallido(s). Revíselos con «php artisan queue:failed»."],
            $pending > 100 => [self::WARNING, "Hay {$pending} trabajos pendientes: compruebe que el worker «php artisan queue:work» está corriendo."],
            default => [self::OK, "Conexión «{$connection}», {$pending} pendiente(s), sin fallidos."],
        };

        return $this->check('queue', 'Cola de trabajos', $status, $detail,
            ['connection' => $connection, 'pending' => $pending, 'failed' => $failed]);
    }

    public function scheduler(): array
    {
        $heartbeat = Cache::get(self::SCHEDULER_HEARTBEAT);
        $lastRun = $heartbeat ? Carbon::parse($heartbeat) : null;

        if ($lastRun === null) {
            return $this->check('scheduler', 'Tareas programadas', self::ERROR,
                'El scheduler nunca ha corrido. Sin él no se envían recordatorios. Inicie «php artisan schedule:work» o configure el cron.',
                ['last_run_at' => null, 'minutes_ago' => null]);
        }

        $minutes = (int) $lastRun->diffInMinutes(now());
        $stale = $minutes > self::SCHEDULER_STALE_MINUTES;

        return $this->check('scheduler', 'Tareas programadas', $stale ? self::ERROR : self::OK,
            $stale
                ? "La última corrida fue hace {$minutes} minutos ({$lastRun->format('d/m/Y H:i')}). El scheduler está detenido."
                : "Última corrida: {$lastRun->format('d/m/Y H:i')}.",
            ['last_run_at' => $lastRun->toIso8601String(), 'minutes_ago' => $minutes]);
    }

    public function openAi(): array
    {
        $present = filled(config('services.openai.key'));

        return $this->check('openai', 'OpenAI', $present ? self::OK : self::WARNING,
            $present
                ? 'Clave configurada. Modelo: '.config('services.openai.model').'.'
                : 'Falta OPENAI_API_KEY: el análisis de pliegos y el agente legal no están disponibles; la carga manual sigue funcionando.',
            ['key_present' => $present, 'model' => config('services.openai.model')]);
    }

    public function mailer(): array
    {
        $mailer = (string) config('mail.default');
        $delivers = ! in_array($mailer, ['log', 'array'], true);
        $missingKey = $mailer === 'resend' && blank(config('services.resend.key'));

        [$status, $detail] = match (true) {
            $missingKey => [self::ERROR, 'El correo usa Resend pero falta RESEND_API_KEY.'],
            ! $delivers => [self::WARNING, "El correo está en modo «{$mailer}»: los mensajes no se entregan a nadie."],
            default => [self::OK, "Correo saliente por «{$mailer}» desde ".config('mail.from.address').'.'],
        };

        return $this->check('mailer', 'Correo saliente', $status, $detail,
            ['mailer' => $mailer, 'from' => config('mail.from.address')]);
    }

    /**
     * Convierte un valor de php.ini («40M», «1G», «-1») a megabytes. -1 = sin límite.
     */
    public static function megabytes(string $value): float
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (float) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024,
            'M' => $number,
            'K' => $number / 1024,
            default => $number / 1048576,
        };
    }

    /**
     * @return array{current: string, required: string, ok: bool}
     */
    protected function limit(string $directive, int $requiredMb): array
    {
        $current = (string) ini_get($directive);
        $megabytes = self::megabytes($current);

        return [
            'current' => $current,
            'required' => "{$requiredMb}M",
            'ok' => $megabytes < 0 || $megabytes >= $requiredMb,
        ];
    }

    /**
     * Ejecuta un binario y devuelve su salida, o null si no existe o falla.
     *
     * @param  array<int, string>  $command
     */
    protected function run(array $command): ?string
    {
        try {
            $result = Process::timeout(10)->run($command);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        // Poppler escribe la versión en la salida de error.
        return trim($result->output()) !== '' ? $result->output() : $result->errorOutput();
    }

    protected function count(string $table): int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    protected function firstLine(string $output): string
    {
        return trim(strtok(trim($output), "\r\n") ?: '');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function check(string $key, string $label, string $status, string $detail, array $meta = []): array
    {
        return compact('key', 'label', 'status', 'detail', 'meta');
    }
}
