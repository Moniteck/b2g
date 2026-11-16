<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;

/**
 * Utilidades de las pantallas financieras: tamaño de página y permisos que
 * recibe la interfaz (`can`) para ocultar lo que el rol no puede hacer.
 */
trait FinanceScreen
{
    /** @var array<int, int> */
    private array $pageSizes = [25, 50, 100];

    protected function perPage(Request $request): int
    {
        $size = $request->integer('per_page', 25);

        return in_array($size, $this->pageSizes, true) ? $size : 25;
    }

    /**
     * @param  class-string  $model
     * @return array{create: bool, update: bool, delete: bool, export: bool}
     */
    protected function abilities(User $user, string $model): array
    {
        $write = $user->can('create', $model);

        return [
            'create' => $write,
            'update' => $write,
            'delete' => $user->hasPermission(Permissions::DELETE),
            'export' => $user->hasPermission(Permissions::VIEW_REPORTS),
        ];
    }

    /** Texto de búsqueda saneado para `LIKE`. */
    protected function searchTerm(Request $request): ?string
    {
        $term = trim((string) $request->input('search', ''));

        return $term === '' ? null : '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
    }
}
