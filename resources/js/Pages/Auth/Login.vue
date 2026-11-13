<script setup>
import { useForm } from '@inertiajs/vue3';
const form = useForm({ email: '', password: '', remember: false });
function submit() { form.post('/login'); }
</script>
<template><div class="login-page"><form class="login-card" @submit.prevent="submit"><div class="login-logo">B2G</div><h1>CRM B2G Honduras</h1><p>Gestión Gubernamental</p><label>Correo electrónico<input v-model="form.email" type="email" autocomplete="email" required autofocus></label><label>Contraseña<input v-model="form.password" type="password" autocomplete="current-password" required></label><label class="login-remember"><input v-model="form.remember" type="checkbox"> Recordarme</label><p v-if="form.errors.email" class="login-error">{{ form.errors.email }}</p><button :disabled="form.processing">{{ form.processing ? 'Ingresando...' : 'Ingresar' }}</button></form></div></template>
