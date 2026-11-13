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

        $admin = User::firstOrCreate(
            ['email' => 'admin@crm-b2g.test'],
            ['name' => 'Administrador B2G', 'password' => Hash::make('password')],
        );
        $company->users()->syncWithoutDetaching([$admin->id => ['role' => 'admin', 'is_default' => true]]);

        $institutions = collect([
            ['name' => 'SEFIN', 'acronym' => 'SEFIN', 'potential' => 'ALTO'],
            ['name' => 'IHSS', 'acronym' => 'IHSS', 'potential' => 'ALTO'],
            ['name' => 'SESAL', 'acronym' => 'SESAL', 'potential' => 'ALTO'],
            ['name' => 'Municipalidad DC', 'acronym' => 'AMDC', 'potential' => 'MEDIO'],
        ])->map(fn (array $institution) => Institution::updateOrCreate(['name' => $institution['name']], $institution));

        Opportunity::updateOrCreate(
            ['company_id' => $company->id, 'title' => 'Renovación flota administrativa'],
            ['institution_id' => $institutions[0]->id, 'owner_id' => $admin->id, 'amount' => 1800000, 'probability' => 35, 'stage' => 'prospecto', 'expected_close_date' => now()->addMonth(), 'notes' => 'Dato demo'],
        );
        Opportunity::updateOrCreate(
            ['company_id' => $company->id, 'title' => 'Repuestos de ambulancias'],
            ['institution_id' => $institutions[2]->id, 'owner_id' => $admin->id, 'amount' => 640000, 'probability' => 65, 'stage' => 'oportunidad', 'expected_close_date' => now()->addWeeks(3), 'notes' => 'Dato demo'],
        );

        $quote = Quote::updateOrCreate(
            ['company_id' => $company->id, 'number' => 'COT-2026-0001'],
            ['institution_id' => $institutions[1]->id, 'created_by' => $admin->id, 'quoted_at' => now()->toDateString(), 'purchase_type' => 'Compra Menor', 'validity_days' => 30, 'status' => 'Emitida', 'outcome' => 'Pendiente', 'subtotal' => 138000, 'tax' => 20700, 'total' => 158700, 'notes' => 'Entrega en cinco días hábiles.'],
        );

        if ($quote->items()->count() === 0) {
            $quote->items()->createMany([
                ['description' => 'Resma papel bond A4 80gr', 'quantity' => 500, 'unit' => 'Resma', 'unit_cost' => 101.75, 'margin' => 45, 'unit_price' => 185, 'total' => 92500],
                ['description' => 'Cartucho tinta HP 664XL negro', 'quantity' => 50, 'unit' => 'Unidad', 'unit_cost' => 231, 'margin' => 45, 'unit_price' => 420, 'total' => 21000],
            ]);
        }

        Document::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Constancia SAR'],
            ['kind' => 'empresa', 'category' => 'Solvencia', 'expires_at' => now()->addDays(12), 'status' => 'Por vencer'],
        );
    }
}
