<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Settings = {
    mail_host: string | null;
    mail_port: number | null;
    mail_username: string | null;
    mail_encryption: string | null;
    mail_from_address: string | null;
    mail_from_name: string | null;
    password_set: boolean;
};

const props = defineProps<{ settings: Settings }>();

const form = useForm({
    mail_host: props.settings.mail_host ?? '',
    mail_port: props.settings.mail_port ?? 587,
    mail_username: props.settings.mail_username ?? '',
    mail_password: '',
    mail_encryption: props.settings.mail_encryption ?? '',
    mail_from_address: props.settings.mail_from_address ?? '',
    mail_from_name: props.settings.mail_from_name ?? '',
});

const testTo = ref('');

function save(): void {
    form.put('/admin/settings', { preserveScroll: true, onSuccess: () => form.reset('mail_password') });
}

function sendTest(): void {
    router.post('/admin/settings/test', { test_to: testTo.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Email (SMTP) — Backoffice" />

    <div class="mx-auto w-full max-w-xl space-y-5 p-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Email do sistema (SMTP)</h1>
            <p class="text-sm text-muted-foreground">Usado para verificação de conta, reposição da palavra-passe e convites. Sobrepõe o <code>.env</code>.</p>
        </div>

        <form class="space-y-4 rounded-lg border border-border p-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Host</span>
                    <input v-model="form.mail_host" type="text" placeholder="Ex.: smtp.exemplo.com" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Encriptação</span>
                    <select v-model="form.mail_encryption" class="w-full rounded-md border border-border bg-background px-3 py-2">
                        <option value="">Nenhuma</option>
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Porta</span>
                    <input v-model.number="form.mail_port" type="number" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Utilizador</span>
                    <input v-model="form.mail_username" type="text" autocomplete="off" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Palavra-passe</span>
                    <input v-model="form.mail_password" type="password" autocomplete="new-password" :placeholder="settings.password_set ? '•••• definida (deixa em branco para manter)' : ''" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Remetente (email)</span>
                    <input v-model="form.mail_from_address" type="email" placeholder="Ex.: nao-responder@criativatek.com" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                    <span v-if="form.errors.mail_from_address" class="mt-1 block text-xs text-red-600">{{ form.errors.mail_from_address }}</span>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block font-medium">Remetente (nome)</span>
                    <input v-model="form.mail_from_name" type="text" placeholder="Ex.: LÁPIS" class="w-full rounded-md border border-border bg-background px-3 py-2" />
                </label>
            </div>

            <div class="flex flex-wrap items-end justify-between gap-2 border-t border-border pt-4">
                <div class="flex items-end gap-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Enviar teste para</span>
                        <input v-model="testTo" type="email" placeholder="Ex.: o-teu-email@exemplo.com" class="w-56 rounded-md border border-border bg-background px-3 py-2" />
                    </label>
                    <button type="button" class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40" @click="sendTest">
                        Enviar email de teste
                    </button>
                </div>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50" :disabled="form.processing">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</template>
