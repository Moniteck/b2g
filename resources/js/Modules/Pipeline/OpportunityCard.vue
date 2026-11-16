<script setup>
// Contenido de una tarjeta del tablero (slot `card` de CapKanban). Las clases `cap-kanban-card__*`
// son las de la receta. La fila superior lleva el código: el tablero la lee al anunciar movimientos.
// Props: card (de OpportunityController::card), menu (ítems de DropdownMenu), showParticipants.
// Eventos: open (ver detalle), participants.
import { computed } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import { date, money } from '@/lib/format.js';
import { isClosed, keyDateBadge } from '@/Modules/Pipeline/pipeline.js';

const props = defineProps({
    card: { type: Object, required: true },
    menu: { type: Array, default: () => [] },
    showParticipants: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'participants']);

const badge = computed(() => keyDateBadge(props.card));
const closed = computed(() => isClosed(props.card.stage));
const amount = computed(() => (props.card.stage === 'adjudicado' && props.card.awardedAmount !== null ? props.card.awardedAmount : props.card.amount));
</script>

<template>
    <div class="cap-kanban-card__top">
        <span class="cap-kanban-card__priority" role="img" :aria-label="`Probabilidad ${card.probability ?? 0} %`" :data-level="card.probability >= 70 ? 'high' : card.probability >= 40 ? 'medium' : undefined" />
        <span class="whitespace-nowrap">{{ card.code }}</span>
        <span v-if="card.probability !== null && card.probability !== undefined && !closed" class="whitespace-nowrap">· {{ card.probability }} %</span>
        <DropdownMenu class="ml-auto" :label="`Acciones de ${card.title}`" :items="menu" width="w-60">
            <template #trigger="{ toggle, attrs }">
                <button type="button" class="cap-kanban-card__menu" v-bind="attrs" :aria-label="`Acciones de ${card.title}`" @click.stop="toggle" @keydown.enter.space.stop>
                    <i class="mdi mdi-dots-vertical" aria-hidden="true" />
                </button>
            </template>
        </DropdownMenu>
    </div>

    <button type="button" class="cap-kanban-card__title rounded-sm text-left hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cap-brand" data-card-title @click.stop="emit('open')" @keydown.enter.space.stop>
        {{ card.title }}
    </button>

    <p class="-mt-1 truncate text-xs text-cap-text-soft" :title="card.institution?.name">{{ card.institution?.name ?? 'Sin institución' }}</p>
    <p v-if="card.keyDate" class="-mt-1 truncate text-xs text-cap-text-soft">{{ card.keyDate.label }}: {{ date(card.keyDate.date) }}</p>

    <div class="cap-kanban-card__row">
        <span class="cap-kanban-card__amount text-gray-900 dark:text-gray-100">{{ money(amount) }}</span>
        <span v-if="badge" class="cap-kanban-card__badge" :data-status="badge.status">
            <i :class="['mdi', `mdi-${badge.icon}`]" aria-hidden="true" />{{ badge.text }}
        </span>
    </div>

    <div v-if="card.stage === 'no_adjudicado' && card.winner" class="rounded-cap-control bg-cap-card-2 px-2.5 py-2 text-xs" data-card-winner>
        <p class="text-cap-text-soft">Ganó</p>
        <p class="truncate font-semibold text-gray-900 dark:text-gray-100" :title="card.winner.name">{{ card.winner.name || '—' }}</p>
        <p class="tabular-nums text-gray-700 dark:text-gray-300">{{ card.winner.amount !== null ? money(card.winner.amount) : 'Monto sin registrar' }}</p>
    </div>

    <div class="cap-kanban-card__foot flex-wrap">
        <span v-if="card.owner" class="cap-kanban-card__avatars" :title="`Responsable: ${card.owner.name}`">
            <Avatar :name="card.owner.name" size="sm" />
        </span>
        <span v-if="card.tender" class="cap-kanban-card__tag inline-flex min-w-0 max-w-full items-center gap-1" :title="`Licitación ${card.tender.reference}`"><i class="mdi mdi-gavel" aria-hidden="true" /><span class="truncate">{{ card.tender.reference }}</span></span>
        <span v-if="card.quote" class="cap-kanban-card__tag inline-flex items-center gap-1" :title="`Cotización ${card.quote.number} · ${card.quote.status}`"><i class="mdi mdi-file-document-outline" aria-hidden="true" />{{ card.quote.number }}</span>
        <span v-else-if="card.quotesCount" class="cap-kanban-card__tag inline-flex items-center gap-1" :title="`${card.quotesCount} cotizaciones`"><i class="mdi mdi-file-document-multiple-outline" aria-hidden="true" />{{ card.quotesCount }}</span>
        <button v-if="showParticipants" type="button" class="cap-kanban-card__meta rounded-sm hover:text-gray-900 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cap-brand dark:hover:text-gray-100" :aria-label="`Participantes de ${card.title} (${card.participantsCount})`" @click.stop="emit('participants')" @keydown.enter.space.stop>
            <i class="mdi mdi-account-group-outline" aria-hidden="true" />Participantes · {{ card.participantsCount }}
        </button>
        <span v-else-if="card.participantsCount" class="cap-kanban-card__meta" :title="`${card.participantsCount} participantes`"><i class="mdi mdi-account-group-outline" aria-hidden="true" />{{ card.participantsCount }}</span>
    </div>
</template>
