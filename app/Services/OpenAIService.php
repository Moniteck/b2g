<?php
namespace App\Services;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
class OpenAIService
{
    private function complete(array $messages, int $maxTokens = 900): string
    {
        $key = config('services.openai.key');
        if (blank($key)) throw new RuntimeException('La integración OpenAI no está configurada.');
        try { $response = Http::timeout(30)->withToken($key)->post('https://api.openai.com/v1/chat/completions', ['model' => config('services.openai.model'), 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $maxTokens]); }
        catch (ConnectionException) { throw new RuntimeException('No fue posible conectar con el servicio de IA.'); }
        if ($response->failed()) throw new RuntimeException('El servicio de IA no pudo procesar la solicitud.');
        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || blank($content)) throw new RuntimeException('El servicio de IA devolvió una respuesta vacía.');
        return $content;
    }
    public function legalReply(array $messages): string
    {
        return $this->complete(array_merge([['role' => 'system', 'content' => 'Eres el Agente Legal B2G Honduras. Ofrece orientación general sobre contratación pública hondureña. No inventes normas; pide revisar pliego, expediente y asesoría legal profesional.']], $messages));
    }
    public function extractTenderLines(string $text): array { $content = $this->complete([['role' => 'system', 'content' => 'Extrae líneas de un pliego. Responde solamente JSON válido: {"title":"", "institution":"", "lines":[{"description":"", "quantity":1, "unit":"Unidad"}]}. No inventes datos.'], ['role' => 'user', 'content' => mb_substr($text, 0, 45000)]], 1800); $decoded = json_decode($content, true); if (!is_array($decoded) || !is_array($decoded['lines'] ?? null)) throw new RuntimeException('La IA no devolvió una estructura de pliego válida.'); return $decoded; }
    public function tenderAnalysis(object $tender, array $rubrics, string $context): string { return $this->complete([['role' => 'system', 'content' => 'Analiza licitaciones B2G Honduras en formato estructurado: recomendación, oportunidades, riesgos, documentos y próximos pasos. No afirmes requisitos no presentes en el contexto.'], ['role' => 'user', 'content' => json_encode(['tender' => $tender->only(['reference','title','purchase_type','estimated_amount','closing_date','notes']), 'rubrics' => $rubrics, 'context' => $context])]], 1200); }
    public function businessDocument(string $type, array $company, array $data): string { return $this->complete([['role' => 'system', 'content' => 'Redacta un documento empresarial formal para contratación pública hondureña. No inventes leyes, expedientes o hechos. Incluye aviso de revisión antes de presentar.'], ['role' => 'user', 'content' => json_encode(['type' => $type, 'company' => $company, 'recipient' => $data['recipient'] ?? '', 'contact' => $data['contact'] ?? '', 'context' => $data['context'] ?? ''])]], 1400); }
    public function legalWriting(array $data): string { return $this->complete([['role' => 'system', 'content' => 'Redacta un borrador legal B2G Honduras para revisión profesional. No inventes artículos, hechos o plazos.'], ['role' => 'user', 'content' => json_encode($data)]], 1400); }
}
