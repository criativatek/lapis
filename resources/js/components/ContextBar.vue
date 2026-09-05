<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarRange,
    Check,
    ChevronDown,
    GraduationCap,
    Layers,
    Settings,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const page = usePage();
const scope = computed(() => page.props.scope);
const selectableAcademicYears = computed(() => page.props.selectableAcademicYears);
const hasAcademicYears = computed(() => selectableAcademicYears.value.length > 0);
const academicYearChipText = computed(() => {
    if (!hasAcademicYears.value) {
        return 'Sem anos letivos configurados';
    }

    return scope.value.academicYear ?? 'Selecionar ano letivo';
});

type Selector = { key: string; icon: LucideIcon; label: string; value: string | null; href: string | null; emptyLabel: string };

// Subject remains a link to its management page until subject selection has a
// domain rule. Grade level, class and period remain deliberately disabled.
const selectors = computed<Selector[]>(() => [
    { key: 'subject', icon: BookOpen, label: 'Disciplina', value: scope.value.subject, href: '/subjects', emptyLabel: scope.value.hasSubjects ? 'Sem disciplina selecionada' : 'Sem disciplinas configuradas' },
    { key: 'gradeLevel', icon: GraduationCap, label: 'Ano', value: scope.value.gradeLevel, href: null, emptyLabel: '—' },
    { key: 'class', icon: Users, label: 'Turma', value: scope.value.class, href: null, emptyLabel: '—' },
    { key: 'period', icon: Layers, label: 'Período', value: scope.value.period, href: null, emptyLabel: '—' },
]);

/*
 * Três estados, três aparências (SUP-UEVAH4: a barra era toda cinzenta e nada
 * dizia o que estava escolhido, o que era clicável e o que ainda não existe):
 *
 *   - COM VALOR: tinta âmbar pálida da marca (`bg-accent`) + borda com um
 *     toque de navy — é o contexto em vigor, lê-se sem ler;
 *   - NEUTRO (sem valor, mas clicável): a aparência antiga;
 *   - DISABLED (fases futuras): borda tracejada + baixa opacidade — distinto
 *     de clicável, e o `title` continua a dizer porquê.
 */
const chipBase =
    'flex items-center gap-1.5 rounded-md border px-2 py-1.5 text-sm md:px-2.5';

const chipNeutral = `${chipBase} border-border bg-background text-muted-foreground`;

const chipSelected = `${chipBase} border-primary/25 bg-accent text-accent-foreground`;

const chipDisabled = `${chipNeutral} border-dashed opacity-60`;

function chipFor(hasValue: boolean): string {
    return hasValue ? chipSelected : chipNeutral;
}

function selectAcademicYear(ulid: string, isCurrent: boolean): void {
    if (isCurrent) {
        return;
    }

    router.post(`/academic-years/${ulid}/select`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="scrollbar-none flex min-w-0 flex-1 items-center gap-1.5 overflow-hidden md:overflow-x-auto">
        <DropdownMenu v-if="hasAcademicYears">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    title="Ano letivo"
                    :class="[chipSelected, 'shrink-0 transition-colors hover:border-primary/40']"
                >
                    <CalendarRange class="size-3.5 shrink-0 opacity-70" />
                    <span class="hidden font-medium sm:inline">Ano letivo:</span>
                    <span class="whitespace-nowrap">{{ academicYearChipText }}</span>
                    <ChevronDown class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="min-w-56">
                <DropdownMenuItem
                    v-for="academicYear in selectableAcademicYears"
                    :key="academicYear.ulid"
                    class="cursor-pointer gap-2"
                    @click="selectAcademicYear(academicYear.ulid, academicYear.is_current)"
                >
                    <Check v-if="academicYear.is_current" class="size-4 shrink-0" aria-hidden="true" />
                    <span v-else class="size-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1">{{ academicYear.label }}</span>
                    <span v-if="academicYear.is_current" class="text-xs text-muted-foreground">(atual)</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem :as-child="true">
                    <Link href="/academic-years" class="flex w-full cursor-pointer items-center gap-2">
                        <Settings class="size-4 shrink-0" />
                        Gerir anos letivos
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <Link
            v-else
            href="/academic-years"
            title="Ano letivo"
            :class="[chipNeutral, 'shrink-0 transition-colors hover:border-primary/40 hover:text-foreground']"
        >
            <CalendarRange class="size-3.5 shrink-0 opacity-70" />
            <span class="hidden font-medium text-foreground/70 sm:inline">Ano letivo:</span>
            <span class="whitespace-nowrap">{{ academicYearChipText }}</span>
        </Link>

        <template v-for="selector in selectors" :key="selector.key">
            <Link
                v-if="selector.href"
                :href="selector.href"
                :title="selector.label"
                :class="[chipFor(selector.value !== null), 'min-w-0 flex-1 transition-colors hover:border-primary/40 md:flex-none md:shrink-0']"
            >
                <component :is="selector.icon" class="size-3.5 shrink-0 opacity-70" />
                <span class="hidden font-medium sm:inline">{{ selector.label }}:</span>
                <span class="truncate whitespace-nowrap">{{ selector.value ?? selector.emptyLabel }}</span>
            </Link>
            <button
                v-else
                type="button"
                disabled
                :title="`${selector.label} — disponível na próxima fase`"
                :class="[chipDisabled, 'hidden shrink-0 disabled:cursor-not-allowed md:flex']"
            >
                <component :is="selector.icon" class="size-3.5 shrink-0 opacity-70" />
                <span class="hidden font-medium sm:inline">{{ selector.label }}:</span>
                <span class="whitespace-nowrap">{{ selector.value ?? selector.emptyLabel }}</span>
            </button>
        </template>
    </div>
</template>
