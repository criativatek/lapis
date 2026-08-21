<script setup lang="ts">
/**
 * The assessment profile, reduced to the part that matters commercially: the
 * domains are named by the teacher, the weights are the teacher's, and the
 * whole thing is versioned.
 *
 * The «Versão 2 · ativa» badge is not decoration — an active version is frozen
 * (§10.2), and that is precisely the promise this section makes.
 */

const domains = [
    { name: 'Conhecimentos e capacidades', weight: 60 },
    { name: 'Comunicação matemática', weight: 25 },
    { name: 'Atitudes e participação', weight: 15 },
] as const;
</script>

<template>
    <div class="bg-card">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-border/70 px-4 py-3"
        >
            <p class="text-sm font-semibold tracking-tight">
                Perfil de Matemática · 9.º ano
            </p>
            <span
                class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
            >
                Versão 2 · ativa
            </span>
        </div>

        <dl class="divide-y divide-border">
            <div
                v-for="domain in domains"
                :key="domain.name"
                class="px-4 py-3.5"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <dt class="min-w-0 truncate text-sm font-medium">
                        {{ domain.name }}
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums">
                        {{ domain.weight }}%
                    </dd>
                </div>
                <div
                    aria-hidden="true"
                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full bg-primary dark:bg-(--brand-amber)"
                        :style="{ width: `${domain.weight}%` }"
                    />
                </div>
            </div>
        </dl>

        <p
            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-t border-border/70 bg-muted/30 px-4 py-2.5 text-[11px] text-muted-foreground"
        >
            <span>Escala 1 a 5 · 3 domínios · soma 100%</span>
            <span>Alterar cria a versão 3. A 2 fica no histórico.</span>
        </p>
    </div>
</template>
