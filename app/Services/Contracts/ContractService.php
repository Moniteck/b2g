<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractFollowUp;
use App\Models\User;
use App\Services\Documents\DocumentService;
use App\Services\System\AuditLogger;
use App\Services\Tenancy\CompanyContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones del contrato post-adjudicación. Cada una corre en una transacción con el contrato
 * bloqueado y deja su rastro en la auditoría. Los eventos del calendario y sus recordatorios
 * los genera `CalendarSourceObserver` al guardar (después del commit).
 */
class ContractService
{
    public function __construct(
        protected AuditLogger $audit,
        protected ContractTerm $term,
        protected DocumentService $documents,
    ) {}

    /**
     * Crea o actualiza el contrato con todos sus datos y recalcula la fecha fin desde el plazo
     * (salvo que esté fijada a mano).
     *
     * @param  array<string, mixed>  $data
     */
    public function save(?Contract $contract, array $data, User $actor): Contract
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $creating = $contract === null;
            $contract = $creating ? new Contract : Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();

            if (! array_key_exists('amount', $data) || $data['amount'] === null) {
                $data['amount'] = $data['awarded_amount'] ?? $contract->amount ?? 0;
            }

            $contract->fill(Arr::except($data, ['status']));

            if ($creating) {
                // La empresa hace falta antes de guardar: el plazo en hábiles usa sus feriados.
                $contract->company_id ??= app(CompanyContext::class)->currentCompanyId();
                $contract->status = $data['status'] ?? ContractStatus::PENDING;
                $contract->created_by = $actor->id;
                $contract->manager_id ??= $actor->id;
            }

            $this->applyTerm($contract);

            if ($contract->status === ContractStatus::DELIVERED) {
                $contract->product_delivered = true;
            }

            $contract->updated_by = $actor->id;
            $changes = $creating ? Arr::only($contract->getAttributes(), ['number', 'name', 'status', 'awarded_amount', 'amount', 'start_date', 'end_date', 'manager_id']) : $this->changes($contract);
            $contract->save();

            if ($creating || $changes !== []) {
                $this->audit->record($creating ? 'contrato.creado' : 'contrato.actualizado', $contract, $changes, $actor);
            }

            return $contract;
        });
    }

    /**
     * Inicio por hito y fin desde el plazo. Una fecha fin puesta a mano nunca se pisa.
     */
    public function applyTerm(Contract $contract): void
    {
        if ($contract->start_date === null && $contract->start_trigger === 'Firma del contrato' && $contract->signed_at !== null) {
            $contract->start_date = $contract->signed_at;
        }

        if ($contract->is_end_date_manual) {
            return;
        }

        $end = $contract->calculateEndDate();
        if ($end !== null) {
            $contract->end_date = $end;
        }
    }

    /**
     * Cambia el estado según las transiciones de `ContractStatus`.
     *
     * @throws ValidationException
     */
    public function changeStatus(Contract $contract, string $to, User $actor, ?string $reason = null): Contract
    {
        return DB::transaction(function () use ($contract, $to, $actor, $reason) {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            if (! ContractStatus::allows($from, $to)) {
                throw ValidationException::withMessages(['status' => "Un contrato en «{$from}» no puede pasar a «{$to}»."]);
            }

            if (ContractStatus::requiresAdmin($from, $to) && ! $actor->can('cancel', $locked)) {
                throw ValidationException::withMessages(['status' => 'Solo un administrador de la empresa puede cerrar, cancelar o reabrir un contrato.']);
            }

            if ($to === ContractStatus::CLOSED && ! $locked->product_delivered && $from !== ContractStatus::DELIVERED) {
                throw ValidationException::withMessages(['status' => 'Marque el producto como entregado antes de cerrar el contrato.']);
            }

            $locked->status = $to;
            $locked->updated_by = $actor->id;

            match ($to) {
                ContractStatus::DELIVERED => $locked->product_delivered = true,
                ContractStatus::CLOSED => $locked->forceFill([
                    'product_delivered' => true,
                    'physical_progress' => 100,
                    'closed_at' => now(),
                ]),
                default => null,
            };

            if ($from === ContractStatus::CLOSED) {
                $locked->forceFill(['closed_at' => null]);
            }

            $locked->save();

            $this->audit->record($to === ContractStatus::CLOSED ? 'contrato.cerrado' : 'contrato.estado', $locked, array_filter([
                'estado_anterior' => $from,
                'estado' => $to,
                'motivo' => $reason,
            ]), $actor);

            return $locked;
        });
    }

    /**
     * Cerrar: exige producto entregado. Repetir sobre un contrato ya cerrado no hace nada.
     *
     * @throws ValidationException
     */
    public function close(Contract $contract, User $actor, ?string $reason = null): Contract
    {
        return $this->changeStatus($contract, ContractStatus::CLOSED, $actor, $reason);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDelivery(Contract $contract, ?ContractDelivery $delivery, array $data, User $actor): ContractDelivery
    {
        return DB::transaction(function () use ($contract, $delivery, $data, $actor) {
            Contract::query()->whereKey($contract->id)->lockForUpdate()->first();
            $creating = $delivery === null;
            $delivery ??= new ContractDelivery(['contract_id' => $contract->id, 'created_by' => $actor->id]);

            $delivery->fill(Arr::only($data, ['description', 'quantity', 'unit', 'due_date', 'status', 'delivered_at', 'evidence_document_id', 'notes']));
            $delivery->status ??= ContractDelivery::PENDING;

            // Entregada sin fecha real: hoy. Volver a pendiente quita la fecha real.
            if ($delivery->isDelivered() && $delivery->delivered_at === null) {
                $delivery->delivered_at = now();
            }
            if ($delivery->status === ContractDelivery::PENDING && ! array_key_exists('delivered_at', $data)) {
                $delivery->delivered_at = null;
            }

            $delivery->updated_by = $actor->id;
            $changes = $creating ? [] : $this->changes($delivery);
            $delivery->save();

            $this->audit->record($creating ? 'contrato.entrega.creada' : 'contrato.entrega.actualizada', $contract, [
                'entrega' => $delivery->description,
                'estado' => $delivery->status,
            ] + ($changes !== [] ? ['cambios' => $changes] : []), $actor);

            return $delivery;
        });
    }

    /**
     * Marca la entrega como entregada y guarda la evidencia en los documentos del contrato.
     *
     * @throws ValidationException
     */
    public function markDelivered(Contract $contract, ContractDelivery $delivery, mixed $deliveredAt, ?string $notes, ?UploadedFile $evidence, User $actor): ContractDelivery
    {
        $documentId = null;

        if ($evidence !== null) {
            $document = $this->documents->create(
                ['name' => "Evidencia de entrega: {$delivery->description}", 'kind' => 'contrato', 'category' => 'Acta de recepción'],
                $evidence,
                $actor,
                $contract,
                ['category' => 'Acta de recepción', 'description' => "Entrega: {$delivery->description}", 'field' => 'evidence'],
            );
            $documentId = $document->id;
        }

        return DB::transaction(function () use ($contract, $delivery, $deliveredAt, $notes, $documentId, $actor) {
            $locked = ContractDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => ContractDelivery::DELIVERED,
                'delivered_at' => $deliveredAt,
                'evidence_document_id' => $documentId ?? $locked->evidence_document_id,
                'notes' => $notes ?? $locked->notes,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record('contrato.entrega.entregada', $contract, array_filter([
                'entrega' => $locked->description,
                'fecha_real' => $locked->delivered_at?->toDateString(),
                'evidencia' => $documentId,
            ]), $actor);

            return $locked;
        });
    }

    public function deleteDelivery(Contract $contract, ContractDelivery $delivery, User $actor): void
    {
        DB::transaction(function () use ($contract, $delivery, $actor) {
            $delivery->delete();
            $this->audit->record('contrato.entrega.eliminada', $contract, ['entrega' => $delivery->description], $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addFollowUp(Contract $contract, array $data, User $actor): ContractFollowUp
    {
        return DB::transaction(function () use ($contract, $data, $actor) {
            $followUp = $contract->followUps()->create([
                'user_id' => $actor->id,
                'type' => $data['type'] ?? 'Seguimiento',
                'description' => $data['description'],
                'occurred_at' => $data['occurred_at'] ?? now(),
                'next_action' => $data['next_action'] ?? null,
                'next_action_at' => $data['next_action_at'] ?? null,
            ]);

            $this->audit->record('contrato.seguimiento.creado', $contract, array_filter([
                'tipo' => $followUp->type,
                'proxima_accion' => $followUp->next_action,
                'fecha_proxima_accion' => $followUp->next_action_at?->toDateString(),
            ]), $actor);

            return $followUp;
        });
    }

    public function deleteFollowUp(Contract $contract, ContractFollowUp $followUp, User $actor): void
    {
        DB::transaction(function () use ($contract, $followUp, $actor) {
            $followUp->delete();
            $this->audit->record('contrato.seguimiento.eliminado', $contract, ['tipo' => $followUp->type, 'nota' => mb_substr($followUp->description, 0, 120)], $actor);
        });
    }

    /**
     * ¿Todas las entregas esperadas están entregadas? (para proponer «Producto entregado»)
     */
    public static function allDelivered(Contract $contract): bool
    {
        $counted = $contract->deliveries()->where('status', '!=', ContractDelivery::CANCELLED)->get(['id', 'status']);

        return $counted->isNotEmpty() && $counted->every(fn (ContractDelivery $delivery) => $delivery->isDelivered());
    }

    /**
     * Campos cambiados con su valor anterior y nuevo, sin columnas técnicas.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function changes(object $model): array
    {
        $changes = [];
        foreach (Arr::except($model->getDirty(), ['updated_by', 'updated_at']) as $key => $value) {
            $original = $model->getOriginal($key);
            $changes[$key] = [$original instanceof \DateTimeInterface ? $original->format('Y-m-d') : $original, $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value];
        }

        return $changes;
    }
}
