<script setup>
// Configuración de la empresa activa: datos de membrete, logo, vista previa del PDF y zona de peligro.
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CapAlert from '../../../design/vue/CapAlert.vue';
import AppButton from '../../Components/AppButton.vue';
import BaseModal from '../../Components/BaseModal.vue';
import DescriptionList from '../../Components/DescriptionList.vue';
import FileDropzone from '../../Components/FileDropzone.vue';
import FormField from '../../Components/FormField.vue';
import PageHeader from '../../Components/PageHeader.vue';
import PdfViewer from '../../Components/PdfViewer.vue';
import TextArea from '../../Components/TextArea.vue';
import TextInput from '../../Components/TextInput.vue';
import { useConfirm } from '../../composables/useConfirm.js';
import { useUpload } from '../../composables/useUpload.js';
import AppLayout from '../../Layouts/AppLayout.vue';
import { focusFirstError } from '../../lib/errors.js';
import { percent } from '../../lib/format.js';
import { visit } from '../../lib/http.js';
import { card } from '../../lib/ui.js';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    company: { type: Object, required: true },
});

const confirm = useConfirm();
const body = ref(null);

const form = useForm({
    name: props.company.name ?? '',
    tax_id: props.company.tax_id ?? '',
    representative: props.company.representative ?? '',
    contact_name: props.company.contact_name ?? '',
    contact_email: props.company.contact_email ?? '',
    contact_phone: props.company.contact_phone ?? '',
    settings: {
        address: props.settings.address ?? '',
        city: props.settings.city ?? '',
        phone: props.settings.phone ?? '',
        email: props.settings.email ?? '',
        website: props.settings.website ?? '',
        quote_footer: props.settings.quote_footer ?? '',
    },
});

function save() {
    form.put('/configuracion', { preserveScroll: true, onSuccess: () => form.defaults(), onError: () => focusFirstError(body.value) });
}

// Logo
const files = ref([]);
const uploader = useUpload();
async function uploadLogo() {
    const done = await uploader.upload('/configuracion/logo', files.value, { field: 'logo', only: ['company', 'flash', 'errors'] });
    if (done) {
        files.value = [];
        uploader.reset();
    }
}
function removeLogo() {
    confirm({
        verb: 'Quitar',
        object: 'el logo de la empresa',
        noun: 'logo',
        message: 'Los PDF nuevos saldrán solo con el nombre de la empresa.',
        icon: 'image-remove-outline',
        action: () => visit('delete', '/configuracion/logo', {}, { only: ['company', 'flash'] }),
    });
}

// Vista previa
const previewOpen = ref(false);
const previewKey = ref(0);
function openPreview() {
    previewKey.value = Date.now();
    previewOpen.value = true;
}

// Zona de peligro
const resetOpen = ref(false);
const resetForm = useForm({ confirmation: '' });
const resetReady = computed(() => resetForm.confirmation.trim() === props.company.name);
function openReset() {
    resetForm.reset();
    resetForm.clearErrors();
    resetOpen.value = true;
}
function doReset() {
    resetForm.post('/configuracion/reset', {
        preserveScroll: true,
        onSuccess: () => {
            resetOpen.value = false;
            router.reload();
        },
    });
}

const taxInfo = computed(() => [
    { label: 'Tasa de ISV', value: percent(props.company.tax_rate, 0) },
    { label: 'Precio de Blessing', value: props.company.prices_include_tax ? 'Incluye ISV' : 'Sin ISV (se suma)' },
    { label: 'Conexión con Blessing', value: props.company.blessing_configured ? 'Configurada' : 'Sin configurar' },
]);
</script>

<template>
    <AppLayout title="Configuración">
        <PageHeader :description="`Datos de ${company.name} para membretes, cotizaciones y documentos`">
            <template #actions>
                <AppButton icon="file-pdf-box" @click="openPreview">Vista previa del PDF</AppButton>
            </template>
        </PageHeader>

        <form ref="body" :class="[card, 'flex flex-col gap-6 p-4 sm:p-6']" @submit.prevent="save">
            <div>
                <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Datos de la empresa</h2>
                <p class="text-sm text-cap-text-soft">Salen en el encabezado y el pie de las cotizaciones.</p>
            </div>
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 md:grid-cols-2">
                <FormField label="Nombre" required :error="form.errors.name" class="md:col-span-2"><TextInput v-model="form.name" maxlength="255" /></FormField>
                <FormField label="RTN" optional :error="form.errors.tax_id"><TextInput v-model="form.tax_id" maxlength="50" inputmode="numeric" /></FormField>
                <FormField label="Representante legal" optional :error="form.errors.representative"><TextInput v-model="form.representative" maxlength="255" /></FormField>
                <FormField label="Dirección" optional :error="form.errors['settings.address']" class="md:col-span-2"><TextInput v-model="form.settings.address" maxlength="500" /></FormField>
                <FormField label="Ciudad" optional :error="form.errors['settings.city']"><TextInput v-model="form.settings.city" maxlength="100" /></FormField>
                <FormField label="Teléfono" optional :error="form.errors['settings.phone']"><TextInput v-model="form.settings.phone" type="tel" maxlength="50" /></FormField>
                <FormField label="Correo" optional :error="form.errors['settings.email']"><TextInput v-model="form.settings.email" type="email" maxlength="255" /></FormField>
                <FormField label="Sitio web" optional :error="form.errors['settings.website']"><TextInput v-model="form.settings.website" maxlength="255" /></FormField>
                <FormField label="Persona de contacto" optional :error="form.errors.contact_name"><TextInput v-model="form.contact_name" maxlength="255" /></FormField>
                <FormField label="Correo de contacto" optional :error="form.errors.contact_email"><TextInput v-model="form.contact_email" type="email" maxlength="255" /></FormField>
                <FormField label="Pie de página de cotizaciones" optional :error="form.errors['settings.quote_footer']" class="md:col-span-2"><TextArea v-model="form.settings.quote_footer" :rows="2" maxlength="1000" /></FormField>
            </div>
            <div class="flex justify-end">
                <AppButton variant="primary" type="submit" icon="content-save-outline" :loading="form.processing" :disabled="!form.isDirty">Guardar cambios</AppButton>
            </div>
        </form>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <section :class="[card, 'flex flex-col gap-4 p-4 sm:p-6']">
                <div>
                    <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Logo</h2>
                    <p class="text-sm text-cap-text-soft">PNG, JPG o WebP de hasta 2 MB; mejor con fondo transparente.</p>
                </div>
                <div v-if="company.logo_url" class="flex flex-wrap items-center gap-4">
                    <img :src="company.logo_url" :alt="`Logo de ${company.name}`" class="h-20 max-w-full rounded-cap-control border border-cap-divider bg-white object-contain p-2 dark:bg-white" />
                    <AppButton variant="danger" icon="image-remove-outline" @click="removeLogo">Quitar logo</AppButton>
                </div>
                <FileDropzone v-model="files" :uploader="uploader" accept=".png,.jpg,.jpeg,.webp" :max-size-mb="2" :title="company.logo_url ? 'Arrastre un logo nuevo para reemplazarlo' : 'Arrastre el logo o elija una imagen'" />
                <div class="flex justify-end">
                    <AppButton icon="upload-outline" :disabled="!files.length" :loading="uploader.uploading.value" @click="uploadLogo">Subir logo</AppButton>
                </div>
            </section>

            <section :class="[card, 'flex flex-col gap-4 p-4 sm:p-6']">
                <div>
                    <h2 class="font-display text-base font-semibold text-gray-900 dark:text-gray-100">Impuestos e inventario</h2>
                    <p class="text-sm text-cap-text-soft">Los define la raíz del grupo en Empresas.</p>
                </div>
                <DescriptionList :items="taxInfo" :columns="1" />
            </section>
        </div>

        <section :class="[card, 'flex flex-col gap-4 border-cap-status-error-tint-border p-4 sm:p-6']">
            <div>
                <h2 class="font-display text-base font-semibold text-cap-status-error-ink">Zona de peligro</h2>
                <p class="text-sm text-cap-text-soft">Restablecer borra la dirección, los datos de contacto del membrete, el pie de página y el logo. Licitaciones, cotizaciones y demás datos no se tocan.</p>
            </div>
            <div>
                <AppButton variant="danger" icon="restore-alert" @click="openReset">Restablecer configuración</AppButton>
            </div>
        </section>

        <BaseModal v-model:open="previewOpen" size="xl" flush title="Vista previa del membrete" description="Con los datos guardados (guarde antes los cambios pendientes).">
            <PdfViewer v-if="previewOpen" :key="previewKey" :src="`/configuracion/preview.pdf?v=${previewKey}`" name="vista-previa.pdf" height="h-[70vh]" />
            <template #footer>
                <AppButton @click="previewOpen = false">Cerrar</AppButton>
            </template>
        </BaseModal>

        <BaseModal v-model:open="resetOpen" as="form" size="md" title="¿Restablecer la configuración?" :persistent="resetForm.processing" @submit="doReset">
            <div class="flex flex-col gap-4">
                <CapAlert status="error" title="No se puede deshacer" detail="Se borran los datos de membrete y el logo de esta empresa." />
                <FormField :label="`Escriba «${company.name}» para confirmar`" :error="resetForm.errors.confirmation">
                    <TextInput v-model="resetForm.confirmation" autocomplete="off" />
                </FormField>
            </div>
            <template #footer>
                <AppButton :disabled="resetForm.processing" @click="resetOpen = false">Cancelar</AppButton>
                <AppButton variant="danger" type="submit" icon="restore-alert" :disabled="!resetReady" :loading="resetForm.processing">Restablecer</AppButton>
            </template>
        </BaseModal>
    </AppLayout>
</template>
