<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SpecializedActionRequest extends FormRequest {
    public function authorize(): bool { return $this->user() !== null; }
    public function rules(): array { return [
        'text' => ['nullable','string','max:50000'], 'spreadsheet' => ['nullable','file','max:10240','mimes:xlsx,xls,csv'], 'title' => ['nullable','string','max:255'], 'institution' => ['nullable','string','max:255'], 'lot' => ['nullable','string','max:255'], 'margin' => ['nullable','numeric','min:0','max:200'],
        'lines' => ['nullable','array','max:1000'], 'lines.*.description' => ['required_with:lines','string','max:1000'], 'lines.*.quantity' => ['required_with:lines','numeric','gt:0'], 'lines.*.unit' => ['nullable','string','max:50'], 'lines.*.price' => ['nullable','numeric','min:0'],
        'type' => ['nullable','string','max:100'], 'recipient' => ['nullable','string','max:255'], 'contact' => ['nullable','string','max:255'], 'context' => ['nullable','string','max:5000'],
        'rubrics' => ['nullable','array','max:20'], 'rubrics.*' => ['string','max:100'], 'minimum' => ['nullable','numeric','min:0'], 'maximum' => ['nullable','numeric','gte:minimum'],
        'name' => ['nullable','string','max:255'], 'channel' => ['nullable','in:whatsapp,email'], 'subject' => ['nullable','string','max:255'], 'body' => ['nullable','string','max:5000'], 'active' => ['nullable','boolean'],
        'starts_at' => ['nullable','date'], 'ends_at' => ['nullable','date','after_or_equal:starts_at'], 'priority' => ['nullable','in:Baja,Normal,Alta,Crítica'], 'completed' => ['nullable','boolean'], 'description' => ['nullable','string','max:5000'],
        'tutorial_key' => ['nullable','string','max:100'], 'progress' => ['nullable','integer','between:0,100'], 'score' => ['nullable','integer','between:0,100'], 'settings' => ['nullable','array'],
    ]; }
}
