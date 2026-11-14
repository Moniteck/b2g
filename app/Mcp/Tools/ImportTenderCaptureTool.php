<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\Base64File;
use App\Models\Tender;
use App\Models\User;
use App\Services\Documents\UploadInspector;
use App\Services\Tenders\CaptureImportService;
use App\Support\Binaries;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('import_tender_capture')]
#[Title('Leer captura de HonduCompras')]
#[Description('Lee con OCR la imagen de la ficha de un expediente de HonduCompras (PNG, JPG o WebP en base64, máx. 10 MB) y devuelve un BORRADOR para revisar: cada campo con su valor y su estado (read leído, doubtful dudoso, missing no encontrado), instituciones parecidas y si el expediente ya existe. NO guarda la licitación: muestra el borrador a la persona, corrige con ella los campos dudosos (sobre todo las fechas) y confirma con confirm_tender_import usando el `token`.')]
#[IsDestructive(false)]
class ImportTenderCaptureTool extends BaseTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->companySchema($schema),
            'content_base64' => $schema->string()->description('Imagen de la captura en base64 (sin o con prefijo data:).')->required(),
            'file_name' => $schema->string()->description('Nombre del archivo con extensión, p. ej. «captura.png».')->required(),
        ];
    }

    protected function run(Request $request, User $user, int $companyId): Response|ResponseFactory
    {
        $this->authorize('create', Tender::class);

        $file = Base64File::decode($request->get('content_base64'), $request->get('file_name'), min(Base64File::maxKilobytes(), Binaries::maxImageKilobytes()));
        app(UploadInspector::class)->inspect($file, 'file', CaptureImportService::imageExtensions());

        $imports = app(CaptureImportService::class);
        $token = $imports->read($file, $user, $companyId);
        $review = $imports->review($token, $imports->state($token, $user, $companyId));

        return $this->ok([
            'token' => $token,
            'company_id' => $companyId,
            'ocr_error' => $review['error'],
            'duplicate' => $review['duplicate'],
            'suggested_institution_id' => $review['suggestedInstitutionId'],
            'institution_candidates' => array_map(fn (array $candidate) => [
                'id' => $candidate['id'] ?? null,
                'score' => $candidate['score'] ?? null,
                ...$this->untrusted(['name' => $candidate['name'] ?? null], 255),
            ], $review['institutionCandidates']),
            ...$this->untrusted([
                'fields' => $review['fields'],
                'ocr_text' => $review['ocrText'],
            ], 6000),
            'next_step' => 'Revisa el borrador con la persona y confirma con confirm_tender_import (token y los campos corregidos). El token vale 24 horas, solo para tu usuario y esta empresa.',
        ], $review['error'] ? 'No se leyó la ficha completa: completa los datos con la persona.' : 'Captura leída. Revisa el borrador antes de confirmar.');
    }
}
