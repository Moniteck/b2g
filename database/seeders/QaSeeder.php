<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Institution;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Models\User;
use App\Services\Contracts\ContractStatus;
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
 * Cada empresa queda con licitaciones, una oportunidad por etapa del pipeline, cotizaciones
 * (Borrador, Presentada con participantes y Ganada con su cliente) y un contrato: en CAP Honduras
 * «En ejecución» con una entrega Entregada y otra Pendiente; en Empresa Demo B «Pendiente de contrato».
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

        // Participantes del proceso: van por oportunidad (y cotización si la hay); «nuestra oferta» es una sola fila.
        $this->participants($company, $quotes['presentada'], $opportunities['oferta_presentada'], 95000, [
            ['Climatización del Norte S. de R. L.', 99500, false],
            ['Servicios Técnicos Unidos', 104000, false],
        ]);
        $this->participants($company, $quotes['ganada'], $opportunities['adjudicado'], 320000, [
            ['Soluciones Informáticas Catrachas', 338000, false],
        ], oursWon: true);
        $this->participants($company, null, $opportunities['no_adjudicado'], 432000, [
            ['Distribuidora Competidora S. A.', 410000, true],
        ]);

        // Cliente que deja la adjudicación (como lo crea AdjudicationService).
        $client = Client::withTrashed()->updateOrCreate(
            ['company_id' => $company->id, 'dedupe_key' => Client::dedupeKeyFor($company->id, $institutions[0]->id, $institutions[0]->name)],
            [
                'institution_id' => $institutions[0]->id,
                'source_quote_id' => $quotes['ganada']->id,
                'origin' => 'Adjudicacion',
                'name' => $institutions[0]->name,
                'type' => 'Publico',
                'sector' => $institutions[0]->type,
                'contact_name' => $contacts[0]->name,
                'phone' => $contacts[0]->phone,
                'email' => $contacts[0]->email,
                'status' => 'Activo',
                'accumulated_amount' => 320000,
                'last_purchase_at' => now()->subDays(4)->toDateString(),
                'notes' => 'Dato de QA.',
            ],
        );
        if ($client->trashed()) {
            $client->restore();
        }
        $quotes['ganada']->forceFill(['client_id' => $client->id, 'winner_name' => $company->name])->save();
        $opportunities['adjudicado']->forceFill(['winner_name' => $company->name])->save();

        $running = $prefix === 'CAP';

        $contract = Contract::updateOrCreate(
            ['company_id' => $company->id, 'number' => "CTR-{$prefix}-{$year}-001"],
            [
                'name' => 'Licencias de ofimática',
                'client_id' => $client->id,
                'quote_id' => $quotes['ganada']->id,
                'opportunity_id' => $opportunities['adjudicado']->id,
                'institution_id' => $institutions[0]->id,
                'contact_id' => $contacts[0]->id,
                'manager_id' => $manager->id,
                'amount' => 320000,
                'awarded_amount' => 320000,
                'status' => $running ? ContractStatus::IN_PROGRESS : ContractStatus::PENDING,
                'signed_at' => $running ? now()->subDays(3)->toDateString() : null,
                'start_trigger' => 'Orden de inicio',
                'duration_days' => 30,
                'duration_business_days' => false,
                'start_date' => $running ? now()->subDays(2)->toDateString() : now()->addDays(3)->toDateString(),
                'end_date' => $running ? now()->addDays(28)->toDateString() : now()->addDays(33)->toDateString(),
                'billed_amount' => 0,
                'collected_amount' => 0,
                'retention_amount' => 0,
            ],
        );

        if ($running) {
            ContractDelivery::updateOrCreate(
                ['contract_id' => $contract->id, 'description' => 'Primera entrega: 50 licencias'],
                ['quantity' => 50, 'unit' => 'Licencia', 'due_date' => now()->subDay()->toDateString(), 'delivered_at' => now()->subDay()->toDateString(), 'status' => ContractDelivery::DELIVERED, 'created_by' => $manager->id],
            );
            ContractDelivery::updateOrCreate(
                ['contract_id' => $contract->id, 'description' => 'Segunda entrega: 50 licencias'],
                ['quantity' => 50, 'unit' => 'Licencia', 'due_date' => now()->addDays(20)->toDateString(), 'delivered_at' => null, 'status' => ContractDelivery::PENDING, 'created_by' => $manager->id],
            );
        }
    }

    /**
     * «Nuestra oferta» y los competidores de un proceso, por oportunidad (y cotización si existe).
     *
     * @param  array<int, array{0: string, 1: float, 2: bool}>  $rivals  [nombre, monto, ganó]
     */
    private function participants(Company $company, ?Quote $quote, Opportunity $opportunity, float $ourAmount, array $rivals, bool $oursWon = false): void
    {
        $rows = [[$company->name, $ourAmount, $oursWon, true], ...array_map(fn (array $rival) => [$rival[0], $rival[1], $rival[2], false], $rivals)];
        usort($rows, fn (array $a, array $b) => $a[1] <=> $b[1]);

        foreach ($rows as $position => [$name, $amount, $winner, $ours]) {
            QuoteParticipant::updateOrCreate(
                $ours ? ['opportunity_id' => $opportunity->id, 'is_ours' => true] : ['opportunity_id' => $opportunity->id, 'name' => $name],
                [
                    'quote_id' => $quote?->id,
                    'name' => $name,
                    'tax_id' => $ours ? $company->tax_id : null,
                    'amount' => $amount,
                    'currency' => 'HNL',
                    'includes_tax' => true,
                    'is_ours' => $ours,
                    'is_winner' => $winner,
                    'position' => $position + 1,
                ],
            );
        }
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
                'discount' => 0,
                'tax_included' => false,
                'total' => $total,
                'notes' => 'Dato de QA.',
            ],
        );

        if ($quote->trashed()) {
            $quote->restore();
        }

        // Una línea a precio sin ISV (15 %), con las columnas de la regla única de precios.
        $quote->items()->updateOrCreate(
            ['position' => 1],
            [
                'description' => $opportunity->title,
                'quantity' => 1,
                'unit' => 'Global',
                'unit_cost' => round($subtotal * 0.8, 2),
                'margin' => 25,
                'tax_rate' => 15,
                'tax_included' => false,
                'pricing_source' => 'calculadora',
                'unit_price' => $subtotal,
                'discount_percent' => 0,
                'subtotal' => $subtotal,
                'tax' => round($total - $subtotal, 2),
                'total' => $total,
            ],
        );

        if ($opportunity->quote_id !== $quote->id) {
            $opportunity->forceFill(['quote_id' => $quote->id])->save();
        }

        return $quote;
    }
}
