// Enlace entre `FormField` y el control que envuelve: el control toma de aquí su id, su estado de
// error y sus atributos ARIA sin que la página tenga que repetirlos.
import { computed, inject, useId } from 'vue';

export const FIELD_KEY = Symbol('form-field');

/** Para controles (TextInput, SelectInput…). Las props propias (`id`, `invalid`, `required`) mandan. */
export function useField(props = {}) {
    const field = inject(FIELD_KEY, null);
    const ownId = useId();

    const id = computed(() => props.id || field?.id.value || ownId);
    const invalid = computed(() => Boolean(props.invalid) || Boolean(field?.invalid.value));
    const required = computed(() => Boolean(props.required) || Boolean(field?.required.value));
    const aria = computed(() => ({
        'aria-invalid': invalid.value ? 'true' : undefined,
        'aria-required': required.value ? 'true' : undefined,
        'aria-describedby': field?.describedBy.value || undefined,
    }));

    return { id, invalid, required, aria };
}
