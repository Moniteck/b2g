<?php

namespace App\Services\Documents;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Entidades que admiten adjuntos: único lugar donde se declaran.
 *
 * Para añadir una entidad, agrega una entrada a TYPES con su modelo (debe usar
 * `BelongsToCompany`), el `kind` con que se guardan sus documentos y la categoría por defecto.
 * Las rutas `/adjuntos/{tipo}/{id}` la aceptan sin más cambios.
 *
 * Autorización: `view` de la entidad para listar y `update` para subir o quitar. Si la policy
 * de la entidad define `manageAttachments`, se usa esa en lugar de `update` (útil cuando la
 * entidad ya no se puede editar, p. ej. una cotización presentada, pero sí recibir PDF).
 */
final class Attachables
{
    /** @var array<string, array{model: class-string<Model>, kind: string, label: string}> */
    public const TYPES = [
        'quote' => ['model' => Quote::class, 'kind' => 'proceso', 'label' => 'cotización'],
        'tender' => ['model' => Tender::class, 'kind' => 'proceso', 'label' => 'licitación'],
        'opportunity' => ['model' => Opportunity::class, 'kind' => 'proceso', 'label' => 'oportunidad'],
        'contract' => ['model' => Contract::class, 'kind' => 'contrato', 'label' => 'contrato'],
        'client' => ['model' => Client::class, 'kind' => 'cliente', 'label' => 'cliente'],
    ];

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /**
     * Entidad de la empresa activa (el scope de empresa da 404 si es de otra).
     */
    public static function find(string $type, int|string $id): Model
    {
        abort_unless(self::exists($type), 404);

        $class = self::TYPES[$type]['model'];

        return $class::query()->findOrFail($id);
    }

    public static function kindOf(string $type): string
    {
        return self::TYPES[$type]['kind'] ?? 'proceso';
    }

    /** Tipo corto de un modelo (`Quote` => `quote`), o null si no admite adjuntos. */
    public static function typeOf(Model $model): ?string
    {
        foreach (self::TYPES as $type => $definition) {
            if ($model instanceof $definition['model']) {
                return $type;
            }
        }

        return null;
    }

    /** Habilidad de la policy para subir o quitar adjuntos. */
    public static function editAbility(Model $model): string
    {
        $policy = Gate::getPolicyFor($model);

        return $policy !== null && method_exists($policy, 'manageAttachments') ? 'manageAttachments' : 'update';
    }
}
