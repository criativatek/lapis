<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { CalendarRange, Check, ChevronDown, LayoutList, Settings, Users } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * O contexto de trabalho, e só ele: o ano letivo em vigor e, nas páginas de
 * uma turma, a turma em vista.
 *
 * DOIS CHIPS NO MÁXIMO, NENHUM DESLIGADO. Os chips «Disciplina», «Ano»,
 * «Turma» e «Período» da primeira versão prometiam fases que nunca chegaram e
 * ocupavam a barra inteira com traços; a disciplina gere-se em «Estrutura do
 * Ano Letivo», o ano e o período lêem-se na turma. O que fica é o que se
 * escolhe: o ano letivo (sempre) e a turma (quando a rota ligou uma) — e
 * trocar de turma leva à MESMA página na outra turma, calculada no servidor
 * (`ClassSwitcher`), nunca adivinhada aqui.
 */
const page = usePage();
const scope = computed(() => page.props.workContext);
const selectableAcademicYears = computed(() => page.props.selectableAcademicYears);
const hasAcademicYears = computed(() => selectableAcademicYears.value.length > 0);
const academicYearChipText = computed(() => {
    if (!hasAcademicYears.value) {
        return 'Sem anos letivos configurados';
    }

    return scope.value.academicYear ?? 'Selecionar ano letivo';
});

const currentClass = computed(() => scope.value.class);
const classOptions = computed(() => scope.value.classes);

/*
 * Duas aparências (SUP-UEVAH4): COM VALOR, a tinta âmbar pálida da marca e
 * uma borda com um toque de navy — o contexto em vigor lê-se sem ler; NEUTRO,
 * a aparência de um chip clicável sem nada escolhido ainda.
 */
// 44px no telemóvel, 36px de `md` para cima — como os restantes alvos da barra.
const chipBase = 'flex min-h-11 items-center gap-1.5 rounded-md border px-2 text-sm md:min-h-9 md:px-2.5';

const chipNeutral = `${chipBase} border-border bg-background text-muted-foreground`;

const chipSelected = `${chipBase} border-primary/25 bg-accent text-accent-foreground`;

function selectAcademicYear(ulid: string, isCurrent: boolean): void {
    if (isCurrent) {
        return;
    }

    router.post(`/academic-years/${ulid}/select`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-1.5" data-context-bar>
        <DropdownMenu v-if="hasAcademicYears">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    title="Ano letivo"
                    :class="[chipSelected, 'shrink-0 transition-colors hover:border-primary/40']"
                    data-testid="academic-year-chip"
                >
                    <CalendarRange class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                    <span class="sr-only">Ano letivo:</span>
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
                        <Settings class="size-4 shrink-0" aria-hidden="true" />
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
            data-testid="academic-year-chip"
        >
            <CalendarRange class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
            <span class="sr-only">Ano letivo:</span>
            <span class="whitespace-nowrap">{{ academicYearChipText }}</span>
        </Link>

        <!-- A turma em vista: só nas rotas que ligaram uma. Trocar leva à
             mesma página na outra turma (ou à página da turma, quando a atual
             leva um aluno, uma pauta guardada ou outra coisa só desta). -->
        <DropdownMenu v-if="currentClass">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    title="Turma"
                    :class="[chipSelected, 'min-w-0 transition-colors hover:border-primary/40']"
                    data-testid="class-chip"
                >
                    <Users class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                    <span class="sr-only">Turma:</span>
                    <span class="truncate whitespace-nowrap">
                        <span class="font-medium">{{ currentClass.label }}</span>
                        <span class="hidden sm:inline"> · {{ currentClass.subject }}</span>
                    </span>
                    <ChevronDown class="size-3.5 shrink-0 opacity-70" aria-hidden="true" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="min-w-64">
                <DropdownMenuItem v-for="option in classOptions" :key="option.ulid" :as-child="true">
                    <Link
                        :href="option.href"
                        class="flex w-full cursor-pointer items-center gap-2"
                        :aria-current="option.is_current ? 'true' : undefined"
                        data-testid="class-option"
                    >
                        <Check v-if="option.is_current" class="size-4 shrink-0" aria-hidden="true" />
                        <span v-else class="size-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1 truncate">{{ option.label }} <span class="text-muted-foreground">· {{ option.subject }}</span></span>
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem :as-child="true">
                    <Link href="/classes" class="flex w-full cursor-pointer items-center gap-2">
                        <LayoutList class="size-4 shrink-0" aria-hidden="true" />
                        Todas as turmas
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
