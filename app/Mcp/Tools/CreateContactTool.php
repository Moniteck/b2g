<?php

namespace App\Mcp\Tools;

use App\Http\Requests\ContactRequest;
use App\Models\Contact;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('create_contact')]
#[Title('Crear contacto')]
#[Description('Alta rápida de un contacto de una institución (catálogo del grupo).')]
#[IsDestructive(false)]
class CreateContactTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'institution_id' => $schema->integer()->description('Institución del contacto.')->required(),
            'name' => $schema->string()->description('Nombre completo.')->required(),
            'position' => $schema->string()->description('Cargo.'),
            'department' => $schema->string()->description('Departamento.'),
            'email' => $schema->string()->description('Correo.'),
            'phone' => $schema->string()->description('Teléfono.'),
            'whatsapp' => $schema->string()->description('WhatsApp.'),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Contact::class);

        $form = new ContactRequest;
        $data = $this->validate($request->all(), $form->rules(), $form->messages(), $form->attributes());

        $contact = Contact::create($data);
        app(AuditLogger::class)->record('contacto.creado', $contact, ['name' => $contact->name, 'institution_id' => $contact->institution_id], null, $companyId);

        return $this->ok(['contact' => $contact->only(['id', 'institution_id', 'name', 'position', 'email', 'phone'])], "Contacto {$contact->name} creado.");
    }
}
