<?php

namespace Tests\Feature\Inventory;

use App\Services\Pricing\OfferCalculator;
use App\Services\Pricing\PriceCalculator;
use App\Services\Pricing\PriceLine;
use App\Services\Pricing\TaxRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calculador único de precios: puro, sin base de datos.
 * Los mismos casos deben dar lo mismo en `resources/js/Pages/Inventory/Partials/pricing.js`.
 */
class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new PriceCalculator;
    }

    /* ── Precio SIN impuesto incluido (supuesto vigente para Blessing) ───────── */

    public function test_price_without_tax_adds_the_tax_on_top(): void
    {
        $line = $this->calc->line(689.00, 3, 15, false);

        $this->assertSame(2067.0, $line->subtotal);
        $this->assertSame(310.05, $line->tax);
        $this->assertSame(2377.05, $line->total);
        $this->assertSame(689.0, $line->unitPrice);
        $this->assertSame(103.35, $line->unitTax);
        $this->assertSame(792.35, $line->unitTotal);
        $this->assertSame(15.0, $line->taxRate);
    }

    /* ── Precio CON impuesto incluido ────────────────────────────────────────── */

    public function test_price_with_tax_included_extracts_the_tax(): void
    {
        $line = $this->calc->line(115.00, 1, 15, true);

        $this->assertSame(100.0, $line->subtotal);
        $this->assertSame(15.0, $line->tax);
        $this->assertSame(115.0, $line->total);
        $this->assertSame(100.0, $line->unitPrice);
        $this->assertSame(15.0, $line->unitTax);
        $this->assertSame(115.0, $line->unitTotal);
    }

    public function test_tax_included_total_is_exactly_what_the_customer_pays(): void
    {
        $line = $this->calc->line(10.00, 3, 15, true);

        $this->assertSame(30.0, $line->total);
        $this->assertSame(26.09, $line->subtotal);   // 30 / 1.15 = 26.0869…
        $this->assertSame(3.91, $line->tax);          // total − subtotal, nunca 3.9130…
    }

    public function test_the_same_price_gives_different_totals_depending_on_the_tax_rule(): void
    {
        $without = $this->calc->line(100, 1, 15, false);
        $with = $this->calc->line(100, 1, 15, true);

        $this->assertSame(115.0, $without->total);
        $this->assertSame(100.0, $with->total);
        $this->assertSame(86.96, $with->subtotal);
        $this->assertSame(13.04, $with->tax);
    }

    /* ── Redondeo ─────────────────────────────────────────────────────────────── */

    /**
     * @return array<string, array{float, float, float, bool, float, float, float}>
     */
    public static function roundingCases(): array
    {
        // precio, cantidad, tasa, incluido → subtotal, impuesto, total
        return [
            'mitad hacia arriba en el impuesto' => [0.10, 1, 15, false, 0.10, 0.02, 0.12],
            'coma flotante 1.005' => [1.005, 1, 0, false, 1.01, 0.0, 1.01],
            'coma flotante 0.07 × 100' => [0.07, 100, 15, false, 7.0, 1.05, 8.05],
            'impuesto 14.9985' => [33.33, 3, 15, false, 99.99, 15.0, 114.99],
            'cantidad fraccionaria' => [129.00, 2.5, 15, false, 322.5, 48.38, 370.88],
            'tasa 18 %' => [59.99, 7, 18, false, 419.93, 75.59, 495.52],
            'incluido con decimales' => [172.50, 7, 15, true, 1050.0, 157.5, 1207.5],
            'incluido 0.01' => [0.01, 1, 15, true, 0.01, 0.0, 0.01],
            'incluido 18 %' => [236.00, 1, 18, true, 200.0, 36.0, 236.0],
            'cero' => [0.0, 5, 15, false, 0.0, 0.0, 0.0],
            'importe grande' => [33890.00, 120, 15, false, 4066800.0, 610020.0, 4676820.0],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_rounding_rule(float $price, float $qty, float $rate, bool $included, float $subtotal, float $tax, float $total): void
    {
        $line = $this->calc->line($price, $qty, $rate, $included);

        $this->assertSame($subtotal, $line->subtotal, 'subtotal');
        $this->assertSame($tax, $line->tax, 'impuesto');
        $this->assertSame($total, $line->total, 'total');
        $this->assertSame(round($line->subtotal + $line->tax, 2), $line->total, 'total = subtotal + impuesto');
    }

    public function test_line_tax_is_computed_on_the_line_not_by_multiplying_the_unit_tax(): void
    {
        // Impuesto unitario 0.15 × 0.15 = 0.0225 → 0.02; ×10 serían 0.20, pero sobre la línea es 0.23.
        $line = $this->calc->line(0.15, 10, 15, false);

        $this->assertSame(0.02, $line->unitTax);
        $this->assertSame(1.5, $line->subtotal);
        $this->assertSame(0.23, $line->tax);
    }

    public function test_round_helper_never_returns_negative_zero(): void
    {
        $this->assertSame('0', (string) PriceCalculator::round(-0.001));
        $this->assertSame(2.68, PriceCalculator::round(2.675));
        $this->assertSame(-2.68, PriceCalculator::round(-2.675));
    }

    /* ── Descuento ────────────────────────────────────────────────────────────── */

    public function test_percent_discount_reduces_the_base_before_tax(): void
    {
        $line = $this->calc->line(1000, 1, 15, false, discountPercent: 10);

        $this->assertSame(1000.0, $line->gross);
        $this->assertSame(100.0, $line->discount);
        $this->assertSame(900.0, $line->subtotal);
        $this->assertSame(135.0, $line->tax);
        $this->assertSame(1035.0, $line->total);
    }

    public function test_fixed_and_percent_discount_add_up(): void
    {
        $line = $this->calc->line(50, 4, 15, false, discount: 15, discountPercent: 5);

        $this->assertSame(25.0, $line->discount); // 10 + 15
        $this->assertSame(175.0, $line->subtotal);
        $this->assertSame(26.25, $line->tax);
    }

    public function test_discount_never_exceeds_the_line_amount(): void
    {
        $line = $this->calc->line(100, 1, 15, false, discount: 500);

        $this->assertSame(100.0, $line->discount);
        $this->assertSame(0.0, $line->subtotal);
        $this->assertSame(0.0, $line->total);
    }

    public function test_discount_on_a_price_with_tax_included(): void
    {
        $line = $this->calc->line(115, 2, 15, true, discountPercent: 10);

        $this->assertSame(23.0, $line->discount);
        $this->assertSame(207.0, $line->total);
        $this->assertSame(180.0, $line->subtotal);
        $this->assertSame(27.0, $line->tax);
    }

    /* ── Exoneración ──────────────────────────────────────────────────────────── */

    public function test_exempt_line_without_tax_included_pays_no_tax(): void
    {
        $line = $this->calc->line(100, 2, 15, false, exempt: true);

        $this->assertSame(200.0, $line->subtotal);
        $this->assertSame(0.0, $line->tax);
        $this->assertSame(200.0, $line->total);
        $this->assertSame(0.0, $line->taxRate);
        $this->assertSame(15.0, $line->nominalRate);
        $this->assertTrue($line->exempt);
    }

    public function test_exempt_line_with_tax_included_pays_the_base(): void
    {
        $line = $this->calc->line(115, 1, 15, true, exempt: true);

        $this->assertSame(100.0, $line->subtotal);
        $this->assertSame(0.0, $line->tax);
        $this->assertSame(100.0, $line->total);
        $this->assertSame(100.0, $line->unitTotal);
    }

    /* ── Documento: tasas mixtas y suma por líneas ────────────────────────────── */

    public function test_document_with_mixed_rates(): void
    {
        $document = $this->calc->document([
            $this->calc->line(100, 1, 15, false),
            $this->calc->line(200, 1, 18, false),
            $this->calc->line(50, 2, 0, false),
            $this->calc->line(40, 1, 15, false, exempt: true),
        ]);

        $this->assertSame(440.0, $document->subtotal);
        $this->assertSame(51.0, $document->tax);
        $this->assertSame(491.0, $document->total);
        $this->assertSame(140.0, $document->exemptBase);
        $this->assertSame(300.0, $document->taxedBase);
        $this->assertSame([
            ['rate' => 0.0, 'base' => 140.0, 'tax' => 0.0],
            ['rate' => 15.0, 'base' => 100.0, 'tax' => 15.0],
            ['rate' => 18.0, 'base' => 200.0, 'tax' => 36.0],
        ], $document->taxes);
    }

    public function test_document_totals_are_the_sum_of_rounded_lines(): void
    {
        $lines = array_fill(0, 3, $this->calc->line(0.10, 1, 15, false));
        $document = $this->calc->document($lines);

        // Cada línea lleva 0.02 de impuesto; sobre el subtotal global (0.30) serían 0.05.
        $this->assertSame(0.3, $document->subtotal);
        $this->assertSame(0.06, $document->tax);
        $this->assertSame(0.36, $document->total);
    }

    public function test_document_of_many_lines_has_no_cent_drift(): void
    {
        $lines = [];
        $expected = 0;

        for ($i = 1; $i <= 250; $i++) {
            $line = $this->calc->line(($i * 7.13) / 3, ($i % 7) + 0.5, $i % 3 === 0 ? 18 : 15, $i % 2 === 0, discountPercent: $i % 5);
            $lines[] = $line;
            $expected += (int) round($line->total * 100);
            $this->assertSame(round($line->subtotal + $line->tax, 2), $line->total);
        }

        $document = $this->calc->document($lines);

        $this->assertSame($expected / 100, $document->total);
        $this->assertSame(round($document->subtotal + $document->tax, 2), $document->total);
        $this->assertCount(250, $document->toArray()['lines']);
    }

    public function test_empty_document_is_zero(): void
    {
        $document = $this->calc->document([]);

        $this->assertSame(0.0, $document->total);
        $this->assertSame('HNL', $document->currency);
        $this->assertSame([], $document->taxes);
    }

    public function test_document_rejects_mixed_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->document([
            $this->calc->line(10, 1, 15, false, currency: 'HNL'),
            $this->calc->line(10, 1, 15, false, currency: 'USD'),
        ]);
    }

    public function test_usd_is_supported(): void
    {
        $document = $this->calc->document([$this->calc->line(19.99, 3, 15, false, currency: 'USD')]);

        $this->assertSame('USD', $document->currency);
        $this->assertSame(68.97, $document->total);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function invalidLines(): array
    {
        return [
            'precio negativo' => [-1, 1, 15, 'HNL'],
            'cantidad cero' => [10, 0, 15, 'HNL'],
            'cantidad negativa' => [10, -2, 15, 'HNL'],
            'tasa mayor que 100' => [10, 1, 101, 'HNL'],
            'tasa negativa' => [10, 1, -15, 'HNL'],
            'moneda no admitida' => [10, 1, 15, 'EUR'],
        ];
    }

    #[DataProvider('invalidLines')]
    public function test_invalid_input_is_rejected(float $price, float $qty, float $rate, string $currency): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->line($price, $qty, $rate, false, currency: $currency);
    }

    public function test_invalid_discount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->line(10, 1, 15, false, discountPercent: 120);
    }

    /* ── Margen sobre la venta ────────────────────────────────────────────────── */

    public function test_price_from_margin_is_margin_on_sale(): void
    {
        $this->assertSame(100.0, $this->calc->priceFromMargin(80, 20));
        $this->assertSame(100.0, $this->calc->priceFromMargin(100, 0));
        $this->assertSame(1333.33, $this->calc->priceFromMargin(1000, 25));
        $this->assertSame(689.34, $this->calc->priceFromMargin(520, 24.565));
        $this->assertSame(20.0, $this->calc->marginOf(80, 100));
        $this->assertSame(0.0, $this->calc->marginOf(80, 0));
    }

    public function test_margin_of_100_or_more_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->priceFromMargin(80, 100);
    }

    public function test_negative_margin_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc->priceFromMargin(80, -5);
    }

    /* ── Calculadora de oferta ────────────────────────────────────────────────── */

    public function test_offer_calculator_uses_cost_margin_and_tax(): void
    {
        $result = (new OfferCalculator($this->calc))->calculate([
            ['description' => 'Aceite 15W-40', 'quantity' => 10, 'cost' => 520, 'margin' => 20, 'tax_rate' => 15],
            ['description' => 'Filtro', 'quantity' => 4, 'cost' => 245, 'margin' => 30, 'tax_rate' => 15, 'exempt' => true],
        ], 'HNL', [10, 20]);

        $this->assertSame(650.0, $result['lines'][0]['unit_price']);      // 520 / 0.8
        $this->assertSame(6500.0, $result['lines'][0]['subtotal']);
        $this->assertSame(975.0, $result['lines'][0]['tax']);
        $this->assertSame(1300.0, $result['lines'][0]['profit']);
        $this->assertSame(350.0, $result['lines'][1]['unit_price']);      // 245 / 0.7
        $this->assertSame(0.0, $result['lines'][1]['tax']);

        $this->assertSame(7900.0, $result['totals']['subtotal']);
        $this->assertSame(975.0, $result['totals']['tax']);
        $this->assertSame(8875.0, $result['totals']['total']);
        $this->assertSame(6180.0, $result['totals']['cost_total']);
        $this->assertSame(1720.0, $result['totals']['profit']);

        $this->assertSame(10.0, $result['scenarios'][0]['margin']);
        $this->assertSame(6866.68, $result['scenarios'][0]['subtotal']); // 577.78 × 10 + 272.22 × 4 (precio unitario al centavo)
        $this->assertSame(7725.0, $result['scenarios'][1]['subtotal']);  // 6500 + 1225
    }

    /* ── Regla de impuesto por empresa ────────────────────────────────────────── */

    public function test_tax_rules_default_to_price_without_tax_at_15(): void
    {
        $rules = TaxRules::defaults();

        $this->assertSame(15.0, $rules->rate);
        $this->assertFalse($rules->includesTax());
        $this->assertTrue($rules->includesTax(true), 'sin configuración de empresa decide el origen');
    }

    public function test_company_settings_override_the_source(): void
    {
        $included = TaxRules::fromSettings(['pricing' => ['prices_include_tax' => true, 'tax_rate' => 18]]);
        $excluded = TaxRules::fromSettings(['pricing' => ['prices_include_tax' => false]]);

        $this->assertTrue($included->includesTax(false));
        $this->assertSame(18.0, $included->rateFor(null));
        $this->assertSame(15.0, $included->rateFor(15.0), 'la tasa del producto manda sobre la de la empresa');
        $this->assertFalse($excluded->includesTax(true));
        $this->assertSame(15.0, TaxRules::fromSettings(['pricing' => ['tax_rate' => 'x']])->rate);
        $this->assertSame(100.0, TaxRules::fromSettings(['pricing' => ['tax_rate' => 500]])->rate);
    }

    public function test_line_serializes_to_snake_case(): void
    {
        $array = $this->calc->line(10, 1, 15, false)->toArray();

        $this->assertEqualsCanonicalizing(
            ['currency', 'quantity', 'unit_price', 'unit_tax', 'unit_total', 'source_unit_price', 'gross', 'discount', 'subtotal', 'tax_rate', 'nominal_rate', 'tax', 'total', 'tax_included', 'exempt'],
            array_keys($array),
        );
        $this->assertInstanceOf(PriceLine::class, $this->calc->line(10, 1, 15, false));
    }
}
