<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, History as HistoryIcon } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import type { EvaluationSheetHistoryEntry } from '@/types';

/**
 * Pautas guardadas — a lista, por ordem inversa de criação.
 *
 * NADA É FUNDIDO. Guardar três vezes o mesmo momento produz três registos, e
 * os três aparecem: uma pauta guardada é a prova do que estava no ecrã naquele
 * instante, e uma prova que desaparece porque chegou outra não é prova nenhuma.
 *
 * «MAIS RECENTE» NÃO É UMA COLUNA NA BASE DE DADOS. É simplesmente o primeiro
 * item desta lista já ordenada pelo servidor; marcá-lo aqui não pode entrar em
 * contradição com o registo, como uma flag persistida entraria.
 *
 * A unidade temporal («Semestre — 1.º Semestre») vem do SNAPSHOT, não da
 * configuração atual: mudar o nome de um período não reescreve o que a
 * história diz sobre o passado.
 */

defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    entries: EvaluationSheetHistoryEntry[];
}>();

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' });
const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatDate(value: string | null): string {
    return value === null ? '—' : dateFormatter.format(new Date(value));
}

function formatDateTime(value: string): string {
    return dateTimeFormatter.format(new Date(value));
}

/** «Semestre — 1.º Semestre», tal como estava guardado. Nunca uma palavra fixa. */
function temporalUnit(entry: EvaluationSheetHistoryEntry): string {
    const parts = [entry.period_kind_label, entry.period_label].filter((part): part is string => Boolean(part));

    return parts.length ? parts.join(' — ') : '—';
}

function statusClasses(entry: EvaluationSheetHistoryEntry): string {
    if (entry.warning_count > 0) {
        return 'border-amber-300 bg-amber-50 text-amber-900';
    }

    return entry.has_file ? 'border-sky-300 bg-sky-50 text-sky-900' : 'border-border bg-muted/40 text-muted-foreground';
}
</script>

<template>
    <Head :title="`Pautas guardadas — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div>
            <Heading
                :title="`Pautas guardadas — ${schoolClass.label}`"
                :description="schoolClass.subject"
            />
            <Link
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao`"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← Voltar à pauta atual
            </Link>
        </div>

        <EmptyState
            v-if="entries.length === 0"
            title="Ainda não há pautas guardadas nesta turma."
            description="Guarde uma pauta a partir do ecrã da pauta atual para começar o histórico."
            :icon="HistoryIcon"
        />

        <ul v-else class="space-y-3">
            <li
                v-for="(entry, index) in entries"
                :key="entry.ulid"
                class="rounded-lg border border-border bg-background p-4"
                :class="index === 0 ? 'border-primary/50 ring-1 ring-primary/20' : ''"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <Link
                                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico/${entry.ulid}`"
                                class="font-medium hover:underline"
                            >
                                {{ entry.moment_label }}
                            </Link>
                            <!-- Só o primeiro da lista já ordenada. Nada disto
                                 está persistido — ver o bloco no topo. -->
                            <span
                                v-if="index === 0"
                                class="rounded-full border border-primary/40 bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                            >
                                Mais recente
                            </span>
                        </div>

                        <p class="text-sm text-muted-foreground">
                            {{ temporalUnit(entry) }} · {{ entry.scope_label }}
                        </p>

                        <dl class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                            <div class="flex gap-1">
                                <dt>Data de referência:</dt>
                                <dd class="font-medium text-foreground">{{ formatDate(entry.effective_at) }}</dd>
                            </div>
                            <div class="flex gap-1">
                                <dt>Guardada em:</dt>
                                <dd class="font-medium text-foreground">{{ formatDateTime(entry.exported_at) }}</dd>
                            </div>
                            <div class="flex gap-1">
                                <dt>Por:</dt>
                                <dd class="font-medium text-foreground">{{ entry.author ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex flex-col items-end gap-1">
                        <span
                            class="rounded-full border px-2 py-0.5 text-[11px] font-medium"
                            :class="statusClasses(entry)"
                        >
                            {{ entry.status_label }}
                        </span>
                        <span v-if="entry.warning_count > 0" class="text-[11px] text-amber-800">
                            {{ entry.warning_count }} {{ entry.warning_count === 1 ? 'aviso' : 'avisos' }}
                        </span>
                        <!-- UMA NAVEGAÇÃO NORMAL, não uma visita Inertia: um
                             ficheiro não volta por uma visita Inertia — o
                             cliente exige uma resposta Inertia e deita fora
                             tudo o resto, e o botão pareceria não fazer nada.
                             O ficheiro vive em disco privado e esta rota é a
                             única porta: autoriza a turma e confirma que o
                             registo é dela. -->
                        <a
                            v-if="entry.has_file"
                            :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico/${entry.ulid}/ficheiro`"
                            class="mt-1 inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted/40"
                        >
                            <Download class="size-3.5" />
                            Grelha do Inovar
                        </a>

                        <!-- ESTE MOMENTO, EM FICHEIRO. Gerado a partir do que
                             ficou guardado e de mais nada: alterar a pauta atual
                             depois disto não muda uma célula do que sai daqui.
                             Coisa diferente da grelha do Inovar acima — esta é
                             para arquivar e para ler. -->
                        <div class="mt-1 flex items-center gap-1">
                            <span class="text-[11px] text-muted-foreground">Exportar:</span>
                            <a
                                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico/${entry.ulid}/csv`"
                                class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted/40"
                                :aria-label="`Exportar ${entry.moment_label} em CSV`"
                            >
                                <Download class="size-3.5" />
                                CSV
                            </a>
                            <a
                                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico/${entry.ulid}/xlsx`"
                                class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-xs font-medium hover:bg-muted/40"
                                :aria-label="`Exportar ${entry.moment_label} em Excel`"
                            >
                                <Download class="size-3.5" />
                                Excel
                            </a>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <p class="text-xs text-muted-foreground">
            Cada pauta guardada é imutável: mostra os valores tal como estavam no momento em que foi guardada,
            e nunca é atualizada por alterações posteriores. Várias pautas do mesmo momento aparecem todas —
            nenhuma substitui outra.
        </p>
    </div>
</template>
