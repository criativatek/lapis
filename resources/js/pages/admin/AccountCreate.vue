<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ plans: { key: string; name: string }[] }>();

const form = useForm({
    type: 'personal' as 'personal' | 'institutional',
    // Personal, and institutional's "new owner" — same shape on purpose,
    // the same person-provisioning either way.
    name: '',
    email: '',
    password: '',
    plan_key: 'base',
    // Institutional only.
    organization_name: '',
    owner_mode: 'new' as 'new' | 'existing',
    owner_email: '',
});

const isInstitutional = computed(() => form.type === 'institutional');
const isExistingOwner = computed(() => isInstitutional.value && form.owner_mode === 'existing');

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
            <p class="text-sm text-muted-foreground">
                Provisiona um professor ou uma organização institucional. O email fica verificado; se deixares a palavra-passe em
                branco, é gerada uma temporária.
            </p>
        </div>

        <form class="space-y-4 rounded-lg border border-border p-4" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="!isInstitutional ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/40'"
                    @click="
                        form.type = 'personal';
                        if (form.plan_key === 'institutional') form.plan_key = 'base';
                    "
                >
                    Conta pessoal
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="isInstitutional ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/40'"
                    @click="
                        form.type = 'institutional';
                        if (form.plan_key === 'base') form.plan_key = 'institutional';
                    "
                >
                    Institucional
                </button>
            </div>

            <label v-if="isInstitutional" class="block text-sm">
                <span class="mb-1 block font-medium">Nome da organização</span>
                <input
                    v-model="form.organization_name"
                    type="text"
                    placeholder="Ex.: Agrupamento de Escolas de Exemplo"
                    class="w-full rounded-md border border-border bg-background px-3 py-2"
                />
                <span v-if="form.errors.organization_name" class="mt-1 block text-xs text-red-600">{{
                    form.errors.organization_name
                }}</span>
            </label>

            <div v-if="isInstitutional" class="grid grid-cols-2 gap-2">
                <button
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="form.owner_mode === 'new' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/40'"
                    @click="form.owner_mode = 'new'"
                >
                    Novo responsável
                </button>
                <button
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="form.owner_mode === 'existing' ? 'border-primary bg-primary/10 text-primary' : 'border-border hover:bg-muted/40'"
                    @click="form.owner_mode = 'existing'"
                >
                    Utilizador existente
                </button>
            </div>

            <label v-if="isExistingOwner" class="block text-sm">
                <span class="mb-1 block font-medium">Email do responsável</span>
                <input v-model="form.owner_email" type="email" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                <span v-if="form.errors.owner_email" class="mt-1 block text-xs text-red-600">{{ form.errors.owner_email }}</span>
                <span class="mt-1 block text-xs text-muted-foreground">Tem de já ter conta no LÁPIS.</span>
            </label>

            <template v-else>
                <label class="block text-sm">
                    <span class="mb-1 block font-medium">{{ isInstitutional ? 'Nome do responsável' : 'Nome' }}</span>
                    <input v-model="form.name" type="text" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.name" class="mt-1 block text-xs text-red-600">{{ form.errors.name }}</span>
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Email</span>
                    <input v-model="form.email" type="email" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.email" class="mt-1 block text-xs text-red-600">{{ form.errors.email }}</span>
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium">Palavra-passe <span class="text-muted-foreground">(opcional)</span></span>
                    <input
                        v-model="form.password"
                        type="text"
                        autocomplete="off"
                        placeholder="Deixa em branco para gerar"
                        class="w-full rounded-md border border-border bg-background px-3 py-2"
                    />
                    <span v-if="form.errors.password" class="mt-1 block text-xs text-red-600">{{ form.errors.password }}</span>
                </label>
                <p v-if="isInstitutional" class="text-xs text-muted-foreground">
                    Fica também com a sua própria organização pessoal, como qualquer conta nova.
                </p>
            </template>

            <label class="block text-sm">
                <span class="mb-1 block font-medium">Plano</span>
                <select v-model="form.plan_key" class="w-full rounded-md border border-border bg-background px-3 py-2">
                    <option v-for="plan in plans" :key="plan.key" :value="plan.key">{{ plan.name }}</option>
                </select>
            </label>

            <div class="flex justify-end gap-2">
                <Link href="/admin" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40">Cancelar</Link>
                <button
                    type="submit"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    Criar conta
                </button>
            </div>
        </form>
    </div>
</template>
