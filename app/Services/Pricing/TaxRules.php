<?php

namespace App\Services\Pricing;

use App\Models\Company;

/**
 * Regla de impuesto de los precios de Blessing, configurable por empresa.
 * Es el ÚNICO lugar donde se decide si el precio sugerido trae ISV y con qué tasa.
 *
 * Supuesto vigente (diseño, sección 10.1): el precio sugerido de Blessing NO
 * incluye ISV y se suma el 15 %. Para invertirlo en una empresa basta guardar en
 * `companies.settings`:
 *
 *   { "pricing": { "prices_include_tax": true, "tax_rate": 15 } }
 *
 * y sincronizar de nuevo (`php artisan blessing:sync <empresa>`): la sincronización
 * reescribe `tax_included` y la tasa por defecto de cada producto.
 *
 * Precedencia de `prices_include_tax`:
 *   1. lo configurado en la empresa (si existe la clave);
 *   2. lo que informe Blessing por producto (si la API lo trae);
 *   3. el supuesto por defecto (false).
 */
final class TaxRules
{
    public const DEFAULT_RATE = 15.0;

    public const DEFAULT_PRICES_INCLUDE_TAX = false;

    public function __construct(
        public readonly float $rate = self::DEFAULT_RATE,
        /** null: la empresa no lo fijó (decide el origen o el supuesto por defecto). */
        public readonly ?bool $pricesIncludeTax = null,
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    public static function forCompany(?Company $company): self
    {
        return self::fromSettings($company?->settings);
    }

    /**
     * @param  array<string, mixed>|null  $settings  `companies.settings` ya decodificado.
     */
    public static function fromSettings(?array $settings): self
    {
        $pricing = is_array($settings['pricing'] ?? null) ? $settings['pricing'] : [];

        $rate = isset($pricing['tax_rate']) && is_numeric($pricing['tax_rate'])
            ? max(0.0, min(100.0, (float) $pricing['tax_rate']))
            : self::DEFAULT_RATE;

        $included = array_key_exists('prices_include_tax', $pricing) && $pricing['prices_include_tax'] !== null
            ? filter_var($pricing['prices_include_tax'], FILTER_VALIDATE_BOOL)
            : null;

        return new self($rate, $included);
    }

    /** ¿El precio del origen trae el impuesto incluido? */
    public function includesTax(?bool $reportedBySource = null): bool
    {
        return $this->pricesIncludeTax ?? $reportedBySource ?? self::DEFAULT_PRICES_INCLUDE_TAX;
    }

    /** Tasa a usar cuando el origen no informa una para el producto. */
    public function rateFor(?float $reportedBySource = null): float
    {
        return $reportedBySource ?? $this->rate;
    }

    /**
     * @return array{rate: float, prices_include_tax: bool, configured: bool}
     */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'prices_include_tax' => $this->includesTax(),
            'configured' => $this->pricesIncludeTax !== null,
        ];
    }
}
