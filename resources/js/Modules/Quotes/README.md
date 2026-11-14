# Módulo de cotizaciones: piezas reutilizables

Pantalla: `Pages/Quotes/Index.vue` (`/cotizaciones`). Servidor: `QuoteController`,
`AdjudicationController`, `QuoteExportController`, `App\Services\Business\QuoteService` y
`App\Services\Business\AdjudicationService`.

Un **sujeto** es `{ type: 'quote' | 'opportunity', id }`. El resultado comercial y los participantes
se registran igual sobre una cotización o sobre una oportunidad (kanban, decisión 13).

## Componentes

| Componente | Uso |
|---|---|
| `ParticipantsEditor.vue` | Lista editable de oferentes. Props `subject`, `showActions` (botón propio de guardar), `compact`. Eventos `saved`, `loaded`, `dirty`. Expone `save()`, `reload()`, `saving`, `dirty`, `canEdit`. |
| `ParticipantsModal.vue` | La misma lista dentro de una `BaseModal`: `<ParticipantsModal v-model:open="open" :subject="{ type: 'opportunity', id }" />`. |
| `WonModal.vue` | Ganada. `subject` lleva además `label`, `amount` (monto por defecto) y `quoteId` (opcional). Si el sujeto es una oportunidad sin `quoteId` y tiene varias cotizaciones vivas, el modal las lee de `GET /pipeline/{id}` y pide elegir una. Emite `done(result)` y muestra los enlaces al cliente y al contrato. |
| `LostModal.vue` | Perdida: quién ganó (participante o escrito), valor de su oferta y motivo. Emite `done(result, message)`. |
| `RevertModal.vue` | Revertir resultado (solo administrador; `subject.outcome` decide el texto). |
| `QuoteViewerModal.vue`, `QuoteFormModal.vue`, `StatusModal.vue`, `MailModal.vue` | Propias de la pantalla de cotizaciones. |

```vue
<WonModal v-model:open="wonOpen" :subject="{ type: 'opportunity', id: card.id, label: card.title, amount: card.amount }" @done="router.reload({ only: ['board'] })" />
```

## Endpoints (JSON)

| Método y ruta | Cuerpo | Respuesta |
|---|---|---|
| `POST /cotizaciones/{id}/ganada` · `/oportunidades/{id}/ganada` | `awarded_amount`, `awarded_at`, `quote_id?` | `{ message, result: { outcome, already_applied, quote, opportunity, client: { id, name, url }, contract: { id, number, status, url } } }` |
| `POST …/perdida` | `winner_participant_id` o `winner_name`, `winner_amount`, `winner_tax_id?`, `lost_reason?`, `awarded_at?`, `quote_id?` | ídem |
| `POST …/revertir` | `reason` (mín. 5) | ídem (solo administrador) |
| `GET …/participantes` | — | `{ data: [...], summary, outcome, reference_amount, can: { edit } }` |
| `PUT …/participantes` | `participants: [{ id?, name, tax_id?, amount, currency?, includes_tax?, is_ours?, is_winner?, notes? }]` | ídem + `message` |

Errores de negocio: 422 con `errors.outcome` (ya tiene resultado, estado no adjudicable, contrato con
actividad), `errors.quote_id` (oportunidad con varias cotizaciones vivas) o el campo que falta.
Repetir Ganada o Perdida responde 200 con `already_applied: true` sin duplicar nada.

## Servidor

```php
app(AdjudicationService::class)->win($opportunityOrQuote, ['awarded_amount' => 1000, 'awarded_at' => '2026-11-14'], $user);
app(AdjudicationService::class)->lose($subject, ['winner_name' => 'X', 'winner_amount' => 900], $user);
app(AdjudicationService::class)->revert($subject, 'Motivo', $user);
app(AdjudicationService::class)->saveParticipants($subject, $rows, $user);
app(AdjudicationService::class)->revertBlocker($subject); // null o el motivo
```

`QuoteService::changeStatus($quote, 'Presentada', $user)` crea «nuestra oferta» entre los participantes
y mueve la oportunidad a Oferta Presentada.
