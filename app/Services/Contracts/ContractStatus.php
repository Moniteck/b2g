<?php

namespace App\Services\Contracts;

/**
 * Estados del contrato y sus transiciones: el único lugar con estas reglas.
 *
 * - Cerrar exige producto entregado (desde En ejecución, Entrega parcial o Producto entregado).
 * - Cerrar, cancelar y reabrir un cerrado son decisiones del administrador.
 * - Cerrado y Cancelado son finales (un cerrado solo se reabre a «Producto entregado»).
 */
final class ContractStatus
{
    public const PENDING = 'Pendiente de contrato';

    public const IN_PROGRESS = 'En ejecución';

    public const PARTIAL = 'Entrega parcial';

    public const DELIVERED = 'Producto entregado';

    public const CLOSED = 'Cerrado';

    public const SUSPENDED = 'Suspendido';

    public const CANCELLED = 'Cancelado';

    public const ALL = [self::PENDING, self::IN_PROGRESS, self::PARTIAL, self::DELIVERED, self::CLOSED, self::SUSPENDED, self::CANCELLED];

    /** Contratos vivos (cuentan en cifras y vencimientos). */
    public const OPEN = [self::PENDING, self::IN_PROGRESS, self::PARTIAL, self::DELIVERED, self::SUSPENDED];

    /** Coinciden con `CalendarSyncService::CLOSED_CONTRACT_STATUSES`. */
    public const FINAL = [self::CLOSED, self::CANCELLED];

    /** Solo el administrador lleva un contrato a estos estados. */
    public const ADMIN_TARGETS = [self::CLOSED, self::CANCELLED];

    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        self::PENDING => [self::IN_PROGRESS, self::SUSPENDED, self::CANCELLED],
        self::IN_PROGRESS => [self::PARTIAL, self::DELIVERED, self::CLOSED, self::SUSPENDED, self::CANCELLED],
        self::PARTIAL => [self::IN_PROGRESS, self::DELIVERED, self::CLOSED, self::SUSPENDED, self::CANCELLED],
        self::DELIVERED => [self::CLOSED, self::PARTIAL, self::IN_PROGRESS, self::CANCELLED],
        self::SUSPENDED => [self::PENDING, self::IN_PROGRESS, self::PARTIAL, self::CANCELLED],
        self::CLOSED => [self::DELIVERED],
        self::CANCELLED => [],
    ];

    /** Semántica del distintivo en pantalla. */
    public const TONES = [
        self::PENDING => 'warning',
        self::IN_PROGRESS => 'info',
        self::PARTIAL => 'warning',
        self::DELIVERED => 'ok',
        self::CLOSED => 'neutral',
        self::SUSPENDED => 'warning',
        self::CANCELLED => 'error',
    ];

    /** Grupos del segmentado de la lista. */
    public const GROUPS = [
        'activos' => self::OPEN,
        'pendientes' => [self::PENDING],
        'ejecucion' => [self::IN_PROGRESS, self::PARTIAL, self::SUSPENDED],
        'entregados' => [self::DELIVERED],
        'finalizados' => self::FINAL,
    ];

    /** @return array<int, string> */
    public static function next(?string $from): array
    {
        return self::TRANSITIONS[$from] ?? [self::PENDING, self::IN_PROGRESS];
    }

    public static function allows(?string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::next($from), true);
    }

    public static function requiresAdmin(?string $from, string $to): bool
    {
        return in_array($to, self::ADMIN_TARGETS, true) || $from === self::CLOSED;
    }

    public static function isOpen(?string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }
}
