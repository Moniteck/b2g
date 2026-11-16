<?php

namespace Tests\Feature\Database;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Event;
use App\Models\Institution;
use App\Models\InventoryItem;
use App\Models\Opportunity;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\QuoteParticipant;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function modelProvider(): array
    {
        return [
            'User' => [User::class],
            'Company' => [Company::class],
            'Institution' => [Institution::class],
            'Contact' => [Contact::class],
            'Tender' => [Tender::class],
            'Opportunity' => [Opportunity::class],
            'Quote' => [Quote::class],
            'QuoteItem' => [QuoteItem::class],
            'QuoteParticipant' => [QuoteParticipant::class],
            'Client' => [Client::class],
            'Document' => [Document::class],
            'DocumentVersion' => [DocumentVersion::class],
            'Contract' => [Contract::class],
            'ContractDelivery' => [ContractDelivery::class],
            'ContractFollowUp' => [ContractFollowUp::class],
            'Event' => [Event::class],
            'InventoryItem' => [InventoryItem::class],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('modelProvider')]
    public function test_factory_persists_the_model(string $model): void
    {
        // Dos registros: detecta colisiones en las columnas únicas.
        $records = $model::factory()->count(2)->create();

        $this->assertCount(2, $records);
        $records->each(fn ($record) => $this->assertModelExists($record));
    }

    public function test_company_scoped_factories_create_their_company(): void
    {
        foreach ([Tender::class, Opportunity::class, Quote::class, Client::class, Document::class, Contract::class, Event::class, InventoryItem::class] as $model) {
            $record = $model::factory()->create();

            $this->assertNotNull($record->company_id, "{$model} sin empresa");
            $this->assertTrue($record->company->is($record->company()->first()));
        }

        $company = Company::factory()->create();
        $quote = Quote::factory()->for($company)->create();

        $this->assertSame($company->id, $quote->company_id);
    }

    public function test_child_factories_create_their_parent(): void
    {
        $this->assertNotNull(Contact::factory()->create()->institution);
        $this->assertNotNull(QuoteItem::factory()->create()->quote);
        $this->assertNotNull(QuoteParticipant::factory()->create()->quote);
        $this->assertNotNull(DocumentVersion::factory()->create()->document);
        $this->assertNotNull(ContractDelivery::factory()->create()->contract);

        $followUp = ContractFollowUp::factory()->create();
        $this->assertNotNull($followUp->contract);
        $this->assertNotNull($followUp->user);
    }

    public function test_user_factory_defaults_and_root_state(): void
    {
        $user = User::factory()->create();

        $this->assertSame('operator', $user->role);
        $this->assertFalse((bool) $user->is_root);
        $this->assertTrue((bool) User::factory()->root()->create()->refresh()->is_root);
    }

    public function test_company_factory_attaches_user(): void
    {
        $user = User::factory()->create();

        $company = Company::factory()->withUser($user)->create();
        $pivot = $company->users()->first()->pivot;

        $this->assertSame('operator', $pivot->role);
        $this->assertTrue((bool) $pivot->is_default);

        $other = Company::factory()->withUser($user, 'admin', false)->create();
        $pivot = $other->users()->first()->pivot;

        $this->assertSame('admin', $pivot->role);
        $this->assertFalse((bool) $pivot->is_default);
        $this->assertDatabaseCount('company_user', 2);
    }

    public function test_default_states_are_coherent(): void
    {
        $quote = Quote::factory()->create();
        $this->assertSame('Borrador', $quote->status);
        $this->assertSame('Pendiente', $quote->outcome);
        $this->assertTrue($quote->isEditable());

        $this->assertSame('prospecto', Opportunity::factory()->create()->stage);
        $this->assertSame('Pendiente de contrato', Contract::factory()->create()->status);

        $event = Event::factory()->create();
        $this->assertSame('Manual', $event->type);
        $this->assertFalse($event->isDerived());

        $document = Document::factory()->create();
        $this->assertSame('empresa', $document->kind);
        $this->assertSame('Vigente', $document->status);
        $this->assertSame('local', $document->disk);
        $this->assertFalse($document->isExpired());

        $this->assertFalse(QuoteParticipant::factory()->create()->is_ours);
    }

    public function test_tender_factory_uses_future_deadlines(): void
    {
        $tender = Tender::factory()->create()->refresh();

        $this->assertSame('Activa', $tender->status);
        $this->assertTrue($tender->clarification_deadline->isFuture());
        $this->assertTrue($tender->presentation_deadline->isFuture());
        $this->assertTrue($tender->opening_at->isFuture());
        $this->assertTrue($tender->clarification_deadline->lt($tender->presentation_deadline));
        $this->assertTrue($tender->presentation_deadline->lte($tender->opening_at));

        $this->assertTrue(Tender::factory()->expired()->create()->presentation_deadline->isPast());
        $this->assertSame('Adjudicada', Tender::factory()->status('Adjudicada')->create()->status);
    }

    public function test_opportunity_stage_state(): void
    {
        foreach (array_keys(Opportunity::STAGES) as $stage) {
            $this->assertSame($stage, Opportunity::factory()->stage($stage)->create()->stage);
        }
    }

    public function test_quote_states(): void
    {
        $this->assertSame('Emitida', Quote::factory()->status('Emitida')->create()->status);

        $won = Quote::factory()->won()->create()->refresh();
        $this->assertTrue($won->isWon());
        $this->assertSame('Presentada', $won->status);
        $this->assertSame($won->total, $won->awarded_amount);
        $this->assertNotNull($won->awarded_at);

        $lost = Quote::factory()->lost()->create()->refresh();
        $this->assertTrue($lost->isLost());
        $this->assertNotNull($lost->lost_reason);
        $this->assertNotNull($lost->winner_name);
    }

    public function test_remaining_states(): void
    {
        $this->assertTrue(QuoteParticipant::factory()->ours()->create()->is_ours);
        $this->assertFalse(Company::factory()->inactive()->create()->is_active);
        $this->assertSame('proceso', Document::factory()->kind('proceso')->create()->kind);
        $this->assertTrue(Document::factory()->expired()->create()->isExpired());
        $this->assertSame('Cerrado', Contract::factory()->status('Cerrado')->create()->status);
        $this->assertNotNull(ContractDelivery::factory()->delivered()->create()->delivered_at);
        $this->assertSame('Entrega', Event::factory()->type('Entrega')->create()->type);
        $this->assertTrue(Event::factory()->completed()->create()->completed);
    }
}
