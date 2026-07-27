<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ plans: { key: string; name: string }[] }>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    plan_key: 'base',
});

function submit(): void {
    form.post('/admin/accounts');
}
</script>

<template>
    <Head title="Nova conta — Backoffice" />

    <div class="mx-auto w-full max-w-lg space-y-5 p-6">
        <div>
            <Link href="/admin" class="text-sm text-muted-foreground hover:underline">← Contas</Link>
            <h1 class="mt-1 text-xl font-semibold tracking-tight">Nova conta</h1>
            <p class="text-sm text-muted-foreground">Provisiona um professor (ou escola). O email fica verificado; se deixares a password em branco, é gerada uma temporária.</p>
        </div>

        <form class="space-y-4 rounded-lg border border-border p-4" @submit.prevent="submit">
            <label class="block text-sm">
                <span class="mb-1 block font-medium">Nome</span>
                <input v-model="form.name" type="text" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                <span v-if="form.errors.name" class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Email</span>
                <input v-model="form.email" type="email" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                <span v-if="form.errors.email" class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Password <span class="text-muted-foreground">(opcional)</span></span>
                <input v-model="form.password" type="text" autocomplete="off" placeholder="Deixa em branco para gerar" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                <span v-if="form.errors.password" class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span>
            </label>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Plano</span>
                <select v-model="form.plan_key" class="w-full rounded-md border border-border bg-background px-3 py-2">
                    <option v-for="plan in plans" :key="plan.key" :value="plan.key">{{ plan.name }}</option>
                </select>
            </label>

            <div class="flex justify-end gap-2">
                <Link href="/admin" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40">Cancelar</Link>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="form.processing">
                    Criar conta
                </button>
            </div>
        </form>
    </div>
</template>
