<script setup>
// Procedimientos operativos estándar: lista a un lado, lectura al otro, «Marcar como leído» por usuario
// y PDF de cada procedimiento.
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import AppButton from '../../Components/AppButton.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { date, number } from '../../lib/format.js';
import ProgressBar from './Partials/ProgressBar.vue';

const props = defineProps({
    sops: { type: Array, default: () => [] },
    selected: { type: String, default: '' },
});

const current = ref(props.selected || props.sops[0]?.slug);
const saving = ref(false);
const reader = ref(null);

const sop = computed(() => props.sops.find((item) => item.slug === current.value) ?? props.sops[0]);
const readCount = computed(() => props.sops.filter((item) => item.read_at).length);

function select(slug) {
    current.value = slug;
    window.history.replaceState(window.history.state, '', `/sops?sop=${slug}`);
    if (window.matchMedia('(max-width: 1023px)').matches) nextTick(() => reader.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}

function toggleRead() {
    saving.value = true;
    router.put(`/sops/${sop.value.slug}/lectura`, { read: !sop.value.read_at }, { preserveScroll: true, only: ['sops', 'flash'], onFinish: () => (saving.value = false) });
}
</script>

<template>
    <AppLayout title="Procedimientos (SOP)">
        <PageHeader description="Cómo trabaja la empresa en cada etapa del flujo de licitaciones." />

        <div class="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="flex flex-col gap-4">
                <section class="rounded-cap-card border border-cap-divider bg-cap-card p-4">
                    <p class="text-xs font-medium text-cap-text-soft">Tu lectura</p>
                    <p class="mt-1 font-display text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number(readCount) }} de {{ number(sops.length) }} leídos</p>
                    <ProgressBar class="mt-3" :value="sops.length ? (readCount / sops.length) * 100 : 0" :status="readCount === sops.length ? 'ok' : 'info'" label="Procedimientos leídos" />
                </section>
                <nav aria-label="Procedimientos" class="flex flex-col gap-2">
                    <button
                        v-for="item in sops"
                        :key="item.slug"
                        type="button"
                        class="flex items-start gap-3 rounded-cap-card border bg-cap-card p-4 text-left transition-colors duration-cap-fast ease-cap hover:bg-cap-card-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-cap-chrome dark:focus-visible:ring-gray-100"
                        :class="item.slug === sop?.slug ? 'border-cap-brand dark:border-cap-brand-soft' : 'border-cap-divider'"
                        :aria-current="item.slug === sop?.slug ? 'true' : undefined"
                        @click="select(item.slug)"
                    >
                        <span class="mt-0.5 rounded-md bg-cap-card-2 px-2 py-1 text-xs font-semibold tabular-nums text-gray-800 dark:text-gray-200">{{ item.code }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ item.title }}</span>
                            <span class="mt-1 block"><StatusBadge :status="item.read_at ? 'ok' : 'neutral'" :label="item.read_at ? 'Leído' : 'Sin leer'" /></span>
                        </span>
                    </button>
                </nav>
            </aside>

            <article v-if="sop" ref="reader" class="scroll-mt-24 rounded-cap-card border border-cap-divider bg-cap-card p-5 md:p-8">
                <header class="flex flex-wrap items-start justify-between gap-4 border-b border-cap-divider pb-5">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-cap-text-soft">{{ sop.code }}</p>
                        <h2 class="mt-1 font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ sop.title }}</h2>
                        <p v-if="sop.read_at" class="mt-1 text-xs text-cap-text-soft">Leído el {{ date(sop.read_at, 'medium') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <AppButton external :href="sop.pdf_url" icon="file-pdf-box">Descargar PDF</AppButton>
                        <AppButton :variant="sop.read_at ? 'secondary' : 'primary'" :icon="sop.read_at ? 'check-circle' : 'check-circle-outline'" :loading="saving" @click="toggleRead">{{ sop.read_at ? 'Marcar como no leído' : 'Marcar como leído' }}</AppButton>
                    </div>
                </header>

                <div class="mx-auto flex max-w-prose flex-col gap-8 pt-6">
                    <DescriptionList :columns="1" :items="[{ label: 'Propósito', value: sop.purpose }, { label: 'Alcance', value: sop.scope }, { label: 'Responsable', value: sop.owner }]" />

                    <section>
                        <h3 class="mb-4 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Procedimiento</h3>
                        <ol class="flex flex-col gap-4">
                            <li v-for="(step, index) in sop.steps" :key="step.title" class="flex gap-4">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-cap-red-50 text-sm font-semibold tabular-nums text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true">{{ index + 1 }}</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ step.title }}</p>
                                    <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ step.detail }}</p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <section v-if="sop.notes?.length" class="rounded-cap-control bg-cap-card-2 p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Notas</h3>
                        <p v-for="note in sop.notes" :key="note" class="text-sm text-gray-700 dark:text-gray-300">{{ note }}</p>
                    </section>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
