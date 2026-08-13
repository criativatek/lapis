<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';

type Row = { name: string; status_label: string | null; link: string };

defineProps<{
    schoolClass: { ulid: string; label: string };
    period: { ulid: string; label: string };
    expiresAt: string;
    rows: Row[];
}>();

const copiedIndex = ref<number | null>(null);

async function copy(link: string, index: number): Promise<void> {
    await navigator.clipboard.writeText(link);
    copiedIndex.value = index;
    setTimeout(() => {
        if (copiedIndex.value === index) {
            copiedIndex.value = null;
        }
    }, 2000);
}

function expiresLabel(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head :title="`Ligações — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-4 p-4">
        <div>
            <Heading :title="`Ligações para os alunos — ${schoolClass.label}`" :description="period.label" />
            <Link :href="`/classes/${schoolClass.ulid}/self-assessments/${period.ulid}`" class="text-sm text-muted-foreground hover:underline">
                ← Voltar à turma
            </Link>
        </div>

        <p class="text-xs text-muted-foreground">
            Cada aluno tem a sua própria ligação — não precisa de conta nem password, mas deixa de funcionar a partir de {{ expiresLabel(expiresAt) }}.
            Partilha-a como preferires: projetada, colada no Teams, ou enviada individualmente.
        </p>

        <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
            <li v-for="(row, index) in rows" :key="row.link" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ row.name }}</span>
                        <span v-if="row.status_label" class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ row.status_label }}</span>
                    </div>
                    <input
                        :value="row.link"
                        readonly
                        class="mt-1 w-full truncate rounded-md border border-border bg-muted/20 px-2 py-1 text-xs text-muted-foreground"
                        @focus="($event.target as HTMLInputElement).select()"
                    />
                </div>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                    @click="copy(row.link, index)"
                >
                    <Check v-if="copiedIndex === index" class="size-4 text-emerald-600" />
                    <Copy v-else class="size-4" />
                    {{ copiedIndex === index ? 'Copiado' : 'Copiar' }}
                </button>
            </li>
        </ul>
    </div>
</template>
