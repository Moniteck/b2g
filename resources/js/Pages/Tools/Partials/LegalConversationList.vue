<script setup>
// Lista de conversaciones del Agente Legal (panel lateral en escritorio, hoja en móvil), agrupadas
// por fecha y con buscador cuando hay muchas.
// Props: conversations ([{ id, title, updated_at, messages_count }]), activeId, disabled.
// Eventos: open (conversación), remove (conversación).
import { computed, ref } from 'vue';
import IconButton from '@/Components/IconButton.vue';
import { daysUntil, relativeTime } from '@/lib/format.js';
import { inputClass } from '@/lib/ui.js';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeId: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['open', 'remove']);

const query = ref('');
const filtered = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('es');
    return needle ? props.conversations.filter((item) => item.title.toLocaleLowerCase('es').includes(needle)) : props.conversations;
});
const groups = computed(() => {
    const buckets = [
        { label: 'Hoy', items: [] },
        { label: 'Últimos 7 días', items: [] },
        { label: 'Anteriores', items: [] },
    ];
    filtered.value.forEach((item) => {
        const days = daysUntil(item.updated_at);
        buckets[days === 0 ? 0 : days >= -7 ? 1 : 2].items.push(item);
    });
    return buckets.filter((bucket) => bucket.items.length);
});
</script>

<template>
    <div class="flex min-h-0 flex-col gap-3">
        <label v-if="conversations.length > 6" class="relative block">
            <span class="sr-only">Buscar conversación</span>
            <i class="mdi mdi-magnify pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-lg leading-none text-cap-text-soft" aria-hidden="true" />
            <input v-model="query" type="search" :class="[inputClass(), 'h-10 pl-9']" placeholder="Buscar conversación…">
        </label>

        <div v-if="!conversations.length" class="flex flex-col items-center gap-2 px-3 py-8 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-full bg-cap-card-2 text-cap-text-soft" aria-hidden="true"><i class="mdi mdi-forum-outline text-2xl leading-none" /></span>
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Aún no hay conversaciones</p>
            <p class="text-xs text-cap-text-soft">Tus consultas quedan aquí para retomarlas cuando quieras.</p>
        </div>
        <p v-else-if="!filtered.length" class="px-3 py-6 text-center text-sm text-cap-text-soft">Ninguna conversación coincide con «{{ query }}».</p>

        <nav v-else class="flex min-h-0 flex-col gap-4 overflow-y-auto pr-1" aria-label="Conversaciones">
            <section v-for="group in groups" :key="group.label" class="flex flex-col gap-1">
                <h3 class="px-3 text-xs font-semibold uppercase tracking-wide text-cap-text-soft">{{ group.label }}</h3>
                <ul class="flex flex-col gap-0.5">
                    <li
                        v-for="conversation in group.items"
                        :key="conversation.id"
                        class="group flex items-center gap-1 rounded-cap-control transition-colors duration-cap-fast ease-cap"
                        :class="conversation.id === activeId ? 'bg-cap-card-2' : 'hover:bg-cap-card-2'"
                    >
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-start gap-2.5 rounded-cap-control px-3 py-2 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome disabled:opacity-60 dark:focus-visible:ring-gray-100"
                            :aria-current="conversation.id === activeId ? 'true' : undefined"
                            :disabled="disabled"
                            @click="emit('open', conversation)"
                        >
                            <i class="mdi mt-0.5 text-base leading-none" :class="conversation.id === activeId ? 'mdi-message-text text-cap-brand dark:text-cap-brand-soft' : 'mdi-message-text-outline text-cap-text-soft'" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100" :title="conversation.title">{{ conversation.title }}</span>
                                <span class="block text-xs text-cap-text-soft">{{ relativeTime(conversation.updated_at) }} · {{ conversation.messages_count }} mensajes</span>
                            </span>
                        </button>
                        <span class="opacity-100 transition-opacity duration-cap-fast ease-cap lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
                            <IconButton icon="trash-can-outline" :label="`Eliminar la conversación ${conversation.title}`" :disabled="disabled" @click="emit('remove', conversation)" />
                        </span>
                    </li>
                </ul>
            </section>
        </nav>
    </div>
</template>
