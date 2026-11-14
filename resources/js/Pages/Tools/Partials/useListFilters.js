// Filtros de una lista paginada en servidor para las herramientas B2G: cada cambio recarga la página con
// los filtros en la URL (búsqueda, segmentado, tipo…).
//
//   const { filters, loading, error, active, reset, reload } = useListFilters('/historial', props.filters, { only: ['records', 'filters', 'counts'], defaults: { filter: 'all' } });
//
// `active` dice si hay algún filtro distinto del valor por defecto (para «sin resultados»).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

export function useListFilters(url, initial = {}, { only = [], defaults = {} } = {}) {
    const filters = reactive({ ...defaults, ...initial });
    const loading = ref(false);
    const error = ref('');

    const clean = () =>
        Object.fromEntries(Object.entries(filters).filter(([key, value]) => value !== null && value !== undefined && value !== '' && value !== defaults[key]));

    const active = computed(() => Object.keys(clean()).some((key) => key !== 'per_page'));

    function reload() {
        error.value = '';
        router.get(url, clean(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only.length ? { only } : {}),
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
            onError: () => (error.value = 'No se pudo cargar la lista.'),
            onException: () => {
                error.value = 'No se pudo cargar la lista. Revisa tu conexión y vuelve a intentarlo.';
                return false;
            },
        });
    }

    watch(() => ({ ...filters }), reload);

    function reset() {
        Object.keys(filters).forEach((key) => {
            if (key !== 'per_page') filters[key] = defaults[key] ?? '';
        });
    }

    return { filters, loading, error, active, reset, reload };
}
