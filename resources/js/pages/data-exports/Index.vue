<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';

type Export = {
    ulid: string;
    created_at: string | null;
    expires_at: string | null;
    ready: boolean;
};

const props = defineProps<{ exports: Export[]; organizationName: string }>();

const page = usePage();
const exportError = computed(() => (page.props.errors as Record<string, string>)?.export ?? null);

const form = useForm({});

function requestExport(): void {
    form.post('/data-exports', { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto w-full max-w-2xl space-y-6 p-4">
        <Heading
            title="Exportar os meus dados"
            :description="`Os dados a que a sua conta tem acesso em ${props.organizationName}, num ficheiro que pode guardar.`"
        />

        <section class="space-y-3 rounded-lg border border-border p-4">
            <p class="text-sm text-muted-foreground">
                Inclui as suas turmas e o que lhes está associado. Não inclui trabalho pedagógico de colegas, nem
                palavra-passe, autenticação de dois fatores ou outros dados de acesso à conta.
            </p>
            <Button :disabled="form.processing" @click="requestExport">
                Pedir exportação
            </Button>
            <p v-if="exportError" class="text-xs text-red-600">{{ exportError }}</p>
        </section>

        <section v-if="props.exports.length > 0" class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-medium">Exportações recentes</h2>
            <ul class="divide-y divide-border text-sm">
                <li v-for="item in props.exports" :key="item.ulid" class="flex items-center justify-between py-2">
                    <span class="text-muted-foreground">{{ item.created_at }}</span>
                    <a
                        v-if="item.ready"
                        :href="`/data-exports/${item.ulid}`"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                        <Download class="size-4" /> Descarregar
                    </a>
                    <span v-else class="text-xs text-muted-foreground">expirada</span>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">Cada exportação fica disponível durante 24 horas.</p>
        </section>
    </div>
</template>
