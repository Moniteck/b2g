<?php

namespace Tests\Feature\Database;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Quote;
use App\Models\QuoteParticipant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\QaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['users', 'companies', 'company_user', 'institutions', 'contacts', 'tenders', 'opportunities', 'quotes', 'quote_items', 'quote_participants', 'clients', 'contracts', 'contract_deliveries', 'documents'];

    public function test_qa_seeder_is_idempotent_and_uses_current_states(): void
    {
        $this->seed(QaSeeder::class);
        $first = $this->counts();
        $this->seed(QaSeeder::class);

        $this->assertSame($first, $this->counts());
        $this->assertSame(5, User::query()->where('email', 'like', 'qa.%')->count());

        $running = Contract::query()->withoutGlobalScopes()->where('number', 'like', 'CTR-CAP-%')->sole();
        $this->assertSame('En ejecución', $running->status);
        $this->assertNotNull($running->client_id);
        $this->assertNotNull($running->start_date);
        $this->assertSame(['Entregada', 'Pendiente'], ContractDelivery::query()->where('contract_id', $running->id)->orderBy('description')->pluck('status')->all());

        $this->assertSame(0, QuoteParticipant::query()->whereNull('opportunity_id')->count());
        $this->assertSame(1, QuoteParticipant::query()->where('opportunity_id', $running->opportunity_id)->where('is_ours', true)->count());

        $quote = Quote::query()->withoutGlobalScopes()->findOrFail($running->quote_id);
        $this->assertEqualsWithDelta((float) $quote->total, (float) $quote->items()->sum('total'), 0.01);
    }

    public function test_database_seeder_creates_the_demo_user_only_in_local(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'admin@crm-b2g.test']);
        $this->assertDatabaseHas('companies', ['name' => 'CAP Honduras']);

        $this->app['env'] = 'local';
        $this->seed(DatabaseSeeder::class);
        $first = $this->counts();
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, $this->counts());
        $this->assertDatabaseHas('users', ['email' => 'admin@crm-b2g.test']);
        $this->assertDatabaseHas('documents', ['name' => 'Constancia SAR', 'category' => 'solvencia_sar']);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }
}
