<script setup lang="ts">
import { CalendarCheck, CalendarX, ListOrdered, ShieldCheck, X } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { gapsLabel, plural, RENUMBERING_REASON } from '@/lib/calendarReconciliation';
import type { ReconciliationPlan } from '@/lib/calendarReconciliation';

/**
 * O QUE ACONTECE ÀS AULAS JÁ CRIADAS quando se cria, altera ou elimina uma
 * interrupção do calendário — mostrado ANTES de gravar, ao responsável. Trata-o
 * formalmente («Veja», «abrir»), como o resto da página do ano letivo.
 *
 * O responsável vê contagens por turma, nunca aulas individuais nem texto
 * pedagógico. Por palavras: o que vai ser corrigido (aulas vazias retiradas), o
 * que vai ser preservado (planeamento e registos nunca se apagam), a numeração
 * (que turmas têm aulas por dar a mudar de número, e porquê) e o que precisa
 * de revisão — por turma e por razão, com o nome do outro grupo de uma lição
 * partilhada. Nada é gravado enquanto este diálogo está aberto: os botões
 * entregam a decisão a quem o abriu.
 *
 * O QUE SE DIZ DEPENDE DA OPERAÇÃO E DO PLANO, nunca de uma frase fixa:
 * eliminar não retira aula nenhuma; encurtar liberta dias mas RETIRA as aulas
 * vazias que ainda caiam nos dias que ficam — por isso «nenhuma aula é
 * apagada» só se diz quando o plano não retira nenhuma.
 *
 * O FOCO, AO FECHAR, VOLTA A QUEM GRAVOU (`returnFocus`): o diálogo abre depois
 * de um pedido assíncrono, e sem isto o foco caía no primeiro botão da página.
 */
const props = defineProps<{
    plan: ReconciliationPlan | null;
    /** A pré-visualização está a ser (re)calculada. */
    loading?: boolean;
    /** Um pedido de gravação está a decorrer. */
    busy?: boolean;
    /** A razão da última recusa (por exemplo, o plano ficou obsoleto). */
    message?: string | null;
    /** O elemento que recebe o foco quando o diálogo fecha (o «Guardar» do formulário). */
    returnFocus?: HTMLElement | null;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: []; saveOnly: [] }>();

const totals = computed(() => props.plan?.totals ?? { retired: 0, shifted: 0, awaiting_teacher: 0, flagged: 0 });
const withPlanning = computed(() => totals.value.shifted + totals.value.awaiting_teacher);
const freed = computed(() => props.plan?.freed_days.length ?? 0);
const existingOnFreed = computed(() => props.plan?.existing_on_freed_days.count ?? 0);
const canApply = computed(() => props.plan !== null && props.plan.changes_anything && !props.loading && !props.busy);
// Gravar sem alterar as aulas não precisa do plano — também quando a nova
// verificação falhou e o plano deixou de valer.
const canSave = computed(() => !props.loading && !props.busy);

const classesWith = (key: 'retired' | 'awaiting_teacher' | 'flagged') =>
    (props.plan?.classes ?? []).filter((item) => item[key] > 0);

const planningByClass = computed(() =>
    (props.plan?.classes ?? []).filter((item) => item.shifted + item.awaiting_teacher > 0),
);

/** As turmas com aulas por dar a mudar de número, ou com números que ficam sem aula. */
const numberingByClass = computed(() => {
    const gaps = new Map((props.plan?.gaps ?? []).map((item) => [item.class ?? item.class_label, item.numbers]));

    return (props.plan?.classes ?? [])
        .map((item) => ({
            key: item.class ?? item.class_label,
            label: item.class_label,
            renumbered: item.renumbered,
            gaps: gaps.get(item.class ?? item.class_label) ?? item.gaps,
        }))
        .filter((item) => item.renumbered > 0 || item.gaps.length > 0);
});

/** «7.º A: 1 aula (faz parte de uma lição partilhada com o grupo T2, que continua a ter aula)». */
function reviewLines(item: { class_label: string; flagged: number; review?: { count: number; label: string }[] }): string[] {
    if (!item.review || item.review.length === 0) {
        return [`${item.class_label}: ${plural(item.flagged, 'aula', 'aulas')}`];
    }

    return item.review.map((entry) => `${item.class_label}: ${plural(entry.count, 'aula', 'aulas')} (${entry.label})`);
}

function onCloseAutoFocus(event: Event): void {
    const target = props.returnFocus;

    if (target && document.body.contains(target)) {
        event.preventDefault();
        target.focus();
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90vh] overflow-y-auto pb-0 sm:max-w-xl"
            :show-close-button="false"
            data-testid="impact-dialog"
            @close-auto-focus="onCloseAutoFocus"
        >
            <!-- O «Fechar» do canto, com alvo de 44 px (o do componente partilhado tem 16 px). -->
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="absolute top-2 right-2 size-11"
                aria-label="Fechar"
                data-testid="impact-x"
                @click="open = false"
            >
                <X class="size-4" aria-hidden="true" />
            </Button>

            <DialogHeader class="space-y-2 pr-8">
                <DialogTitle>O que acontece às aulas já criadas</DialogTitle>
                <DialogDescription>
                    Há aulas desta organização nos dias afetados. Veja o que muda antes de gravar — nada é
                    gravado até confirmar.
                </DialogDescription>
            </DialogHeader>

            <Alert v-if="message" variant="destructive" data-testid="impact-message">
                <AlertDescription>{{ message }}</AlertDescription>
            </Alert>

            <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                <Spinner /> A calcular o que acontece…
            </p>

            <div v-if="plan && !loading" class="space-y-4 text-sm" data-testid="impact-preview">
                <section
                    v-if="totals.retired > 0"
                    class="space-y-2 rounded-lg border p-3"
                    aria-labelledby="impact-fixed"
                    data-testid="impact-fixed"
                >
                    <h3 id="impact-fixed" class="flex items-center gap-2 font-semibold">
                        <CalendarCheck class="size-4" aria-hidden="true" /> Vai ser corrigido
                    </h3>
                    <p>
                        {{ plural(totals.retired, 'aula vazia é retirada', 'aulas vazias são retiradas') }}:
                        {{ totals.retired === 1 ? 'não tem' : 'não têm' }} nada escrito, por isso não se perde nada.
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in classesWith('retired')" :key="item.class ?? item.class_label">
                            {{ item.class_label }}: {{ plural(item.retired, 'aula', 'aulas') }}
                        </li>
                    </ul>
                    <p class="text-muted-foreground">
                        Se a interrupção for removida mais tarde, as aulas voltam a aparecer quando abrir a semana.
                    </p>
                </section>

                <section
                    v-if="freed > 0"
                    class="space-y-1 rounded-lg border p-3"
                    aria-labelledby="impact-freed"
                    data-testid="impact-freed"
                >
                    <h3 id="impact-freed" class="flex items-center gap-2 font-semibold">
                        <CalendarX class="size-4" aria-hidden="true" /> Dias que voltam a ser letivos
                    </h3>
                    <p>
                        {{ plural(freed, 'dia volta', 'dias voltam') }} a ser letivo{{ freed === 1 ? '' : 's' }}. As
                        aulas aparecem quando abrir as semanas<template v-if="totals.retired === 0">; nenhuma aula é apagada</template>.
                    </p>
                    <p v-if="existingOnFreed > 0">
                        {{ plural(existingOnFreed, 'aula já existe', 'aulas já existem') }} nesses dias e
                        {{ existingOnFreed === 1 ? 'continua' : 'continuam' }} como
                        {{ existingOnFreed === 1 ? 'está' : 'estão' }}.
                    </p>
                </section>

                <section class="space-y-2 rounded-lg border p-3" aria-labelledby="impact-kept" data-testid="impact-kept">
                    <h3 id="impact-kept" class="flex items-center gap-2 font-semibold">
                        <ShieldCheck class="size-4" aria-hidden="true" /> Vai ser preservado
                    </h3>
                    <p>Nenhum planeamento, sumário ou registo é apagado.</p>
                    <template v-if="withPlanning > 0">
                        <p data-testid="impact-planning">
                            {{ plural(withPlanning, 'aula tem planeamento', 'aulas têm planeamento') }} e
                            {{ withPlanning === 1 ? 'fica como está' : 'ficam como estão' }}. O planeamento não se
                            apaga: quem leciona a turma passa-o para a aula seguinte em «Aulas e Sumários». Esta
                            correção não mexe nos planos dos outros professores.
                        </p>
                        <ul class="space-y-0.5">
                            <li v-for="item in planningByClass" :key="item.class ?? item.class_label">
                                {{ item.class_label }}: {{ plural(item.shifted + item.awaiting_teacher, 'aula', 'aulas') }}
                            </li>
                        </ul>
                    </template>
                </section>

                <section
                    v-if="numberingByClass.length > 0"
                    class="space-y-2 rounded-lg border p-3"
                    aria-labelledby="impact-numbering"
                    data-testid="impact-numbering"
                >
                    <h3 id="impact-numbering" class="flex items-center gap-2 font-semibold">
                        <ListOrdered class="size-4" aria-hidden="true" /> Atenção à numeração
                    </h3>
                    <ul class="space-y-0.5">
                        <li v-for="item in numberingByClass" :key="item.key" data-testid="impact-numbering-class">
                            {{ item.label }}:
                            <template v-if="item.renumbered > 0">{{ plural(item.renumbered, 'aula por dar muda', 'aulas por dar mudam') }} de número</template><template v-if="item.renumbered > 0 && item.gaps.length > 0">; </template><template v-if="item.gaps.length > 0">{{ gapsLabel(item.gaps) }} {{ item.gaps.length === 1 ? 'fica' : 'ficam' }} sem aula</template>.
                        </li>
                    </ul>
                    <p class="text-muted-foreground">{{ RENUMBERING_REASON }}</p>
                </section>

                <section
                    v-if="totals.flagged > 0"
                    class="space-y-2 rounded-lg border p-3"
                    aria-labelledby="impact-review"
                    data-testid="impact-review"
                >
                    <h3 id="impact-review" class="font-semibold">Precisa de revisão</h3>
                    <p>
                        {{ plural(totals.flagged, 'aula nesses dias fica', 'aulas nesses dias ficam') }}
                        como {{ totals.flagged === 1 ? 'está' : 'estão' }}: não
                        {{ totals.flagged === 1 ? 'pode' : 'podem' }} ser {{ totals.flagged === 1 ? 'corrigida' : 'corrigidas' }}
                        automaticamente. Os professores das turmas veem-{{ totals.flagged === 1 ? 'na' : 'nas' }} em
                        «Aulas e Sumários» e decidem.
                    </p>
                    <ul class="space-y-0.5">
                        <template v-for="item in classesWith('flagged')" :key="item.class ?? item.class_label">
                            <li v-for="(line, index) in reviewLines(item)" :key="`${item.class ?? item.class_label}-${index}`" data-testid="impact-review-line">
                                {{ line }}
                            </li>
                        </template>
                    </ul>
                </section>

                <p v-if="!plan.changes_anything" data-testid="impact-nothing" class="text-muted-foreground">
                    Neste momento não há aulas a corrigir automaticamente.
                </p>
            </div>

            <!-- Rodapé fixo: os botões ficam à vista com o resumo comprido. -->
            <DialogFooter class="sticky bottom-0 -mx-6 gap-2 border-t bg-background px-6 py-4 sm:flex-wrap" data-testid="impact-footer">
                <Button type="button" variant="outline" class="min-h-11" data-testid="impact-cancel" @click="open = false">
                    Cancelar
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    class="min-h-11"
                    data-testid="impact-save-only"
                    :disabled="!canSave"
                    @click="emit('saveOnly')"
                >
                    Guardar sem alterar as aulas
                </Button>
                <Button
                    v-if="plan === null || plan.changes_anything"
                    type="button"
                    class="min-h-11"
                    data-testid="impact-confirm"
                    :disabled="!canApply"
                    @click="emit('confirm')"
                >
                    <Spinner v-if="busy" />
                    Confirmar e aplicar às aulas
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
