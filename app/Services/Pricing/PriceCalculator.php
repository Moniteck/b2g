<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Calculador ÚNICO de precios e impuestos del CRM (inventario, cotizaciones,
 * calculadora de oferta y MCP). Puro: no consulta la base de datos ni la
 * configuración; quien lo llama le pasa la tasa y si el precio trae impuesto
 * (ver TaxRules e InventoryPricing).
 *
 * El espejo en el navegador es `resources/js/Modules/Inventory/pricing.js`:
 * cualquier cambio de regla se hace en los dos y lo cubren las pruebas de
 * `tests/Feature/Inventory/PriceCalculatorTest.php`.
 *
 * ── Regla de redondeo ──────────────────────────────────────────────────────
 * 1. Redondeo comercial a 2 decimales, mitad hacia arriba (lejos de cero),
 *    aplicado tras normalizar a 6 decimales para que los errores binarios de
 *    coma flotante no cambien el centavo (1.005 → 1.01, nunca 1.00).
 * 2. POR LÍNEA, en este orden y redondeando cada paso:
 *      importe = precio unitario × cantidad
 *      descuento = importe × % descuento + descuento fijo (tope: el importe)
 *      precio SIN impuesto: subtotal = importe − descuento
 *                           impuesto = subtotal × tasa
 *                           total    = subtotal + impuesto
 *      precio CON impuesto: total    = importe − descuento (lo que paga el cliente)
 *                           subtotal = total ÷ (1 + tasa)
 *                           impuesto = total − subtotal
 *    El impuesto de la línea se calcula sobre el subtotal de la línea, no
 *    sumando el impuesto unitario (los unitarios son informativos).
 * 3. POR DOCUMENTO: se suman las líneas ya redondeadas (en centavos enteros).
 *    No se recalcula el impuesto sobre el subtotal global; así el documento
 *    cuadra al centavo con su detalle, también con tasas mixtas.
 *
 * ── Exoneración ────────────────────────────────────────────────────────────
 * Una línea exonerada no lleva impuesto. Si el precio venía con impuesto
 * incluido, el cliente exonerado paga la base (el precio sin el impuesto).
 *
 * ── Margen ─────────────────────────────────────────────────────────────────
 * El margen es SOBRE LA VENTA: precio = costo ÷ (1 − margen). Un margen del
 * 20 % sobre un costo de 80 da un precio de 100 (la ganancia, 20, es el 20 %
 * del precio). Sustituye a la fórmula `costo × (1 + m)` de la calculadora
 * antigua (eso es un recargo sobre el costo, no un margen).
 */
final class PriceCalculator
{
    public const CURRENCIES = ['HNL', 'USD'];

    public const DEFAULT_CURRENCY = 'HNL';

    /** Redondeo comercial a 2 decimales (regla 1). */
    public static function round(float $value): float
    {
        $rounded = round(round($value, 6), 2, PHP_ROUND_HALF_UP);

        return $rounded == 0.0 ? 0.0 : $rounded; // sin «-0»
    }

    /**
     * Calcula una línea.
     *
     * @param  float  $unitPrice  Precio unitario en la base indicada por `$taxIncluded`.
     * @param  float  $taxRate  Tasa en porcentaje (15 = 15 %). 0 = exento.
     * @param  float  $discount  Descuento fijo de la línea, en la misma base que el precio.
     * @param  float  $discountPercent  Descuento porcentual sobre el importe de la línea.
     */
    public function line(
        float $unitPrice,
        float $quantity,
        float $taxRate,
        bool $taxIncluded,
        float $discount = 0.0,
        float $discountPercent = 0.0,
        bool $exempt = false,
        string $currency = self::DEFAULT_CURRENCY,
    ): PriceLine {
        $this->guard($unitPrice, $quantity, $taxRate, $discount, $discountPercent, $currency);

        $factor = 1 + ($taxRate / 100);
        $appliedRate = $exempt ? 0.0 : $taxRate;

        $gross = self::round($unitPrice * $quantity);
        $discountAmount = min($gross, self::round($gross * $discountPercent / 100) + self::round($discount));
        $amount = self::round($gross - $discountAmount);

        if ($taxIncluded) {
            $subtotal = self::round($amount / $factor);
            $tax = $exempt ? 0.0 : self::round($amount - $subtotal);
            $unitNet = self::round($unitPrice / $factor);
            $unitTax = $exempt ? 0.0 : self::round(self::round($unitPrice) - $unitNet);
        } else {
            $subtotal = $amount;
            $tax = self::round($subtotal * $appliedRate / 100);
            $unitNet = self::round($unitPrice);
            $unitTax = self::round($unitNet * $appliedRate / 100);
        }

        return new PriceLine(
            currency: $currency,
            quantity: $quantity,
            unitPrice: $unitNet,
            unitTax: $unitTax,
            unitTotal: self::round($unitNet + $unitTax),
            sourceUnitPrice: self::round($unitPrice),
            gross: $gross,
            discount: self::round($discountAmount),
            subtotal: $subtotal,
            taxRate: $appliedRate,
            nominalRate: $taxRate,
            tax: $tax,
            total: self::round($subtotal + $tax),
            taxIncluded: $taxIncluded,
            exempt: $exempt,
        );
    }

    /**
     * Totales de un documento a partir de líneas ya calculadas (regla 3).
     *
     * @param  iterable<PriceLine>  $lines
     */
    public function document(iterable $lines, ?string $currency = null): PriceDocument
    {
        $list = [];
        $sums = ['gross' => 0, 'discount' => 0, 'subtotal' => 0, 'tax' => 0, 'total' => 0];
        $byRate = [];
        $exempt = 0;
        $taxed = 0;

        foreach ($lines as $line) {
            if (! $line instanceof PriceLine) {
                throw new InvalidArgumentException('Cada línea debe ser un PriceLine.');
            }

            $currency ??= $line->currency;

            if ($line->currency !== $currency) {
                throw new InvalidArgumentException('No se pueden sumar líneas en monedas distintas.');
            }

            $list[] = $line;

            foreach ($sums as $key => $sum) {
                $sums[$key] = $sum + self::cents($line->{$key});
            }

            $rateKey = number_format($line->taxRate, 2, '.', '');
            $byRate[$rateKey] ??= ['base' => 0, 'tax' => 0];
            $byRate[$rateKey]['base'] += self::cents($line->subtotal);
            $byRate[$rateKey]['tax'] += self::cents($line->tax);

            if ($line->taxRate > 0) {
                $taxed += self::cents($line->subtotal);
            } else {
                $exempt += self::cents($line->subtotal);
            }
        }

        ksort($byRate, SORT_NUMERIC);

        $taxes = [];
        foreach ($byRate as $rate => $values) {
            $taxes[] = ['rate' => (float) $rate, 'base' => $values['base'] / 100.0, 'tax' => $values['tax'] / 100.0];
        }

        return new PriceDocument(
            currency: $currency ?? self::DEFAULT_CURRENCY,
            lines: $list,
            gross: $sums['gross'] / 100.0,
            discount: $sums['discount'] / 100.0,
            subtotal: $sums['subtotal'] / 100.0,
            tax: $sums['tax'] / 100.0,
            total: $sums['total'] / 100.0,
            taxes: $taxes,
            exemptBase: $exempt / 100.0,
            taxedBase: $taxed / 100.0,
        );
    }

    /**
     * Precio de venta SIN impuesto a partir del costo y del margen sobre la venta.
     *
     *   precio = costo ÷ (1 − margen/100)
     */
    public function priceFromMargin(float $cost, float $marginPercent): float
    {
        if ($cost < 0) {
            throw new InvalidArgumentException('El costo no puede ser negativo.');
        }

        if ($marginPercent < 0 || $marginPercent >= 100) {
            throw new InvalidArgumentException('El margen sobre la venta debe estar entre 0 % y menos de 100 %.');
        }

        return self::round($cost / (1 - ($marginPercent / 100)));
    }

    /**
     * Margen sobre la venta (%) que deja un precio sin impuesto: (precio − costo) ÷ precio.
     */
    public function marginOf(float $cost, float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return self::round((($price - $cost) / $price) * 100);
    }

    private static function cents(float $value): int
    {
        return (int) round($value * 100);
    }

    private function guard(float $unitPrice, float $quantity, float $taxRate, float $discount, float $discountPercent, string $currency): void
    {
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new InvalidArgumentException("Moneda no admitida: {$currency}.");
        }

        if ($unitPrice < 0) {
            throw new InvalidArgumentException('El precio unitario no puede ser negativo.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        if ($taxRate < 0 || $taxRate > 100) {
            throw new InvalidArgumentException('La tasa de impuesto debe estar entre 0 % y 100 %.');
        }

        if ($discount < 0 || $discountPercent < 0 || $discountPercent > 100) {
            throw new InvalidArgumentException('El descuento no es válido.');
        }
    }
}
