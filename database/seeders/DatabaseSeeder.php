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

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@crm-b2g.test'], ['name' => 'Administrador B2G', 'password' => Hash::make('password'), 'role' => 'admin']);
        $company = Company::updateOrCreate(['tax_id' => '05019012345678'], ['name' => 'CAP Honduras', 'representative' => 'Gerente General']);
        $institutions = collect([['name' => 'SEFIN', 'acronym' => 'SEFIN', 'potential' => 'ALTO'], ['name' => 'IHSS', 'acronym' => 'IHSS', 'potential' => 'ALTO'], ['name' => 'SESAL', 'acronym' => 'SESAL', 'potential' => 'ALTO'], ['name' => 'Municipalidad DC', 'acronym' => 'AMDC', 'potential' => 'MEDIO']])->map(fn ($institution) => Institution::updateOrCreate(['name' => $institution['name']], $institution));
        Opportunity::updateOrCreate(['title' => 'Renovación flota administrativa'], ['institution_id' => $institutions[0]->id, 'owner_id' => $admin->id, 'amount' => 1800000, 'probability' => 35, 'stage' => 'prospecto', 'expected_close_date' => now()->addMonth(), 'notes' => 'Dato demo']);
        Opportunity::updateOrCreate(['title' => 'Repuestos de ambulancias'], ['institution_id' => $institutions[2]->id, 'owner_id' => $admin->id, 'amount' => 640000, 'probability' => 65, 'stage' => 'propuesta', 'expected_close_date' => now()->addWeeks(3), 'notes' => 'Dato demo']);
        $quote = Quote::updateOrCreate(['number' => 'COT-2026-0001'], ['company_id' => $company->id, 'institution_id' => $institutions[1]->id, 'created_by' => $admin->id, 'quoted_at' => now()->toDateString(), 'purchase_type' => 'Compra Menor', 'validity_days' => 30, 'status' => 'Enviada', 'subtotal' => 138000, 'tax' => 20700, 'total' => 158700, 'notes' => 'Entrega en cinco días hábiles.']);
        if ($quote->items()->count() === 0) {
            $quote->items()->createMany([['description' => 'Resma papel bond A4 80gr', 'quantity' => 500, 'unit' => 'Resma', 'unit_cost' => 101.75, 'margin' => 45, 'unit_price' => 185, 'total' => 92500], ['description' => 'Cartucho tinta HP 664XL negro', 'quantity' => 50, 'unit' => 'Unidad', 'unit_cost' => 231, 'margin' => 45, 'unit_price' => 420, 'total' => 21000]]);
        }
        Document::updateOrCreate(['name' => 'Constancia SAR'], ['category' => 'Fiscal', 'expires_at' => now()->addDays(12), 'status' => 'Por vencer']);
    }
}
