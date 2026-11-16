<script setup>
// Administración: página índice con una tarjeta por pantalla de administración, filtradas por el
// permiso del usuario (`adminCards` de config/navigation.js). Cada destino sigue autorizando su ruta.
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { accountMenu, visibleAdminCards } from '@/config/navigation.js';

const page = usePage();
const cards = computed(() => visibleAdminCards(page.props.auth ?? {}));
const company = computed(() => page.props.activeCompany?.name ?? '');
</script>

<template>
    <AppLayout title="Administración">
        <PageHeader :description="company ? `Configuración y catálogos de ${company} y del grupo` : 'Configuración y catálogos'" />

        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Pantallas de administración">
            <li v-for="card in cards" :key="card.href">
                <Link :href="card.href" class="group flex h-full items-start gap-4 rounded-cap-card border border-cap-divider bg-cap-card p-5 shadow-cap-card hover:border-cap-brand focus-visible:outline-2 dark:hover:border-cap-brand-soft">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-cap-control bg-cap-card-2 text-cap-brand dark:text-cap-brand-soft" aria-hidden="true">
                        <i class="mdi m-hop text-2xl leading-none" :class="`mdi-${card.icon}`" />
                    </span>
                    <span class="min-w-0">
                        <span class="block font-display text-base font-semibold text-gray-900 dark:text-gray-100">{{ card.label }}</span>
                        <span class="mt-1 block text-sm text-cap-text-soft">{{ card.description }}</span>
                    </span>
                    <i class="mdi mdi-chevron-right ml-auto self-center text-xl leading-none text-cap-text-soft" aria-hidden="true" />
                </Link>
            </li>
        </ul>

        <section aria-labelledby="admin-personal">
            <h2 id="admin-personal" class="mb-3 font-display text-base font-semibold text-gray-900 dark:text-gray-100">Lo tuyo</h2>
            <div class="flex flex-wrap gap-2">
                <Link v-for="entry in accountMenu" :key="entry.href" :href="entry.href" class="inline-flex min-h-11 items-center gap-2 rounded-full border border-cap-divider bg-cap-card px-4 text-sm text-gray-800 hover:bg-cap-card-2 dark:text-gray-200">
                    <i class="mdi text-lg leading-none text-cap-text-soft" :class="`mdi-${entry.icon}`" aria-hidden="true" />{{ entry.label }}
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
