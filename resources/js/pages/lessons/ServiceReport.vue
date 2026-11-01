<script setup lang="ts">
/**
 * SERVIÇO LETIVO — o relatório pessoal do professor sobre as aulas de uma
 * turma: previstas, dadas, não realizadas, por registar, a percentagem de
 * aulas dadas, as causas e a evolução mensal. Só leitura.
 *
 * Percentagem de aulas dadas = dadas ÷ previstas × 100, com as não realizadas
 * no denominador. As aulas ainda sem registo vêm à parte, para que a
 * percentagem não seja lida como final durante o período letivo.
 *
 * A causa agregada («Greve») é da turma; a especificação perante a greve só
 * chega ao professor que a registou — o servidor decide, a página só mostra.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Cause = { cause: string; label: string; count: number };

type Report = {
    totals: {
        planned: number;
        counted_as_taught: number;
        subject_development: number;
        teacher_absent: number;
        class_external_activity: number;
        not_held: number;
        not_recorded: number;
    };
    given_percentage: number | null;
    upcoming: number;
    causes: Cause[];
    evolution: {
        month: string;
        planned: number;
        given: number;
        not_held: number;
        not_recorded: number;
        given_percentage: number | null;
    }[];
    personal: {
        not_held_recorded: number;
        not_held_by_others: number;
        causes: Cause[];
        strike: { total: number; unspecified: number; adhered: number; school_closed: number; not_adhered: number; not_informed: number };
        teacher_absent: number;
    };
};

const props = defineProps<{
    classes: { ulid: string; label: string; subject: string }[];
    selectedClass: string | null;
    filters: { from: string | null; to: string | null };
    academicYear: string | null;
    report: Report | null;
}>();

const percentageLabel = computed(() =>
    props.report?.given_percentage == null ? '—' : `${props.report.given_percentage.toLocaleString('pt-PT')} %`,
);

const strikeLines = computed(() => {
    const strike = props.report?.personal.strike;

    if (!strike || strike.total === 0) {
        return [];
    }

    return [
        ['Só «Greve», sem especificar', strike.unspecified],
        ['Greve do professor', strike.adhered],
        ['Greve — escola encerrada', strike.school_closed],
        ['Não aderi à greve (registos anteriores)', strike.not_adhered],
        ['Situação desconhecida (registos anteriores)', strike.not_informed],
    ].filter(([, count]) => Number(count) > 0) as [string, number][];
});

const monthLabel = (month: string): string => {
    const [year, number] = month.split('-');

    return new Date(Number(year), Number(number) - 1, 1).toLocaleDateString('pt-PT', { month: 'long', year: 'numeric' });
};

function visit(params: Record<string, string | null>): void {
    router.get(
        '/lessons/service-report',
        {
            class: props.selectedClass,
            from: props.filters.from,
            to: props.filters.to,
            ...params,
        },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
</script>

<template>
    <Head title="Serviço letivo" />

    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 pb-24 sm:p-6">
        <Button as-child variant="ghost" class="-ml-3 min-h-11">
            <Link href="/lessons">
                <ArrowLeft class="size-4" />
                Voltar às aulas
            </Link>
        </Button>

        <Heading
            title="Serviço letivo"
            :description="`As aulas previstas, dadas e não realizadas de uma turma${academicYear ? ` — ${academicYear}` : ''}.`"
        />

        <EmptyState
            v-if="classes.length === 0"
            title="Ainda não tens turmas neste ano"
            description="Quando lecionares uma turma, o serviço letivo aparece aqui."
            :icon="ClipboardList"
        />

        <template v-else>
            <form class="grid gap-3 sm:grid-cols-3" data-testid="filters" @submit.prevent>
                <div class="space-y-1.5">
                    <Label for="service-class">Turma</Label>
                    <select
                        id="service-class"
                        class="min-h-11 w-full rounded-md border bg-background px-3 text-sm"
                        :value="selectedClass ?? ''"
                        @change="visit({ class: ($event.target as HTMLSelectElement).value })"
                    >
                        <option v-for="schoolClass in classes" :key="schoolClass.ulid" :value="schoolClass.ulid">
                            {{ schoolClass.label }} — {{ schoolClass.subject }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <Label for="service-from">De</Label>
                    <Input
                        id="service-from"
                        type="date"
                        class="min-h-11"
                        :model-value="filters.from ?? ''"
                        @change="visit({ from: ($event.target as HTMLInputElement).value || null })"
                    />
                </div>
                <div class="space-y-1.5">
                    <Label for="service-to">Até</Label>
                    <Input
                        id="service-to"
                        type="date"
                        class="min-h-11"
                        :model-value="filters.to ?? ''"
                        @change="visit({ to: ($event.target as HTMLInputElement).value || null })"
                    />
                </div>
            </form>

            <EmptyState
                v-if="report === null || report.totals.planned === 0"
                title="Sem aulas neste intervalo"
                description="Não há aulas previstas, até hoje, para esta turma neste intervalo."
                :icon="ClipboardList"
            />

            <template v-else>
                <section aria-labelledby="service-summary" class="space-y-3" data-testid="summary">
                    <h2 id="service-summary" class="sr-only">Resumo</h2>
                    <div class="rounded-xl border bg-card p-5">
                        <p class="text-sm text-muted-foreground">Aulas dadas</p>
                        <p class="text-4xl font-semibold tracking-tight" data-testid="percentage">{{ percentageLabel }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ report.totals.counted_as_taught }} dadas em {{ report.totals.planned }} previstas. As aulas
                            não realizadas contam nas previstas.
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground" data-testid="given-breakdown">
                            Dadas = {{ report.totals.subject_development }}
                            {{ report.totals.subject_development === 1 ? 'lecionada' : 'lecionadas' }} +
                            {{ report.totals.class_external_activity }} com a turma noutra atividade letiva.
                        </p>
                    </div>
                    <p
                        v-if="report.totals.not_recorded > 0"
                        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100"
                        data-testid="unrecorded-note"
                    >
                        {{ report.totals.not_recorded }}
                        {{ report.totals.not_recorded === 1 ? 'aula ainda sem registo' : 'aulas ainda sem registo' }}: a
                        percentagem muda quando as registares.
                    </p>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl border bg-card p-4">
                            <dt class="text-xs text-muted-foreground">Previstas</dt>
                            <dd class="text-2xl font-semibold">{{ report.totals.planned }}</dd>
                        </div>
                        <div class="rounded-xl border bg-card p-4">
                            <dt class="text-xs text-muted-foreground">Dadas</dt>
                            <dd class="text-2xl font-semibold">{{ report.totals.counted_as_taught }}</dd>
                        </div>
                        <div class="rounded-xl border bg-card p-4">
                            <dt class="text-xs text-muted-foreground">Não realizadas</dt>
                            <dd class="text-2xl font-semibold">{{ report.totals.not_held }}</dd>
                        </div>
                        <div class="rounded-xl border bg-card p-4">
                            <dt class="text-xs text-muted-foreground">Sem registo</dt>
                            <dd class="text-2xl font-semibold">{{ report.totals.not_recorded }}</dd>
                        </div>
                    </dl>
                    <!-- As previstas partem-se em cinco, sem sobreposição: a soma fecha sempre. -->
                    <div class="rounded-xl border bg-card p-4" data-testid="breakdown">
                        <h3 class="text-sm font-medium">As {{ report.totals.planned }} previstas, uma a uma</h3>
                        <ul class="mt-2 divide-y text-sm">
                            <li class="flex justify-between gap-3 py-1.5">
                                <span>Lecionadas</span>
                                <span class="font-medium tabular-nums">{{ report.totals.subject_development }}</span>
                            </li>
                            <li class="flex justify-between gap-3 py-1.5">
                                <span>Turma noutra atividade letiva <span class="text-xs text-muted-foreground">(contam como dadas)</span></span>
                                <span class="font-medium tabular-nums">{{ report.totals.class_external_activity }}</span>
                            </li>
                            <li class="flex justify-between gap-3 py-1.5">
                                <span>Professor ausente <span class="text-xs text-muted-foreground">(não contam como dadas)</span></span>
                                <span class="font-medium tabular-nums">{{ report.totals.teacher_absent }}</span>
                            </li>
                            <li class="flex justify-between gap-3 py-1.5">
                                <span>Aulas não realizadas <span class="text-xs text-muted-foreground">(não contam como dadas)</span></span>
                                <span class="font-medium tabular-nums">{{ report.totals.not_held }}</span>
                            </li>
                            <li class="flex justify-between gap-3 py-1.5">
                                <span>Ainda sem registo</span>
                                <span class="font-medium tabular-nums">{{ report.totals.not_recorded }}</span>
                            </li>
                        </ul>
                    </div>
                    <p v-if="report.upcoming > 0" class="text-xs text-muted-foreground">
                        Mais {{ report.upcoming }} {{ report.upcoming === 1 ? 'aula prevista' : 'aulas previstas' }} depois de
                        hoje, que ainda não entram nas contas.
                    </p>
                </section>

                <section v-if="report.causes.length > 0" aria-labelledby="service-causes" class="space-y-2" data-testid="causes">
                    <h2 id="service-causes" class="font-semibold">Porque não se realizaram</h2>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li v-for="cause in report.causes" :key="cause.cause" class="flex justify-between gap-3 p-3 text-sm">
                            <span>{{ cause.label }}</span>
                            <span class="font-medium tabular-nums">{{ cause.count }}</span>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="report.personal.not_held_recorded > 0 || report.personal.teacher_absent > 0"
                    aria-labelledby="service-personal"
                    class="space-y-2"
                    data-testid="personal"
                >
                    <h2 id="service-personal" class="font-semibold">O que registaste</h2>
                    <p class="text-sm text-muted-foreground">Só tu vês esta parte: é o que registaste tu.</p>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li v-for="cause in report.personal.causes" :key="cause.cause" class="flex justify-between gap-3 p-3 text-sm">
                            <span>{{ cause.label }}</span>
                            <span class="font-medium tabular-nums">{{ cause.count }}</span>
                        </li>
                        <li v-if="report.personal.teacher_absent > 0" class="flex justify-between gap-3 p-3 text-sm">
                            <span>Ausências tuas</span>
                            <span class="font-medium tabular-nums">{{ report.personal.teacher_absent }}</span>
                        </li>
                    </ul>
                    <div v-if="strikeLines.length > 0" data-testid="strike-lines">
                        <h3 class="mt-3 text-sm font-medium">Greve</h3>
                        <ul class="divide-y rounded-xl border bg-card">
                            <li v-for="[label, count] in strikeLines" :key="label" class="flex justify-between gap-3 p-3 text-sm">
                                <span>{{ label }}</span>
                                <span class="font-medium tabular-nums">{{ count }}</span>
                            </li>
                        </ul>
                    </div>
                    <p v-if="report.personal.not_held_by_others > 0" class="text-xs text-muted-foreground">
                        {{ report.personal.not_held_by_others }}
                        {{ report.personal.not_held_by_others === 1 ? 'aula não realizada foi registada' : 'aulas não realizadas foram registadas' }}
                        por outros professores da turma.
                    </p>
                </section>

                <section aria-labelledby="service-evolution" class="space-y-2" data-testid="evolution">
                    <h2 id="service-evolution" class="font-semibold">Evolução por mês</h2>
                    <div class="overflow-x-auto rounded-xl border bg-card">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs text-muted-foreground">
                                <tr>
                                    <th class="p-3 font-medium">Mês</th>
                                    <th class="p-3 text-right font-medium">Previstas</th>
                                    <th class="p-3 text-right font-medium">Dadas</th>
                                    <th class="p-3 text-right font-medium">Não realizadas</th>
                                    <th class="p-3 text-right font-medium">Sem registo</th>
                                    <th class="p-3 text-right font-medium">Dadas %</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="month in report.evolution" :key="month.month">
                                    <td class="p-3 capitalize">{{ monthLabel(month.month) }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ month.planned }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ month.given }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ month.not_held }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ month.not_recorded }}</td>
                                    <td class="p-3 text-right tabular-nums">
                                        {{ month.given_percentage == null ? '—' : `${month.given_percentage.toLocaleString('pt-PT')} %` }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </template>
    </main>
</template>
