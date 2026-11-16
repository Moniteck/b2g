<?php

namespace App\Services\Blessing;

use App\Rules\SafeExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cliente HTTP de la API de Blessing, por empresa (URL base, token y lista de precios).
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ PENDIENTE: la API real todavía no está documentada (diseño, sección 10.2). ║
 * ║ Lo que se supone y hay que confirmar:                                       ║
 * ║  - Autenticación: `Authorization: Bearer <token>`.                         ║
 * ║  - Listado: GET {base}/products?price_list=…&per_page=…&cursor=…           ║
 * ║  - Respuesta: { "data": [ … ], "next_cursor": "…" | null } (también se     ║
 * ║    aceptan `meta.next_cursor` y `links.next`).                              ║
 * ║  - Campos de cada producto: ver mapProduct().                              ║
 * ║ Con la documentación, ajustar PRODUCTS_PATH, nextCursor() y mapProduct();  ║
 * ║ el resto del módulo no cambia.                                             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
class HttpBlessingClient implements BlessingClient
{
    /** PENDIENTE: ruta del listado de productos. */
    public const PRODUCTS_PATH = 'products';

    public const PER_PAGE = 200;

    /** Tope de páginas por corrida, para no quedar en un bucle si la API repite el cursor. */
    public const MAX_PAGES = 1000;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly ?string $priceList = null,
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = 3,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->token) !== '';
    }

    public function source(): string
    {
        return 'api';
    }

    public function products(?string $cursor = null): iterable
    {
        if (! $this->isConfigured()) {
            throw BlessingException::notConfigured();
        }

        $seen = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $this->fetchPage($cursor);
            $rows = $this->rows($payload);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new BlessingException('Blessing devolvió un producto con un formato inesperado.');
                }

                yield $this->mapProduct($row);
            }

            $next = $this->nextCursor($payload);

            if ($next === null || $next === '' || isset($seen[$next])) {
                return;
            }

            $seen[$next] = true;
            $cursor = $next;
        }

        throw new BlessingException('La API de Blessing devolvió demasiadas páginas; se detuvo la sincronización.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchPage(?string $cursor): array
    {
        try {
            $response = $this->request()->get(self::PRODUCTS_PATH, array_filter([
                'price_list' => $this->priceList,
                'per_page' => self::PER_PAGE,
                'cursor' => $cursor,
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            throw new BlessingException('No fue posible conectar con Blessing: no respondió a tiempo.');
        } catch (Throwable $error) {
            throw new BlessingException('No fue posible consultar Blessing ('.class_basename($error).').');
        }

        return $this->decode($response);
    }

    protected function request(): PendingRequest
    {
        // Seguridad (B8): aunque la URL se valide al guardarla, se vuelve a revisar antes de llamar.
        if (($problem = SafeExternalUrl::problem($this->baseUrl)) !== null) {
            throw new BlessingException('La URL de Blessing no es válida: '.$problem);
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken($this->token)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout($this->timeoutSeconds)
            ->retry(
                $this->retries,
                fn (int $attempt) => 500 * $attempt,
                fn (Throwable $error) => $error instanceof ConnectionException
                    || ($error instanceof RequestException && ($error->response->serverError() || $error->response->status() === 429)),
                throw: false,
            );
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new BlessingException('Blessing rechazó el token de acceso de la empresa. Revisa la configuración.');
        }

        if ($response->failed()) {
            throw new BlessingException("Blessing respondió con un error ({$response->status()}).");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new BlessingException('Blessing devolvió una respuesta que no es JSON.');
        }

        return $json;
    }

    /**
     * PENDIENTE: dónde viene la lista de productos en la respuesta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    protected function rows(array $payload): array
    {
        $rows = $payload['data'] ?? $payload['products'] ?? $payload['items'] ?? (array_is_list($payload) ? $payload : null);

        if (! is_array($rows)) {
            throw new BlessingException('La respuesta de Blessing no trae la lista de productos.');
        }

        return $rows;
    }

    /**
     * PENDIENTE: cómo indica la API la página siguiente.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function nextCursor(array $payload): ?string
    {
        $next = $payload['next_cursor'] ?? Arr::get($payload, 'meta.next_cursor') ?? Arr::get($payload, 'links.next');

        if (is_string($next) && str_starts_with($next, 'http')) {
            parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            $next = $query['cursor'] ?? $query['page'] ?? null;
        }

        return $next === null ? null : (string) $next;
    }

    /**
     * ┌─────────────────────────────────────────────────────────────────────┐
     * │ PENDIENTE DE CONFIRMAR CON LA DOCUMENTACIÓN REAL DE BLESSING.         │
     * │ Cada campo prueba varios nombres probables; deja solo el correcto.   │
     * └─────────────────────────────────────────────────────────────────────┘
     *
     * @param  array<string, mixed>  $row
     */
    public function mapProduct(array $row): BlessingProduct
    {
        $pick = function (array $keys) use ($row) {
            foreach ($keys as $key) {
                $value = Arr::get($row, $key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $number = fn (array $keys) => is_numeric($value = $pick($keys)) ? (float) $value : null;
        $bool = fn (array $keys) => ($value = $pick($keys)) === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        $updated = $pick(['updated_at', 'fecha_actualizacion', 'modified_at']);

        try {
            $updatedAt = $updated ? CarbonImmutable::parse((string) $updated, config('app.timezone')) : null;
        } catch (Throwable) {
            $updatedAt = null;
        }

        return new BlessingProduct(
            id: (string) ($pick(['id', 'product_id', 'codigo_interno']) ?? ''),
            sku: trim((string) ($pick(['sku', 'code', 'codigo']) ?? '')),
            description: trim((string) ($pick(['description', 'name', 'descripcion', 'nombre']) ?? '')),
            category: $pick(['category', 'category.name', 'categoria']),
            brand: $pick(['brand', 'brand.name', 'marca']),
            unit: $pick(['unit', 'unit_of_measure', 'unidad']),
            stock: $number(['stock', 'existencia', 'quantity']),
            stockAvailable: $number(['stock_available', 'available', 'existencia_disponible']),
            cost: $number(['cost', 'costo', 'unit_cost']),
            suggestedPrice: $number(['suggested_price', 'price', 'precio_sugerido', 'precio']),
            taxRate: $number(['tax_rate', 'tax', 'isv', 'impuesto']),
            taxIncluded: $bool(['tax_included', 'price_includes_tax', 'incluye_isv']),
            active: $bool(['active', 'is_active', 'activo']) ?? true,
            updatedAt: $updatedAt,
            currency: strtoupper((string) ($pick(['currency', 'moneda']) ?? 'HNL')),
            raw: $row,
        );
    }
}
