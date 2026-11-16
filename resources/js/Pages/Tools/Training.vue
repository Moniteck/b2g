<script setup>
// Capacitación: cursos de lectura por lecciones, con el progreso de cada usuario.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/Components/AppButton.vue';
import BaseModal from '@/Components/BaseModal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { number, percent } from '@/lib/format.js';
import ProgressBar from '@/Pages/Tools/Partials/ProgressBar.vue';

const props = defineProps({
    courses: { type: Array, default: () => [] },
});

const reading = ref(null);
const lesson = ref(0);
const saving = ref(false);

const course = computed(() => props.courses.find((item) => item.key === reading.value) ?? null);
const readerOpen = computed({ get: () => course.value !== null, set: (value) => !value && (reading.value = null) });
const completed = computed(() => props.courses.filter((item) => item.progress >= 100).length);
const overall = computed(() => (props.courses.length ? props.courses.reduce((sum, item) => sum + item.progress, 0) / props.courses.length : 0));
const current = computed(() => course.value?.lessons[lesson.value]);
const isLast = computed(() => course.value && lesson.value === course.value.lessons.length - 1);

function statusOf(item) {
    if (item.progress >= 100) return { status: 'ok', label: 'Completado' };
    if (item.progress > 0) return { status: 'info', label: 'En curso' };
    return { status: 'neutral', label: 'Sin empezar' };
}

function actionOf(item) {
    if (item.progress >= 100) return 'Repasar';
    return item.progress > 0 ? 'Continuar' : 'Empezar';
}

function start(item) {
    reading.value = item.key;
    lesson.value = item.progress >= 100 ? 0 : Math.min(item.completed_lessons, item.lessons.length - 1);
}

function advance() {
    const done = Math.max(course.value.completed_lessons, lesson.value + 1);
    const finishing = isLast.value;
    const save = done > course.value.completed_lessons;
    const next = () => (finishing ? (reading.value = null) : (lesson.value += 1));

    if (!save) return next();
    saving.value = true;
    router.put('/capacitacion', { tutorial_key: course.value.key, completed_lessons: done }, {
        preserveScroll: true,
        only: ['courses', 'flash'],
        onSuccess: next,
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <AppLayout title="Capacitación">
        <PageHeader description="Cursos cortos para aprender a usar el CRM en el flujo de licitaciones." />

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatCard variant="featured" label="Tu avance general" :value="percent(overall, 0)" :hint="`${number(completed)} de ${number(courses.length)} cursos completados`" />
            <StatCard label="Cursos completados" :value="number(completed)" />
            <StatCard label="Lecciones disponibles" :value="number(courses.reduce((sum, item) => sum + item.lessons.length, 0))" />
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <article v-for="item in courses" :key="item.key" class="flex flex-col gap-4 rounded-cap-card border border-cap-divider bg-cap-card p-5">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-cap-red-50 text-cap-brand dark:bg-cap-red-600/15 dark:text-cap-brand-soft" aria-hidden="true"><i class="mdi text-xl leading-none" :class="`mdi-${item.icon}`" /></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ item.title }}</h2>
                            <StatusBadge :status="statusOf(item).status" :label="statusOf(item).label" />
                        </div>
                        <p class="mt-1 text-sm text-cap-text-soft">{{ item.description }}</p>
                    </div>
                </div>
                <p class="text-xs text-cap-text-soft">{{ item.lessons.length }} lecciones · unos {{ item.minutes }} minutos</p>
                <ProgressBar :value="item.progress" :status="item.progress >= 100 ? 'ok' : 'info'" :label="`Avance en ${item.title}`" show-value />
                <div class="mt-auto flex justify-end">
                    <AppButton :icon="item.progress >= 100 ? 'book-refresh-outline' : 'play-circle-outline'" @click="start(item)">{{ actionOf(item) }}</AppButton>
                </div>
            </article>
        </div>

        <BaseModal v-model:open="readerOpen" size="lg" :title="course?.title ?? ''" :description="course ? `Lección ${lesson + 1} de ${course.lessons.length}` : ''" :persistent="saving">
            <template v-if="current">
                <ProgressBar class="mb-6" :value="((lesson + 1) / course.lessons.length) * 100" status="info" label="Avance en el curso" />
                <h3 class="mb-3 font-display text-lg font-semibold text-gray-900 dark:text-gray-100">{{ current.title }}</h3>
                <div class="flex max-w-prose flex-col gap-4 text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                    <p v-for="(paragraph, index) in current.paragraphs" :key="index">{{ paragraph }}</p>
                </div>
            </template>
            <template #footer>
                <AppButton v-if="lesson > 0" icon="chevron-left" class="mr-auto" :disabled="saving" @click="lesson -= 1">Anterior</AppButton>
                <AppButton :disabled="saving" @click="reading = null">Cerrar</AppButton>
                <AppButton variant="primary" :icon="isLast ? 'check-circle-outline' : 'chevron-right'" :loading="saving" @click="advance">{{ isLast ? 'Terminar curso' : 'Siguiente lección' }}</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
