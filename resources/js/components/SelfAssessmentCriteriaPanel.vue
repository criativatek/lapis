<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SelfAssessmentConfiguration } from '@/types/self-assessment';

const props = defineProps<{
    configuration: SelfAssessmentConfiguration;
    /**
     * `form` is the single-student page, which can only point to the list of the
     * class for starting a new self-assessment; `list` is the class page, which
     * renders the button itself in the `renew` slot.
     */
    context: 'form' | 'list';
}>();

/**
 * «Avaliação final · versão 6 · Escala 1 a 5» — the three things that identify
 * the criteria. A part that is missing is left out rather than written as «null».
 */
function label(profile: string | null, version: number | null, scale: string | null): string {
    return [profile ?? 'Sem perfil', version === null ? null : `versão ${version}`, scale].filter((part) => part !== null).join(' · ');
}

const own = computed(() => label(props.configuration.profile, props.configuration.version, props.configuration.scale));
const ofClass = computed(() => {
    const criteria = props.configuration.class_criteria;

    return criteria ? label(criteria.profile, criteria.version, criteria.scale) : null;
});

function weight(value: string): string {
    return `${value.replace('.', ',')}%`;
}

function savedCount(count: number): string {
    return count === 1 ? '1 autoavaliação guardada' : `${count} autoavaliações guardadas`;
}

const listUrl = computed(() => `${props.configuration.class_url}/self-assessments`);

const linkClass = 'inline-flex min-h-11 items-center text-sm font-medium text-primary underline-offset-4 hover:underline';
</script>

<template>
    <section class="space-y-3 rounded-lg border border-border p-4 text-sm" aria-label="Critérios desta autoavaliação" data-test="criteria-panel">
        <!-- A CLASS WITHOUT CRITERIA: nothing below can be asked, and saying so
             beats showing a form that looks broken. -->
        <div
            v-if="configuration.state === 'no_profile'"
            role="note"
            class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            data-test="criteria-no-profile"
        >
            <p>
                Esta turma ainda não tem critérios de avaliação. As perguntas sobre os domínios e a classificação proposta aparecem depois de associar um perfil na página da turma.
            </p>
        </div>

        <template v-else>
            <p v-if="configuration.state === 'current'" data-test="criteria-current">
                Esta autoavaliação utiliza os critérios de avaliação da turma: <span class="font-medium">{{ own }}</span>.
            </p>

            <!-- THE CLASS MOVED ON, THE ANSWERS DID NOT. Said in full because
                 the form below is still on the old criteria, on purpose. -->
            <div
                v-else
                role="note"
                class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                data-test="criteria-previous"
            >
                <p>
                    A turma passou a ser avaliada com «{{ ofClass }}». Esta autoavaliação continua com os critérios em que foi preenchida ({{ own }}), porque já tem {{ savedCount(configuration.saved_count) }}.
                </p>
                <Link v-if="context === 'form'" :href="listUrl" class="inline-flex min-h-11 items-center font-medium underline underline-offset-4">
                    Ir à lista de autoavaliações para iniciar uma nova
                </Link>
                <slot name="renew" />
            </div>

            <ul v-if="configuration.domains.length" class="space-y-0.5" aria-label="Domínios e ponderações">
                <li v-for="domain in configuration.domains" :key="domain.name">{{ domain.name }} — {{ weight(domain.weight) }}</li>
            </ul>

            <p class="text-muted-foreground">Os domínios, as ponderações e a escala vêm do perfil da turma e não se alteram aqui.</p>
        </template>

        <!-- A SCALE THE FORM CANNOT BE ANSWERED ON. -->
        <div
            v-if="configuration.answer?.problem"
            role="note"
            class="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            data-test="criteria-problem"
        >
            <p>{{ configuration.answer.problem }}</p>
            <p>Para resolver, associe a turma a uma versão do perfil com uma escala completa: na página da turma, use «Rever e aplicar alterações».</p>
        </div>

        <div class="flex flex-wrap items-center gap-x-4">
            <Link :href="configuration.class_url" :class="linkClass">Abrir a página da turma</Link>
            <Link v-if="configuration.review" :href="configuration.review.url" :class="linkClass" data-test="criteria-review">
                Rever e aplicar a versão {{ configuration.review.version }}<template v-if="configuration.review.scale"> ({{ configuration.review.scale }})</template>
            </Link>
        </div>
    </section>
</template>
