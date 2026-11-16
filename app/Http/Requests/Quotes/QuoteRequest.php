<?php

namespace App\Http\Requests\Quotes;

use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\Tender;
use App\Rules\AllowedCompany;
use App\Rules\BelongsToActiveCompany;
use App\Support\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una cotización con sus líneas (`create` o `update` de QuotePolicy).
 *
 * Los ids relacionados (oportunidad, licitación, producto) deben pertenecer a la empresa emisora:
 * `BelongsToActiveCompany` toma el `company_id` de la petición o, si no viene, la empresa activa.
 */
class QuoteRequest extends FormRequest
{
    /** Se autoriza antes de validar: sin permiso, 403 y no errores de formulario. */
    public function authorize(): bool
    {
        $quote = $this->route('quote');

        return $quote !== null
            ? (bool) $this->user()?->can('update', $quote)
            : (bool) $this->user()?->can('create', Quote::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('quote') !== null) {
            // La empresa emisora no cambia al editar: los ids se validan contra la de la cotización.
            $this->merge(['company_id' => $this->route('quote')->company_id]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('quote') === null;

        return [
            'company_id' => [$creating ? 'nullable' : 'required', 'integer', new AllowedCompany($creating ? Permissions::CREATE : null)],
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('institution_id', $this->input('institution_id')))],
            'opportunity_id' => ['nullable', 'integer', new BelongsToActiveCompany(Opportunity::class)],
            'tender_id' => ['nullable', 'integer', new BelongsToActiveCompany(Tender::class)],
            'quoted_at' => ['nullable', 'date'],
            'validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'purchase_type' => ['nullable', 'string', 'max:100'],
            'tax_included' => ['nullable', 'boolean'],
            'conditions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_included' => ['nullable', 'boolean'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.margin' => ['nullable', 'numeric', 'min:0', 'max:95'],
            'items.*.inventory_item_id' => ['nullable', 'integer', new BelongsToActiveCompany(InventoryItem::class)],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.pricing_source' => ['nullable', 'in:manual,blessing,calculadora'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_id' => 'empresa emisora',
            'institution_id' => 'institución',
            'contact_id' => 'contacto',
            'opportunity_id' => 'oportunidad',
            'tender_id' => 'licitación',
            'quoted_at' => 'fecha',
            'validity_days' => 'vigencia',
            'items' => 'líneas',
            'items.*.description' => 'descripción',
            'items.*.quantity' => 'cantidad',
            'items.*.unit' => 'unidad',
            'items.*.unit_price' => 'precio unitario',
            'items.*.tax_rate' => 'tasa de ISV',
            'items.*.discount_percent' => 'descuento',
            'items.*.inventory_item_id' => 'producto',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'institution_id.required' => 'Elija la institución a la que va la cotización.',
            'items.required' => 'Agregue al menos una línea.',
            'items.min' => 'Agregue al menos una línea.',
            'items.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ];
    }
}
