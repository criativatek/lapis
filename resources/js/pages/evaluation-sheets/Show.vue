<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import EvaluationSheetTable from '@/components/evaluation-sheets/EvaluationSheetTable.vue';
import EvaluationSheetViewControls from '@/components/evaluation-sheets/EvaluationSheetViewControls.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { EvaluationSheet, EvaluationSheetPeriod, EvaluationSheetSaveDefaults } from '@/types';

/**
 * Pautas de Avaliação — UMA ÚNICA VISTA.
 *
 * Abre com tudo visível: quantitativo, apreciação qualitativa por domínio, e
 * classificação sugerida vs. decidida. Os toggles SÓ ESCONDEM — nunca
 * recalculam nada nem alteram o payload recebido do servidor, que permanece
 * intacto em `props.sheet` do início ao fim da visita.
 *
 * A grelha é o MESMO componente que a pauta guardada usa: é isso que garante
 * que uma fotografia se lê com as mesmas colunas, cores e estrutura do ecrã de
 * onde foi tirada.
 */

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; academic_year: string; has_profile: boolean };
    periods: EvaluationSheetPeriod[];
    sheet: EvaluationSheet | null;
    saveDefaults?: EvaluationSheetSaveDefaults | null;
}>();

const selectedPeriod = computed<EvaluationSheetPeriod | null>(
    () => props.periods.find((period) => period.selected) ?? null,
);

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/pauta-avaliacao/${ulid}`, {}, { preserveScroll: true });
}

// ------------------------------------------------------------- apresentação
//
// SÓ CLIENT-SIDE. Nenhum destes refs viaja para o servidor nem altera o que é
// pedido — ocultar um grupo é puramente visual (§ briefing, regra central).

const showQuantitative = ref(true);
const showDomainDetail = ref(true);
const showWarnings = ref(true);

// ------------------------------------------------------------ guardar pauta
//
// O título e a data são SUGESTÕES editáveis. O título vem da configuração
// temporal do próprio período («Semestre — 1.º Semestre»), nunca de uma
// palavra escrita à mão no código; a data é validada no servidor contra o
// `starts_on`/`ends_on` do período, e os limites são mostrados aqui para que o
// professor não seja recusado só depois de carregar no botão.

const showSaveForm = ref(false);

const saveForm = useForm({
    moment_label: props.saveDefaults?.moment_label ?? '',
    effective_at: props.saveDefaults?.effective_at ?? '',
});

const canSave = computed(() => props.saveDefaults !== null && props.saveDefaults !== undefined && props.sheet !== null);

function submitSave(): void {
    if (!props.saveDefaults) {
        return;
    }

    saveForm.post(`/classes/${props.schoolClass.ulid}/pauta-avaliacao/${props.saveDefaults.period_ulid}/guardar`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Pauta de Avaliação — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading
                    :title="`Pauta de Avaliação — ${schoolClass.label}`"
                    :description="
                        selectedPeriod
                            ? `${schoolClass.subject} · ${selectedPeriod.kind_label} selecionado: ${selectedPeriod.label}`
                            : schoolClass.subject
                    "
                />
                <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>

            <div v-if="periods.length" class="flex flex-wrap gap-1">
                <button
                    v-for="period in periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                    :title="period.kind_label"
                    @click="selectPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button
                v-if="canSave"
                type="button"
                class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90"
                @click="showSaveForm = !showSaveForm"
            >
                Guardar esta pauta
            </button>
            <Link
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico`"
                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            >
                Histórico
            </Link>
        </div>

        <form
            v-if="canSave && showSaveForm"
            class="space-y-3 rounded-lg border border-border bg-muted/10 px-4 py-3"
            @submit.prevent="submitSave"
        >
            <p class="text-sm text-muted-foreground">
                Guardar cria uma fotografia imutável desta pauta. O que estiver no ecrã fica registado tal como
                está agora — alterações posteriores às notas ou às classificações não mexem no que foi guardado.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="moment-label" class="text-sm font-medium">Título do momento</label>
                    <input
                        id="moment-label"
                        v-model="saveForm.moment_label"
                        type="text"
                        maxlength="200"
                        required
                        class="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                    />
                    <InputError :message="saveForm.errors.moment_label" />
                </div>

                <div class="space-y-1">
                    <label for="effective-at" class="text-sm font-medium">Data de referência</label>
                    <input
                        id="effective-at"
                        v-model="saveForm.effective_at"
                        type="date"
                        required
                        :min="saveDefaults?.starts_on"
                        :max="saveDefaults?.ends_on"
                        class="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                    />
                    <p v-if="saveDefaults" class="text-xs text-muted-foreground">
                        Entre {{ saveDefaults.starts_on }} e {{ saveDefaults.ends_on }}.
                    </p>
                    <InputError :message="saveForm.errors.effective_at" />
                </div>
            </div>

            <div class="flex gap-2">
                <button
                    type="submit"
                    :disabled="saveForm.processing"
                    class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                    {{ saveForm.processing ? 'A guardar…' : 'Guardar' }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                    @click="showSaveForm = false"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <!-- Controlos de visualização: só apresentação, nunca alteram os dados
             recebidos do servidor. -->
        <EvaluationSheetViewControls
            v-model:show-quantitative="showQuantitative"
            v-model:show-domain-detail="showDomainDetail"
            v-model:show-warnings="showWarnings"
        />

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há pauta a mostrar.
        </p>

        <EmptyState
            v-else-if="sheet === null || sheet.students.length === 0"
            title="Sem alunos ou sem resultados neste período."
            :icon="Table2"
        />

        <EvaluationSheetTable
            v-else
            :domains="sheet.domains"
            :students="sheet.students"
            :show-quantitative="showQuantitative"
            :show-domain-detail="showDomainDetail"
            :show-warnings="showWarnings"
        />

        <p class="text-xs text-muted-foreground">
            "—" significa sem elementos, nunca zero. O ícone de aviso assinala cobertura parcial ou a ausência de
            elementos avaliados — passe o rato ou o foco por cima para ver o detalhe. Um nível em itálico é a
            <strong>proposta</strong> do Lapispro, ainda não decidida; um nível a negrito é a
            <strong>decisão</strong> do professor.
        </p>
    </div>
</template>
