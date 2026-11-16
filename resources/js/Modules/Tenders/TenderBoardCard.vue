<script setup>
// Tarjeta del kanban de Licitaciones (slot `card` de CapKanban): expediente, objeto, institución, el
// próximo plazo con su cuenta regresiva, monto y responsable. Clases de la receta `cap-kanban-card__*`.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import DropdownMenu from '@/Components/DropdownMenu.vue';
import { money } from '@/lib/format.js';
import { countdown, DEADLINE_ICONS, stageBadge } from '@/Modules/Tenders/tenders.js';

const props = defineProps({
    tender: { type: Object, required: true },
    menu: { type: Array, default: () => [] },
    now: { type: Number, default: () => Date.now() },
});

const left = computed(() => (props.tender.next_deadline ? countdown(props.tender.next_deadline.at, props.now) : null));
const badge = computed(() => stageBadge(props.tender.stage));
const initials = computed(() => (props.tender.owner?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase());
</script>

<template>
    <div class="cap-kanban-card__top">
        <span class="cap-kanban-card__priority" role="img" :aria-label="left?.urgent ? 'Plazo urgente' : 'Plazo normal'" :data-level="left?.urgent ? 'high' : left?.status === 'warning' ? 'medium' : undefined" />
        <span class="truncate">{{ tender.reference }}</span>
        <DropdownMenu v-if="menu.length" class="ml-auto" :label="`Acciones de ${tender.reference}`" :items="menu" width="w-56">
            <template #trigger="{ toggle, attrs }">
                <button type="button" class="cap-kanban-card__menu" v-bind="attrs" :aria-label="`Acciones de ${tender.reference}`" @click.stop="toggle" @keydown.enter.space.stop>
                    <i class="mdi mdi-dots-vertical" aria-hidden="true" />
                </button>
            </template>
        </DropdownMenu>
    </div>

    <Link :href="`/licitaciones/${tender.id}`" class="cap-kanban-card__title line-clamp-3 rounded-sm hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cap-brand" :title="tender.title" @click.stop>{{ tender.title }}</Link>
    <p class="-mt-1 truncate text-xs text-cap-text-soft" :title="tender.institution?.name">{{ tender.institution?.acronym || tender.institution?.name || 'Sin institución' }}</p>

    <div class="cap-kanban-card__row">
        <span class="cap-kanban-card__amount text-gray-900 dark:text-gray-100">{{ Number(tender.estimated_amount) > 0 ? money(tender.estimated_amount) : '—' }}</span>
        <span v-if="left" class="cap-kanban-card__badge" :data-status="left.status" :title="tender.next_deadline.label">
            <i :class="['mdi', `mdi-${DEADLINE_ICONS[tender.next_deadline.key]}`]" aria-hidden="true" />{{ left.label }}
        </span>
        <span v-else-if="tender.stage?.awaiting_result || ['vencida', 'desierta'].includes(tender.stage?.key)" class="cap-kanban-card__badge" :data-status="badge.status">{{ badge.label }}</span>
    </div>

    <div v-if="tender.owner || tender.purchase_type" class="cap-kanban-card__foot">
        <span v-if="tender.owner" class="cap-kanban-card__avatars"><span class="cap-kanban-card__avatar" :title="`Responsable: ${tender.owner.name}`">{{ initials }}</span></span>
        <span v-if="tender.purchase_type" class="cap-kanban-card__tag truncate" :title="tender.purchase_type">{{ tender.purchase_type }}</span>
    </div>
</template>
