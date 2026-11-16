<?php

namespace App\Services\Pricing;

use App\Models\Company;

/**
 * Regla de impuesto de los precios de Blessing, configurable por empresa.
 * Es el ÚNICO lugar donde se decide si el precio sugerido trae ISV y con qué tasa.
 *
 * Supuesto vigente (diseño, sección 10.1): el precio sugerido de Blessing NO
 * incluye ISV y se suma el 15 %. Se cambia por empresa en Empresas (columnas
 * `companies.tax_rate` y `companies.prices_include_tax`) y se sincroniza de
 * nuevo (`php artisan blessing:sync <empresa>`).
 *
 * Precedencia de `prices_include_tax`:
 *   1. lo configurado en la empresa;
 *   2. lo que informe Blessing por producto (si no hay empresa);
 *   3. el supuesto por defecto (false).
 */
final class TaxRules
{
    public const DEFAULT_RATE = 15.0;

    public const DEFAULT_PRICES_INCLUDE_TAX = false;

    public function __construct(
        public readonly float $rate = self::DEFAULT_RATE,
        /** null: sin empresa (decide el origen o el supuesto por defecto). */
        public readonly ?bool $pricesIncludeTax = null,
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    public static function forCompany(?Company $company): self
    {
        if ($company === null) {
            return self::defaults();
        }

        $rate = $company->getAttribute('tax_rate');

        return new self(
            is_numeric($rate) ? max(0.0, min(100.0, (float) $rate)) : self::DEFAULT_RATE,
            (bool) $company->getAttribute('prices_include_tax'),
        );
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
