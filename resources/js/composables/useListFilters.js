// Filtros de una lista paginada en servidor: cada cambio recarga la página con los filtros en la URL.
//
//   const { filters, loading, error, active, reset, reload } = useListFilters('/clientes', props.filters, { only: ['clients', 'filters'], defaults: { status: 'all' } });
//
// `filters` es reactivo (se usa con v-model); la búsqueda ya llega con espera desde `Toolbar`.
// `active` dice si hay algún filtro distinto del valor por defecto (para «sin resultados»).
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

export function useListFilters(url, initial = {}, { only = [], defaults = {} } = {}) {
    const filters = reactive({ ...defaults, ...initial });
    const loading = ref(false);
    const error = ref('');

    const clean = () =>
        Object.fromEntries(
            Object.entries(filters).filter(([key, value]) => value !== null && value !== undefined && value !== '' && value !== defaults[key]),
        );

    const active = computed(() => Object.keys(clean()).some((key) => !['sort', 'direction', 'per_page'].includes(key)));

    function reload() {
        error.value = '';
        router.get(url, clean(), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            ...(only.length ? { only } : {}),
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
            onError: () => {
                error.value = 'No se pudo cargar la lista.';
            },
            onException: () => {
                error.value = 'No se pudo cargar la lista. Revise su conexión.';
                return false;
            },
        });
    }

    // Los cambios del mismo tic se agrupan: limpiar varios filtros hace una sola petición.
    watch(() => ({ ...filters }), reload);

    function reset() {
        Object.keys(filters).forEach((key) => {
            if (!['sort', 'direction', 'per_page'].includes(key)) filters[key] = defaults[key] ?? (typeof filters[key] === 'number' ? null : '');
        });
    }

    return { filters, loading, error, active, reset, reload };
}
