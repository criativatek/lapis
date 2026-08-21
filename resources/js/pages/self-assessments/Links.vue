<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Check, Copy, Printer } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';

type Row = { name: string; status_label: string | null; link: string };

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
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

const copiedAll = ref(false);

async function copyAll(): Promise<void> {
    const text = props.rows.map((row) => `${row.name} — ${row.link}`).join('\n');
    await navigator.clipboard.writeText(text);
    copiedAll.value = true;
    setTimeout(() => {
        copiedAll.value = false;
    }, 2000);
}

function expiresLabel(iso: string): string {
    return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' });
}

// Every dynamic value interpolated into the print window's HTML string goes
// through this — that HTML is built by hand (a Blob, not Vue's template
// compiler), so nothing here gets Vue's usual automatic escaping.
function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function openPrintWindow(): void {
    const generatedAt = new Date().toLocaleDateString('pt-PT', { day: '2-digit', month: 'long', year: 'numeric' });
    const rowsHtml = props.rows
        .map((row) => {
            const name = escapeHtml(row.name);
            const link = escapeHtml(row.link);

            return `<li><span class="name">${name}</span><a href="${link}">${link}</a></li>`;
        })
        .join('');

    const html = `<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<title>${escapeHtml(`Ligações — ${props.schoolClass.label}`)}</title>
<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #18181b; margin: 2rem; }
    h1 { font-size: 1.25rem; margin: 0 0 0.25rem; }
    p.meta { color: #52525b; font-size: 0.875rem; margin: 0 0 0.25rem; }
    p.note { color: #52525b; font-size: 0.75rem; margin: 1rem 0; }
    ul { list-style: none; padding: 0; margin: 1rem 0 0; }
    li { padding: 0.5rem 0; border-bottom: 1px solid #e4e4e7; }
    li .name { display: block; font-weight: 600; font-size: 0.875rem; }
    li a { display: block; font-size: 0.75rem; color: #3f3f46; word-break: break-all; }
</style>
</head>
<body>
<h1>${escapeHtml(`Ligações para os alunos — ${props.schoolClass.label}`)}</h1>
<p class="meta">${escapeHtml(props.schoolClass.subject)} · ${escapeHtml(props.period.label)}</p>
<p class="meta">Gerado em ${escapeHtml(generatedAt)}</p>
<p class="note">Cada ligação é individual e válida durante 7 dias (até ${escapeHtml(expiresLabel(props.expiresAt))}) — não é preciso conta nem palavra-passe.</p>
<ul>${rowsHtml}</ul>
</body>
</html>`;

    const blobUrl = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
    const printWindow = window.open(blobUrl, '_blank');

    if (printWindow === null) {
        URL.revokeObjectURL(blobUrl);

        return;
    }

    printWindow.addEventListener('load', () => {
        printWindow.focus();
        printWindow.print();
        URL.revokeObjectURL(blobUrl);
    });
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
            Cada aluno tem a sua própria ligação — não precisa de conta nem palavra-passe, mas deixa de funcionar a partir de {{ expiresLabel(expiresAt) }}.
            Partilha-a como preferires: projetada, colada no Teams, ou enviada individualmente.
        </p>

        <div class="flex gap-2">
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                @click="copyAll"
            >
                <Check v-if="copiedAll" class="size-4 text-emerald-600" />
                <Copy v-else class="size-4" />
                {{ copiedAll ? 'Copiado' : 'Copiar tudo' }}
            </button>
            <button
                type="button"
                class="flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                @click="openPrintWindow"
            >
                <Printer class="size-4" />
                Imprimir / Guardar PDF
            </button>
        </div>

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
