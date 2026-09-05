<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, CircleAlert, Minus } from '@lucide/vue';
import { computed } from 'vue';
import type {
    EvaluationSheetReadiness,
    EvaluationSheetReadinessAction,
    EvaluationSheetReadinessState,
    EvaluationSheetReadinessStudent,
} from '@/types';

/**
 * «Preparar fecho» — uma leitura, nunca uma segunda pauta.
 *
 * Este painel apenas APRESENTA o que o servidor leu da pauta: o que está
 * completo, o que merece um olhar, o que não se aplica. Não recalcula nada,
 * não decide nada e não impede nada — a decisão é sempre do professor (§3.3),
 * e uma pendência pedagógica nunca é um erro.
 *
 * TEXTO E ÍCONE À FRENTE DA COR. Cada estado comunica pela palavra e pelo
 * símbolo; a cor é apoio discreto e nunca o único sinal (WCAG, § briefing).
 */
const props = defineProps<{
    readiness: EvaluationSheetReadiness;
    classUlid: string;
    periodUlid: string;
}>();

const stateIcon = { ok: Check, attention: CircleAlert, neutral: Minus } as const;

const stateClass: Record<EvaluationSheetReadinessState, string> = {
    ok: 'text-emerald-700',
    attention: 'text-amber-700',
    neutral: 'text-muted-foreground',
};

/** O rótulo lido por quem não vê a cor — nunca só um símbolo. */
const stateSr: Record<EvaluationSheetReadinessState, string> = {
    ok: 'Sem pendências',
    attention: 'Ponto a verificar',
    neutral: 'Informativo / não aplicável',
};

/**
 * O destino de cada «Ver». O servidor nomeia o sítio ('classifications',
 * 'results', …) e o ecrã constrói o caminho — os gates verdadeiros vivem nas
 * rotas de destino, exatamente como para qualquer outro link da aplicação.
 */
function href(action: EvaluationSheetReadinessAction, student?: EvaluationSheetReadinessStudent): string | null {
    const base = `/classes/${props.classUlid}`;

    switch (action) {
        case 'classifications':
            return `${base}/classifications/${props.periodUlid}`;
        case 'results':
            return `${base}/results/${props.periodUlid}`;
        case 'self-assessments':
            return `${base}/self-assessments/${props.periodUlid}`;
        case 'self-assessment':
            return student?.enrollment_ulid
                ? `${base}/self-assessments/${props.periodUlid}/${student.enrollment_ulid}`
                : `${base}/self-assessments/${props.periodUlid}`;
        case 'inovar':
            return `${base}/pauta-avaliacao/inovar/${props.periodUlid}`;
        default:
            return null;
    }
}

const headline = computed(() => {
    const attention = props.readiness.summary.attention_count;

    if (attention === 0) {
        // Nunca «pronto para fechar» — decidir continua a ser do professor.
        return 'Sem pendências detetadas.';
    }

    return attention === 1 ? 'Há 1 ponto a verificar.' : `Há ${attention} pontos a verificar.`;
});
</script>

<template>
    <section
        id="preparacao-do-fecho"
        class="space-y-4 rounded-lg border border-border bg-muted/10 px-4 py-3"
        aria-label="Preparação do fecho do momento"
    >
        <div>
            <h2 class="text-sm font-semibold">
                <!-- O momento com a terminologia do próprio período — «Semestre»,
                     «Período», «Módulo» — nunca uma palavra fixa no código (§6). -->
                Preparação — {{ readiness.moment.kind_label }}: {{ readiness.moment.period_label }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ headline }}
                <span v-if="readiness.summary.students_with_notes > 0">
                    {{ readiness.summary.students_ready }} de {{ readiness.summary.students_total }} alunos sem
                    pendências.
                </span>
                <span v-else>{{ readiness.summary.students_total }} alunos na pauta.</span>
            </p>
            <p v-if="!readiness.moment.is_closing" class="text-xs text-muted-foreground">
                {{ readiness.moment.kind_label }} ainda a decorrer — decisões por tomar são apenas informativas.
            </p>
        </div>

        <!-- A checklist da turma: máximo três estados, sem semáforos a mais. -->
        <ul class="space-y-1.5">
            <li v-for="item in readiness.items" :key="item.key" class="flex items-start gap-2 text-sm">
                <component
                    :is="stateIcon[item.state]"
                    class="mt-0.5 size-4 shrink-0"
                    :class="stateClass[item.state]"
                    aria-hidden="true"
                />
                <span class="sr-only">{{ stateSr[item.state] }}:</span>
                <span>
                    {{ item.label }}
                    <span v-if="item.detail" class="text-muted-foreground">— {{ item.detail }}</span>
                    <Link
                        v-if="href(item.action)"
                        :href="href(item.action)!"
                        class="ml-1 text-primary hover:underline"
                    >
                        Ver
                    </Link>
                </span>
            </li>
        </ul>

        <!-- Quem tem pontos a verificar, e onde resolver cada um. Só aparecem
             alunos COM pendências — uma lista de 26 «tudo em ordem» não diz
             nada que o resumo não diga melhor. -->
        <div v-if="readiness.students.length > 0" class="space-y-2 border-t border-border pt-3">
            <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">Alunos com pontos a verificar</h3>
            <ul class="space-y-2">
                <!-- Pela posição, não pelo nome: dois alunos podem chamar-se o
                     mesmo, e o ulid pode faltar se a matrícula não resolver. -->
                <li v-for="(student, position) in readiness.students" :key="position" class="text-sm">
                    <span class="font-medium">
                        <span v-if="student.class_number != null" class="text-muted-foreground">{{ student.class_number }} ·</span>
                        {{ student.name }}
                    </span>
                    <ul class="mt-0.5 space-y-0.5 pl-4">
                        <li v-for="(entry, index) in student.pending" :key="index" class="flex items-start gap-2">
                            <component
                                :is="stateIcon[entry.state]"
                                class="mt-0.5 size-3.5 shrink-0"
                                :class="stateClass[entry.state]"
                                aria-hidden="true"
                            />
                            <span class="sr-only">{{ stateSr[entry.state] }}:</span>
                            <span>
                                {{ entry.label }}
                                <Link
                                    v-if="href(entry.action, student)"
                                    :href="href(entry.action, student)!"
                                    class="ml-1 text-primary hover:underline"
                                >
                                    Resolver
                                </Link>
                            </span>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- A regra de produto, dita ao professor nas palavras da regra (§3.3):
             avisar, nunca impedir. -->
        <p class="border-t border-border pt-2 text-xs text-muted-foreground">
            O Lapispro informa e alerta; nada nesta lista impede o fecho. A decisão é sempre do professor.
        </p>
    </section>
</template>
