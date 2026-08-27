<script setup lang="ts">
import LandingSection from './LandingSection.vue';
import RevealOnScroll from './RevealOnScroll.vue';

/**
 * The three assessment rules that are not negotiable (§13.3, §11.4), said as
 * short as they can be said.
 *
 * They are the whole argument against a spreadsheet, and an argument is worth
 * more when it fits on one line than when it is explained.
 */
const rules = [
    {
        claim: 'Vazio nunca é zero.',
        body: 'Uma avaliação ainda por preencher não conta como zero. O Lapispro distingue o que falta avaliar de uma classificação de zero.',
    },
    {
        claim: '«Não aplicável» fica fora do cálculo.',
        body: 'Se um critério, questão ou domínio não se aplica, o Lapispro exclui-o do cálculo e recalcula o resultado apenas com o que era efetivamente avaliável.',
    },
    {
        claim: 'Quem chega mais tarde não é penalizado.',
        body: 'Um aluno que entra na turma a meio do período é avaliado apenas com base nos elementos em que podia efetivamente participar.',
    },
] as const;
</script>

<template>
    <LandingSection
        tinted
        eyebrow="O cálculo"
        title="Três regras que uma folha de cálculo não trata sozinha."
    >
        <dl class="grid gap-8 sm:grid-cols-3 sm:gap-6">
            <RevealOnScroll
                v-for="(rule, index) in rules"
                :key="rule.claim"
                v-slot="{ shown }"
                :delay="index * 80"
            >
                <div class="group relative pt-5">
                    <!-- The rule's own rule: it draws itself in from the left as
                         the row is revealed, instead of being there already. -->
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-0 top-0 block h-0.5 origin-left bg-border"
                    />
                    <span
                        aria-hidden="true"
                        class="absolute inset-x-0 top-0 block h-0.5 origin-left bg-primary/70 transition-transform delay-200 duration-700 ease-out dark:bg-(--brand-amber)/60"
                        :class="shown ? 'scale-x-100' : 'scale-x-0'"
                    />
                    <dt
                        class="text-xl font-semibold tracking-tight text-balance transition-colors duration-500 group-hover:text-primary dark:group-hover:text-(--brand-amber)"
                    >
                        {{ rule.claim }}
                    </dt>
                    <dd
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ rule.body }}
                    </dd>
                </div>
            </RevealOnScroll>
        </dl>
    </LandingSection>
</template>
