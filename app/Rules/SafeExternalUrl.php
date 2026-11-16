<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL de un servicio externo al que llama el servidor (p. ej. la API de Blessing), sin SSRF
 * (hallazgo B8): solo `https` y nunca `localhost` ni direcciones privadas, reservadas o de
 * enlace local (tampoco si el nombre resuelve a una de ellas). En `local` se admiten `http` y
 * hosts locales para poder probar contra un servidor de desarrollo.
 *
 *   'blessing_base_url' => ['nullable', 'url', new SafeExternalUrl],
 *   SafeExternalUrl::problem($url);   // null si es válida; si no, el motivo en español
 */
class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (($problem = self::problem((string) $value)) !== null) {
            $fail($problem);
        }
    }

    public static function problem(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return 'Escriba una URL completa, p. ej. https://api.blessing.hn.';
        }

        if (app()->environment('local')) {
            return null;
        }

        if ($scheme !== 'https') {
            return 'La URL debe usar https:// para que el token viaje cifrado.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'La URL no debe incluir usuario ni contraseña.';
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'La URL no puede apuntar a un servidor local o interno.';
        }

        // En pruebas no se consulta el DNS (sin llamadas externas); las IP literales sí se revisan.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (app()->runningUnitTests() ? [] : self::resolve($host));

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'La URL no puede apuntar a una dirección privada, reservada o local.';
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
