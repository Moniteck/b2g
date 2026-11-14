<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Document;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos mínimos de demostración (idempotente). Solo por consola:
 *   php artisan db:seed
 *
 * Fuera del entorno `local` solo crea la empresa y las instituciones: el usuario
 * `admin@crm-b2g.test` con contraseña `password` y los registros de ejemplo son solo
 * para desarrollo. En un servidor, el primer usuario se crea con
 * `php artisan admin:create-user correo@empresa.hn --company=CAP` y, si debe ver todas las empresas,
 * `php artisan crm:root correo@empresa.hn`.
 *
 * Para un juego completo de pruebas manuales (dos empresas, un usuario por
 * rol y registros en cada etapa) use `php artisan db:seed --class=QaSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['tax_id' => '05019012345678'],
            ['name' => 'CAP Honduras', 'representative' => 'Gerente General'],
        );

        $institutions = collect([
            ['name' => 'SEFIN', 'acronym' => 'SEFIN', 'potential' => 'ALTO'],
            ['name' => 'IHSS', 'acronym' => 'IHSS', 'potential' => 'ALTO'],
            ['name' => 'SESAL', 'acronym' => 'SESAL', 'potential' => 'ALTO'],
            ['name' => 'Municipalidad DC', 'acronym' => 'AMDC', 'potential' => 'MEDIO'],
        ])->map(fn (array $institution) => Institution::updateOrCreate(['name' => $institution['name']], $institution + ['status' => 'Activa']));

        if (! app()->environment('local')) {
            $this->command?->warn('Entorno distinto de local: no se crean el usuario demo ni los registros de ejemplo.');

            return;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@crm-b2g.test'],
            ['name' => 'Administrador B2G', 'password' => Hash::make('password')],
        );
        $company->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin', 'is_default' => true]]);

        Opportunity::updateOrCreate(
            ['company_id' => $company->id, 'title' => 'Renovación flota administrativa'],
            ['institution_id' => $institutions[0]->id, 'owner_id' => $admin->id, 'opportunity_type' => 'Licitación', 'amount' => 1800000, 'probability' => 35, 'stage' => 'prospecto', 'expected_close_date' => now()->addMonth()->toDateString(), 'notes' => 'Dato demo'],
        );
        $opportunity = Opportunity::updateOrCreate(
            ['company_id' => $company->id, 'title' => 'Repuestos de ambulancias'],
            ['institution_id' => $institutions[2]->id, 'owner_id' => $admin->id, 'opportunity_type' => 'Compra directa', 'amount' => 640000, 'probability' => 65, 'stage' => 'oportunidad', 'expected_close_date' => now()->addWeeks(3)->toDateString(), 'notes' => 'Dato demo'],
        );

        // Líneas a precio sin ISV (15 %): subtotal, impuesto y total por línea, como QuoteService.
        $lines = [
            ['description' => 'Resma papel bond A4 80gr', 'quantity' => 500, 'unit' => 'Resma', 'unit_cost' => 127.59, 'margin' => 45, 'unit_price' => 185],
            ['description' => 'Cartucho tinta HP 664XL negro', 'quantity' => 50, 'unit' => 'Unidad', 'unit_cost' => 289.66, 'margin' => 45, 'unit_price' => 420],
        ];
        $lines = array_map(function (array $line) {
            $subtotal = round($line['quantity'] * $line['unit_price'], 2);
            $tax = round($subtotal * 0.15, 2);

            return $line + ['tax_rate' => 15, 'tax_included' => false, 'pricing_source' => 'calculadora', 'discount_percent' => 0, 'subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
        }, $lines);
        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $tax = round(array_sum(array_column($lines, 'tax')), 2);

        $quote = Quote::updateOrCreate(
            ['company_id' => $company->id, 'number' => 'COT-2026-0001'],
            ['institution_id' => $institutions[1]->id, 'opportunity_id' => $opportunity->id, 'created_by' => $admin->id, 'prepared_by' => $admin->name, 'quoted_at' => now()->toDateString(), 'purchase_type' => 'Compra Menor', 'validity_days' => 30, 'status' => 'Emitida', 'outcome' => 'Pendiente', 'subtotal' => $subtotal, 'tax' => $tax, 'discount' => 0, 'tax_included' => false, 'total' => round($subtotal + $tax, 2), 'notes' => 'Entrega en cinco días hábiles.'],
        );

        foreach ($lines as $index => $line) {
            $quote->items()->updateOrCreate(['position' => $index + 1], $line);
        }

        if ($opportunity->quote_id !== $quote->id) {
            $opportunity->forceFill(['quote_id' => $quote->id])->save();
        }

        Document::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Constancia SAR'],
            ['kind' => 'empresa', 'category' => 'solvencia_sar', 'issued_at' => now()->subMonths(11)->toDateString(), 'expires_at' => now()->addDays(12)->toDateString(), 'status' => Document::STATUS_EXPIRING, 'uploaded_by' => $admin->id],
        );
    }
}
