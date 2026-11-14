<?php

namespace App\Mcp\Tools;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_contacts')]
#[Title('Buscar contactos')]
#[Description('Contactos de las instituciones (catálogo del grupo): por institución y/o por nombre, cargo o correo.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchContactsTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'institution_id' => $schema->integer()->description('Institución.'),
            'search' => $schema->string()->description('Nombre, cargo o correo.'),
            'limit' => $schema->integer()->description('Máximo de resultados (1 a 50, por defecto 20).'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('viewAny', Contact::class);

        $data = $this->validate($request->all(), [
            'institution_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $term = filled($data['search'] ?? null) ? '%'.str_replace(['%', '_'], ['\%', '\_'], trim($data['search'])).'%' : null;

        return $this->ok([
            'contacts' => Contact::query()
                ->with('institution:id,name')
                ->when(! empty($data['institution_id']), fn ($query) => $query->where('institution_id', $data['institution_id']))
                ->when($term !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', $term)->orWhere('position', 'like', $term)->orWhere('email', 'like', $term)))
                ->orderBy('name')->limit((int) ($data['limit'] ?? 20))
                ->get(['id', 'institution_id', 'name', 'position', 'department', 'email', 'phone', 'whatsapp'])
                ->map(fn (Contact $contact) => $contact->only(['id', 'institution_id', 'name', 'position', 'department', 'email', 'phone', 'whatsapp']) + ['institution' => $contact->institution?->name])
                ->all(),
        ]);
    }
}
