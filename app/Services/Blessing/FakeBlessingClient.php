<?php

namespace App\Services\Blessing;

use Carbon\CarbonImmutable;

/**
 * Catálogo simulado de Blessing para pruebas y para la demostración local
 * (`php artisan blessing:demo`). Nunca se usa en producción: la fábrica solo lo
 * entrega con APP_ENV local o testing.
 *
 * Simula la paginación de la API (`PAGE_SIZE` productos por página, el cursor es
 * el desplazamiento) y permite, en pruebas, cambiar productos, quitarlos o
 * provocar un fallo a mitad del recorrido.
 */
class FakeBlessingClient implements BlessingClient
{
    public const PAGE_SIZE = 15;

    /** @var array<string, BlessingProduct> */
    private array $products = [];

    private ?int $failAfter = null;

    private string $failMessage = 'Blessing no respondió (simulado).';

    /** Páginas pedidas en la última corrida (para las pruebas de paginación). */
    public int $pagesRequested = 0;

    /**
     * @param  array<int, BlessingProduct>|null  $products  null: catálogo de demostración.
     */
    public function __construct(?array $products = null)
    {
        foreach ($products ?? self::demoCatalog() as $product) {
            $this->products[$product->id] = $product;
        }
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function source(): string
    {
        return 'demo';
    }

    public function products(?string $cursor = null): iterable
    {
        $this->pagesRequested = 0;
        $list = array_values($this->products);
        $offset = (int) ($cursor ?? 0);
        $yielded = 0;

        while ($offset < count($list)) {
            $this->pagesRequested++;

            foreach (array_slice($list, $offset, self::PAGE_SIZE) as $product) {
                if ($this->failAfter !== null && $yielded >= $this->failAfter) {
                    throw new BlessingException($this->failMessage);
                }

                $yielded++;

                yield $product;
            }

            $offset += self::PAGE_SIZE;
        }

        if ($this->failAfter !== null && $yielded >= $this->failAfter && $this->failAfter >= count($list)) {
            throw new BlessingException($this->failMessage);
        }
    }

    /* ── Ayudas para pruebas ───────────────────────────────────────────── */

    public function failAfter(int $products, ?string $message = null): static
    {
        $this->failAfter = $products;
        $this->failMessage = $message ?? $this->failMessage;

        return $this;
    }

    public function recover(): static
    {
        $this->failAfter = null;

        return $this;
    }

    public function put(BlessingProduct $product): static
    {
        $this->products[$product->id] = $product;

        return $this;
    }

    public function remove(string $id): static
    {
        unset($this->products[$id]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $changes  Propiedades del DTO a reemplazar.
     */
    public function change(string $id, array $changes): static
    {
        $current = get_object_vars($this->products[$id]);
        $this->products[$id] = new BlessingProduct(...array_merge($current, $changes));

        return $this;
    }

    /** @return array<int, BlessingProduct> */
    public function all(): array
    {
        return array_values($this->products);
    }

    public static function product(string $id, array $overrides = []): BlessingProduct
    {
        return new BlessingProduct(...array_merge([
            'id' => $id,
            'sku' => "SKU-{$id}",
            'description' => "Producto {$id}",
            'category' => 'General',
            'brand' => 'Genérica',
            'unit' => 'Unidad',
            'stock' => 10.0,
            'stockAvailable' => 10.0,
            'cost' => 100.0,
            'suggestedPrice' => 130.0,
            'taxRate' => 15.0,
            'updatedAt' => CarbonImmutable::parse('2026-11-10 08:00:00'),
        ], $overrides));
    }

    /**
     * Catálogo realista de ~40 productos, coherente con el pliego de referencia
     * (suministro de lubricantes): aceites, grasas, filtros, refrigerantes y repuestos.
     * Precios sugeridos en lempiras, SIN ISV (supuesto vigente).
     *
     * @return array<int, BlessingProduct>
     */
    public static function demoCatalog(): array
    {
        // [sku, descripción, categoría, marca, unidad, costo, precio sugerido, existencia, disponible]
        $rows = [
            ['LUB-15W40-G', 'Aceite para motor diésel SAE 15W-40 API CK-4', 'Lubricantes', 'Lubrimax', 'Galón', 520.00, 689.00, 340, 310],
            ['LUB-15W40-C', 'Aceite para motor diésel SAE 15W-40 API CK-4, cubeta 5 galones', 'Lubricantes', 'Lubrimax', 'Cubeta', 2410.00, 3195.00, 86, 80],
            ['LUB-15W40-T', 'Aceite para motor diésel SAE 15W-40, tambor 55 galones', 'Lubricantes', 'Lubrimax', 'Tambor', 25650.00, 33890.00, 12, 9],
            ['LUB-20W50-L', 'Aceite para motor gasolina SAE 20W-50 API SN, litro', 'Lubricantes', 'Petrolub', 'Litro', 128.00, 172.50, 620, 600],
            ['LUB-20W50-G', 'Aceite para motor gasolina SAE 20W-50 API SN', 'Lubricantes', 'Petrolub', 'Galón', 455.00, 609.90, 210, 198],
            ['LUB-10W30-L', 'Aceite semisintético SAE 10W-30 API SP, litro', 'Lubricantes', 'Petrolub', 'Litro', 149.00, 199.00, 0, 0],
            ['LUB-5W30-SN', 'Aceite sintético SAE 5W-30 API SP / ILSAC GF-6, litro', 'Lubricantes', 'Lubrimax', 'Litro', 205.00, 279.00, 145, 140],
            ['LUB-HID46-C', 'Aceite hidráulico ISO VG 46 antidesgaste, cubeta 5 galones', 'Lubricantes', 'Hidrotec', 'Cubeta', 2150.00, 2849.00, 64, 60],
            ['LUB-HID68-C', 'Aceite hidráulico ISO VG 68 antidesgaste, cubeta 5 galones', 'Lubricantes', 'Hidrotec', 'Cubeta', 2235.00, 2960.00, 38, 38],
            ['LUB-HID68-T', 'Aceite hidráulico ISO VG 68, tambor 55 galones', 'Lubricantes', 'Hidrotec', 'Tambor', 22900.00, 30250.00, 4, 2],
            ['LUB-ATF-DIII', 'Aceite para transmisión automática ATF Dexron III, litro', 'Lubricantes', 'Petrolub', 'Litro', 139.00, 186.00, 260, 255],
            ['LUB-80W90-G', 'Aceite para engranajes SAE 80W-90 GL-5', 'Lubricantes', 'Lubrimax', 'Galón', 610.00, 815.00, 72, 70],
            ['LUB-85W140-G', 'Aceite para engranajes SAE 85W-140 GL-5', 'Lubricantes', 'Lubrimax', 'Galón', 655.00, 872.00, 18, 18],
            ['LUB-2T-L', 'Aceite para motor de dos tiempos TC-W3, litro', 'Lubricantes', 'Marinox', 'Litro', 118.00, 158.00, 95, 95],
            ['LUB-COMP100', 'Aceite para compresor ISO VG 100, galón', 'Lubricantes', 'Hidrotec', 'Galón', 690.00, 925.00, 0, 0],
            ['GRA-EP2-1LB', 'Grasa multipropósito de litio EP-2, bote 1 lb', 'Grasas', 'Grasol', 'Bote', 96.00, 129.00, 410, 400],
            ['GRA-EP2-CUB', 'Grasa multipropósito de litio EP-2, cubeta 35 lb', 'Grasas', 'Grasol', 'Cubeta', 2390.00, 3175.00, 26, 25],
            ['GRA-ALT-14', 'Grasa para alta temperatura complejo de litio, cartucho 14 oz', 'Grasas', 'Grasol', 'Cartucho', 112.00, 149.50, 380, 372],
            ['GRA-CHAS-14', 'Grasa para chasis NLGI 2, cartucho 14 oz', 'Grasas', 'Grasol', 'Cartucho', 74.00, 99.00, 540, 530],
            ['GRA-MOLY-CUB', 'Grasa con bisulfuro de molibdeno NLGI 2, cubeta 35 lb', 'Grasas', 'Grasol', 'Cubeta', 2870.00, 3820.00, 9, 6],
            ['FIL-ACE-P550', 'Filtro de aceite para motor diésel, rosca 1-14 UNS', 'Filtros', 'TecnoFilt', 'Unidad', 245.00, 329.00, 160, 150],
            ['FIL-ACE-PH8A', 'Filtro de aceite para motor gasolina, rosca 3/4-16', 'Filtros', 'TecnoFilt', 'Unidad', 88.00, 119.00, 300, 290],
            ['FIL-AIR-P181', 'Filtro de aire primario para camión, sello radial', 'Filtros', 'TecnoFilt', 'Unidad', 735.00, 979.00, 44, 40],
            ['FIL-AIR-PU', 'Filtro de aire para vehículo liviano, panel', 'Filtros', 'TecnoFilt', 'Unidad', 210.00, 279.00, 120, 120],
            ['FIL-COM-FS19', 'Filtro separador de combustible y agua', 'Filtros', 'TecnoFilt', 'Unidad', 395.00, 525.00, 66, 60],
            ['FIL-COM-G', 'Filtro de combustible en línea para gasolina', 'Filtros', 'TecnoFilt', 'Unidad', 92.00, 125.00, 0, 0],
            ['FIL-HID-HF6', 'Filtro hidráulico de retorno 10 micras', 'Filtros', 'TecnoFilt', 'Unidad', 610.00, 812.00, 22, 20],
            ['REF-50-G', 'Refrigerante anticongelante 50/50 listo para usar', 'Refrigerantes', 'Coolpro', 'Galón', 265.00, 352.00, 230, 220],
            ['REF-CONC-G', 'Refrigerante concentrado de larga duración', 'Refrigerantes', 'Coolpro', 'Galón', 395.00, 529.00, 75, 75],
            ['LIQ-FRE-DOT4', 'Líquido de frenos DOT 4, 12 oz', 'Refrigerantes', 'Coolpro', 'Bote', 98.00, 132.00, 190, 185],
            ['REP-BUJ-IR', 'Bujía de iridio para motor gasolina', 'Repuestos', 'Servipart', 'Unidad', 165.00, 219.00, 260, 250],
            ['REP-COR-6PK', 'Banda de accesorios 6PK1870', 'Repuestos', 'Servipart', 'Unidad', 410.00, 545.00, 35, 35],
            ['REP-BAT-27', 'Batería 12 V grupo 27, 850 CCA', 'Repuestos', 'Energix', 'Unidad', 3150.00, 4190.00, 14, 12],
            ['REP-BAT-31', 'Batería 12 V grupo 31 para equipo pesado', 'Repuestos', 'Energix', 'Unidad', 4380.00, 5795.00, 6, 6],
            ['REP-PAS-DEL', 'Juego de pastillas de freno delanteras, pick-up', 'Repuestos', 'Servipart', 'Juego', 690.00, 915.00, 48, 45],
            ['REP-ESC-22', 'Escobilla limpiaparabrisas 22 pulgadas', 'Repuestos', 'Servipart', 'Unidad', 105.00, 139.00, 0, 0],
            ['ACC-BOMB-GR', 'Bomba engrasadora manual de palanca, 14 oz', 'Accesorios', 'Grasol', 'Unidad', 520.00, 695.00, 28, 28],
            ['ACC-EMB-5G', 'Embudo de polietileno con filtro, 5 litros', 'Accesorios', 'Servipart', 'Unidad', 85.00, 115.00, 70, 70],
            ['ACC-ABS-KIT', 'Kit absorbente para derrames de aceite, 20 galones', 'Accesorios', 'Ecosorb', 'Kit', 1290.00, 1720.00, 11, 10],
            ['ACC-TRP-25', 'Trapo industrial absorbente, paquete 25 lb', 'Accesorios', 'Ecosorb', 'Paquete', 410.00, 549.00, 52, 50],
        ];

        $updated = CarbonImmutable::parse('2026-11-12 07:30:00');

        return array_map(fn (array $row, int $index) => new BlessingProduct(
            id: 'BL-'.str_pad((string) (1001 + $index), 5, '0', STR_PAD_LEFT),
            sku: $row[0],
            description: $row[1],
            category: $row[2],
            brand: $row[3],
            unit: $row[4],
            stock: (float) $row[7],
            stockAvailable: (float) $row[8],
            cost: (float) $row[5],
            suggestedPrice: (float) $row[6],
            taxRate: 15.0,
            taxIncluded: null,
            active: true,
            updatedAt: $updated->subMinutes($index * 7),
            raw: ['demo' => true, 'sku' => $row[0]],
        ), $rows, array_keys($rows));
    }
}
