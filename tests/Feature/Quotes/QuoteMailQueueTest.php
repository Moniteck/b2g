<?php

namespace Tests\Feature\Quotes;

use App\Mail\QuoteMail;
use Illuminate\Contracts\Database\ModelIdentifier;

/**
 * El correo de cotización va en cola: la cotización viaja como referencia (no el modelo
 * con sus líneas) y se vuelve a leer en el worker, que no tiene empresa activa.
 */
class QuoteMailQueueTest extends QuotesTestCase
{
    public function test_quote_travels_as_model_identifier_and_restores_without_company_context(): void
    {
        $quote = $this->quote(['number' => 'COT-FIN-0001']);
        $quote->load('items', 'company');

        $payload = serialize(new QuoteMail($quote, 'Adjuntamos nuestra oferta.'));

        $this->assertStringContainsString(ModelIdentifier::class, $payload);
        $this->assertStringNotContainsString('"subtotal"', $payload);

        /** @var QuoteMail $restored */
        $restored = unserialize($payload);
        $this->assertSame($quote->id, $restored->quote->id);
        $this->assertSame('COT-FIN-0001', $restored->quote->number);
    }
}
