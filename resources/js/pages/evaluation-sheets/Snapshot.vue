<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import EvaluationSheetTable from '@/components/evaluation-sheets/EvaluationSheetTable.vue';
import EvaluationSheetViewControls from '@/components/evaluation-sheets/EvaluationSheetViewControls.vue';
import Heading from '@/components/Heading.vue';
import type { EvaluationSheetHistoryEntry, EvaluationSheetSnapshot } from '@/types';

/**
 * Uma pauta guardada, tal como estava.
 *
 * TUDO O QUE SE VÊ AQUI VEM DO SNAPSHOT. Os nomes dos alunos, os domínios, as
 * cores, os níveis, a unidade temporal — nada é lido da configuração atual nem
 * recalculado. Reconstruir a pauta a partir dos dados de hoje seria perguntar
 * ao presente o que o passado dizia, que é exatamente o que esta funcionalidade
 * existe para evitar.
 *
 * SÓ LEITURA. Não há aqui um único controlo que escreva: os toggles escondem
 * colunas e mais nada.
 */

defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    entry: EvaluationSheetHistoryEntry;
    snapshot: EvaluationSheetSnapshot | null;
    integrityFailure: string | null;
}>();

const showQuantitative = ref(true);
const showDomainDetail = ref(true);
const showWarnings = ref(true);

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
</script>

<template>
    <Head :title="`${entry.moment_label} — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div>
            <Heading
                :title="entry.moment_label"
                :description="
                    snapshot
                        ? `${snapshot.class.label} · ${snapshot.class.subject} · ${snapshot.period.kind_label} — ${snapshot.period.label}`
                        : schoolClass.subject
                "
            />
            <Link
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico`"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← Voltar ao histórico
            </Link>
        </div>

        <!-- Identificado como histórico, sem ambiguidade possível: quem abre
             esta página tem de perceber ao primeiro olhar que não está a ver a
             pauta atual. -->
        <div class="rounded-lg border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <p class="font-medium">Pauta guardada — vista histórica, só de leitura.</p>
            <p class="mt-1">
                Mostra a pauta tal como estava a <strong>{{ formatDate(entry.effective_at) }}</strong
                >. Guardada em {{ formatDateTime(entry.exported_at) }} por {{ entry.author ?? '—' }}.
                Alterações posteriores às notas ou às classificações não mudam nada nesta página.
            </p>
            <p v-if="snapshot" class="mt-1">
                Unidade temporal registada: <strong>{{ snapshot.period.kind_label }} — {{ snapshot.period.label }}</strong
                >. Âmbito: {{ entry.scope_label }}.
            </p>
        </div>

        <div
            v-if="integrityFailure"
            class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900"
        >
            <p class="font-medium">Esta pauta guardada não pôde ser mostrada.</p>
            <p class="mt-1">{{ integrityFailure }}</p>
        </div>

        <template v-else-if="snapshot">
            <div
                v-if="snapshot.warnings.length"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <p class="font-medium">
                    Avisos registados no momento em que esta pauta foi guardada
                    ({{ snapshot.warnings.length }}):
                </p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    <li v-for="(warning, index) in snapshot.warnings" :key="index">{{ warning }}</li>
                </ul>
            </div>

            <EvaluationSheetViewControls
                v-model:show-quantitative="showQuantitative"
                v-model:show-domain-detail="showDomainDetail"
                v-model:show-warnings="showWarnings"
            />

            <EvaluationSheetTable
                :domains="snapshot.domains"
                :students="snapshot.students"
                :show-quantitative="showQuantitative"
                :show-domain-detail="showDomainDetail"
                :show-warnings="showWarnings"
            />

            <p class="text-xs text-muted-foreground">
                "—" significa sem elementos, nunca zero. Os nomes, domínios, cores e níveis desta página são os
                que estavam registados no momento em que a pauta foi guardada.
            </p>
        </template>
    </div>
</template>
