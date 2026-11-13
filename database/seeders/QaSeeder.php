<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Juego de datos para pruebas manuales (QA). Idempotente y solo por consola:
 *
 *   php artisan db:seed --class=QaSeeder
 *
 * Crea dos empresas y un usuario por rol, todos con la contraseña de QA:
 *
 *   qa.root@crm-b2g.test       raíz del grupo
 *   qa.admin@crm-b2g.test      admin    de CAP Honduras
 *   qa.operador@crm-b2g.test   operator de CAP Honduras
 *   qa.lector@crm-b2g.test     viewer   de CAP Honduras
 *   qa.adminb@crm-b2g.test     admin    de Empresa Demo B
 *
 * La contraseña es conocida, así que el seeder se niega a correr en producción.
 */
class QaSeeder extends Seeder
{
    use WithoutModelEvents;

    public const PASSWORD = 'QaB2g#2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('QaSeeder crea usuarios con una contraseña conocida: no se ejecuta en producción.');
        }

        $cap = Company::firstOrCreate(['name' => 'CAP Honduras'], ['code' => 'CAP', 'representative' => 'Gerente General', 'is_active' => true]);
        $demo = Company::firstOrCreate(['name' => 'Empresa Demo B'], ['code' => 'DEMOB', 'representative' => 'Representante Demo', 'is_active' => true]);

        $this->user('qa.root@crm-b2g.test', 'QA Raíz', root: true);
        $admin = $this->user('qa.admin@crm-b2g.test', 'QA Administradora', $cap, 'admin');
        $operator = $this->user('qa.operador@crm-b2g.test', 'QA Operador', $cap, 'operator');
        $this->user('qa.lector@crm-b2g.test', 'QA Lector', $cap, 'viewer');
        $adminB = $this->user('qa.adminb@crm-b2g.test', 'QA Administrador B', $demo, 'admin');

        $institutions = collect([
            ['name' => 'Secretaría de Infraestructura y Transporte', 'acronym' => 'SIT', 'type' => 'Secretaría de Estado', 'potential' => 'ALTO'],
            ['name' => 'Instituto Hondureño de Seguridad Social', 'acronym' => 'IHSS', 'type' => 'Instituto autónomo', 'potential' => 'ALTO'],
            ['name' => 'Alcaldía Municipal del Distrito Central', 'acronym' => 'AMDC', 'type' => 'Municipalidad', 'potential' => 'MEDIO'],
        ])->map(fn (array $data) => Institution::firstOrCreate(['name' => $data['name']], $data + ['status' => 'Activa']));

        $contacts = $institutions->map(fn (Institution $institution, int $index) => Contact::firstOrCreate(
            ['institution_id' => $institution->id, 'email' => "compras.qa{$index}@example.hn"],
            ['name' => ['Ana Lucía Mejía', 'Carlos Zelaya', 'Rosa Fúnez'][$index], 'position' => 'Jefatura de Adquisiciones', 'phone' => '2222-000'.$index],
        ));

        $this->seedCompany($cap, 'CAP', $operator, $admin, $institutions, $contacts);
        $this->seedCompany($demo, 'DMB', $adminB, $adminB, $institutions, $contacts);
    }

    private function user(string $email, string $name, ?Company $company = null, ?string $role = null, bool $root = false): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => self::PASSWORD, 'is_root' => $root, 'email_verified_at' => now()],
        );

        if ($company !== null) {
            $company->users()->syncWithoutDetaching([$user->id => ['role' => $role, 'is_default' => true]]);
        }

        return $user;
    }

    /**
     * Licitaciones, una oportunidad en cada etapa, cotizaciones y un contrato.
     *
     * @param  Collection<int, Institution>  $institutions
     * @param  Collection<int, Contact>  $contacts
     */
    private function seedCompany(Company $company, string $prefix, User $owner, User $manager, $institutions, $contacts): void
    {
        $year = now()->year;

        $tenders = collect([
            ['reference' => "LPN-{$prefix}-001-{$year}", 'title' => 'Adquisición de equipo de cómputo', 'purchase_type' => 'Licitación Pública Nacional', 'status' => 'Activa', 'estimated_amount' => 2500000, 'days' => 20, 'institution' => 0],
            ['reference' => "LPR-{$prefix}-002-{$year}", 'title' => 'Suministro de materiales de oficina', 'purchase_type' => 'Licitación Privada', 'status' => 'En preparación', 'estimated_amount' => 480000, 'days' => 9, 'institution' => 1],
            ['reference' => "CM-{$prefix}-003-{$year}", 'title' => 'Mantenimiento de aires acondicionados', 'purchase_type' => 'Compra Menor', 'status' => 'Presentada', 'estimated_amount' => 95000, 'days' => 3, 'institution' => 2],
        ])->map(fn (array $data) => Tender::updateOrCreate(
            ['company_id' => $company->id, 'reference' => $data['reference']],
            [
                'institution_id' => $institutions[$data['institution']]->id,
                'owner_id' => $owner->id,
                'title' => $data['title'],
                'process_name' => $data['title'],
                'purchase_type' => $data['purchase_type'],
                'status' => $data['status'],
                'estimated_amount' => $data['estimated_amount'],
                'published_at' => now()->subDays(5)->toDateString(),
                'clarification_deadline' => now()->addDays(max($data['days'] - 7, 1))->setTime(16, 0),
                'presentation_deadline' => now()->addDays($data['days'])->setTime(10, 0),
                'opening_at' => now()->addDays($data['days'])->setTime(10, 15),
                'closing_date' => now()->addDays($data['days'])->toDateString(),
                'offer_submitted' => $data['status'] === 'Presentada',
            ],
        ));

        $stages = [
            'prospecto' => ['Renovación de flota administrativa', 1800000, 20],
            'oportunidad' => ['Repuestos para ambulancias', 640000, 45],
            'oferta_presentada' => ['Mantenimiento de aires acondicionados', 95000, 60],
            'adjudicado' => ['Licencias de ofimática', 320000, 100],
            'no_adjudicado' => ['Mobiliario para oficinas regionales', 410000, 0],
        ];

        $opportunities = [];

        foreach (array_keys($stages) as $index => $stage) {
            [$title, $amount, $probability] = $stages[$stage];

            $opportunities[$stage] = Opportunity::updateOrCreate(
                ['company_id' => $company->id, 'title' => $title],
                [
                    'institution_id' => $institutions[$index % 3]->id,
                    'contact_id' => $contacts[$index % 3]->id,
                    'owner_id' => $owner->id,
                    'tender_id' => $stage === 'oferta_presentada' ? $tenders[2]->id : null,
                    'opportunity_type' => 'Licitación',
                    'amount' => $amount,
                    'probability' => $probability,
                    'stage' => $stage,
                    'expected_close_date' => now()->addDays(15 + $index * 10)->toDateString(),
                    'awarded_amount' => $stage === 'adjudicado' ? $amount : null,
                    'awarded_at' => $stage === 'adjudicado' ? now()->subDays(4)->toDateString() : null,
                    'winner_name' => $stage === 'no_adjudicado' ? 'Distribuidora Competidora S. A.' : null,
                    'lost_reason' => $stage === 'no_adjudicado' ? 'Precio más bajo del competidor' : null,
                ],
            );
        }

        $quotes = [
            'borrador' => $this->quote($company, "COT-QA-{$year}-0001", 'Borrador', 'Pendiente', $owner, $institutions[1], $contacts[1], $opportunities['oportunidad'], null, 640000),
            'presentada' => $this->quote($company, "COT-QA-{$year}-0002", 'Presentada', 'Pendiente', $owner, $institutions[2], $contacts[2], $opportunities['oferta_presentada'], $tenders[2], 95000),
            'ganada' => $this->quote($company, "COT-QA-{$year}-0003", 'Presentada', 'Ganada', $owner, $institutions[0], $contacts[0], $opportunities['adjudicado'], null, 320000),
        ];

        foreach ([['Nuestra oferta', 95000, true], ['Climatización del Norte S. de R. L.', 99500, false], ['Servicios Técnicos Unidos', 104000, false]] as $position => [$name, $amount, $ours]) {
            QuoteParticipant::updateOrCreate(
                ['quote_id' => $quotes['presentada']->id, 'name' => $name],
                ['amount' => $amount, 'currency' => 'HNL', 'includes_tax' => true, 'is_ours' => $ours, 'position' => $position + 1],
            );
        }

        Contract::updateOrCreate(
            ['company_id' => $company->id, 'number' => "CTR-{$prefix}-{$year}-001"],
            [
                'name' => 'Licencias de ofimática',
                'quote_id' => $quotes['ganada']->id,
                'opportunity_id' => $opportunities['adjudicado']->id,
                'institution_id' => $institutions[0]->id,
                'contact_id' => $contacts[0]->id,
                'manager_id' => $manager->id,
                'amount' => 320000,
                'awarded_amount' => 320000,
                'status' => 'Pendiente de contrato',
                'duration_days' => 30,
                'start_date' => now()->addDays(3)->toDateString(),
                'end_date' => now()->addDays(33)->toDateString(),
            ],
        );
    }

    private function quote(Company $company, string $number, string $status, string $outcome, User $author, Institution $institution, Contact $contact, Opportunity $opportunity, ?Tender $tender, float $total): Quote
    {
        $subtotal = round($total / 1.15, 2);

        $quote = Quote::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'number' => $number],
            [
                'institution_id' => $institution->id,
                'contact_id' => $contact->id,
                'opportunity_id' => $opportunity->id,
                'tender_id' => $tender?->id,
                'created_by' => $author->id,
                'prepared_by' => $author->name,
                'quoted_at' => now()->subDays(2)->toDateString(),
                'purchase_type' => $tender?->purchase_type ?? 'Licitación Privada',
                'validity_days' => 30,
                'status' => $status,
                'outcome' => $outcome,
                'awarded_amount' => $outcome === 'Ganada' ? $total : null,
                'awarded_at' => $outcome === 'Ganada' ? now()->subDays(4)->toDateString() : null,
                'subtotal' => $subtotal,
                'tax' => round($total - $subtotal, 2),
                'total' => $total,
                'notes' => 'Dato de QA.',
            ],
        );

        if ($quote->items()->count() === 0) {
            $quote->items()->create([
                'description' => $opportunity->title,
                'quantity' => 1,
                'unit' => 'Global',
                'unit_cost' => round($subtotal * 0.8, 2),
                'margin' => 20,
                'unit_price' => $subtotal,
                'total' => $subtotal,
            ]);
        }

        if ($opportunity->quote_id !== $quote->id) {
            $opportunity->forceFill(['quote_id' => $quote->id])->save();
        }

        return $quote;
    }
}
