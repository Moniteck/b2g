<p>Adjuntamos la cotización <strong>{{ $quote->number }}</strong> para {{ $quote->institution?->name }}.</p>
<p>Vigencia: {{ $quote->validity_days }} días. Total: L {{ number_format($quote->total, 2) }}.</p>
