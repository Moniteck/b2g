// Subida de archivos con Inertia (`router.post` + `forceFormData`), con progreso y error por archivo.
//
//   const uploader = useUpload();
//   const files = ref([]);
//   <FileDropzone v-model="files" :uploader="uploader" accept=".pdf" :max-size-mb="20" multiple />
//   await uploader.upload('/documentos', files.value, { field: 'file', data: { category: 'Legal' } });
//
// Por defecto envía un archivo por petición, en orden: así cada uno tiene su progreso y su error.
// Con `{ together: true }` van todos en una sola petición como `field[]`.
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { fieldError } from '@/lib/errors.js';

export function useUpload() {
    // Estado por archivo: { status: 'pending' | 'uploading' | 'done' | 'error', progress: 0–100, error: '' }
    const states = reactive(new Map());
    const uploading = ref(false);
    const errors = ref({});
    let cancelToken = null;

    const progress = computed(() => {
        if (states.size === 0) return 0;
        const total = [...states.values()].reduce((sum, state) => sum + state.progress, 0);
        return Math.round(total / states.size);
    });

    function stateOf(file) {
        return states.get(file) ?? null;
    }

    function send(url, files, { field, data, together, options }) {
        return new Promise((resolve) => {
            const payload = { ...data, [field]: together ? files : files[0] };
            files.forEach((file) => states.set(file, { status: 'uploading', progress: 0, error: '' }));
            router.post(url, payload, {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                ...options,
                onCancelToken: (token) => {
                    cancelToken = token;
                },
                onProgress: (event) => {
                    files.forEach((file) => {
                        states.get(file).progress = Math.round(event?.percentage ?? 0);
                    });
                },
                onSuccess: (page) => {
                    files.forEach((file) => states.set(file, { status: 'done', progress: 100, error: '' }));
                    options?.onSuccess?.(page);
                    resolve(true);
                },
                onError: (serverErrors) => {
                    errors.value = { ...errors.value, ...serverErrors };
                    files.forEach((file, index) => {
                        const message = fieldError(serverErrors, together ? `${field}.${index}` : field) || fieldError(serverErrors, field) || Object.values(serverErrors).flat()[0] || 'El servidor rechazó el archivo.';
                        states.set(file, { status: 'error', progress: 0, error: String(message) });
                    });
                    options?.onError?.(serverErrors);
                    resolve(false);
                },
                onCancel: () => {
                    files.forEach((file) => states.set(file, { status: 'error', progress: 0, error: 'Subida cancelada.' }));
                    resolve(false);
                },
            });
        });
    }

    /** Devuelve `true` si todos los archivos se subieron. */
    async function upload(url, files, { field = 'file', data = {}, together = false, ...options } = {}) {
        const list = [...files];
        if (list.length === 0 || uploading.value) return false;
        uploading.value = true;
        errors.value = {};
        list.forEach((file) => states.set(file, { status: 'pending', progress: 0, error: '' }));
        let allDone = true;
        try {
            if (together) {
                allDone = await send(url, list, { field, data, together, options });
            } else {
                for (const file of list) {
                    // En serie: dos visitas de Inertia a la vez se cancelan entre sí.
                    allDone = (await send(url, [file], { field, data, together, options })) && allDone;
                }
            }
        } finally {
            uploading.value = false;
            cancelToken = null;
        }
        return allDone;
    }

    function cancel() {
        cancelToken?.cancel();
    }

    function reset() {
        states.clear();
        errors.value = {};
    }

    return { upload, cancel, reset, stateOf, states, uploading, progress, errors };
}
