<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Footprints, GraduationCap, Plus, Search, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { statusToneClasses } from '@/lib/statusTone';

/** One enrolment of this student, in a turma THIS teacher teaches. */
export type DirectoryEnrollment = {
    enrollment_ulid: string;
    class_ulid: string;
    class_label: string;
    subject: string;
    academic_year: string;
    class_number: number | null;
    status: string;
    status_label: string;
    is_current: boolean;
};

/**
 * A row of the directory. Deliberately shallow: no birth date, no N.º de
 * processo, no médias, no alerts. Finding somebody needs none of it, and the
 * ficha that does need it is one click away in «Acompanhamento».
 */
export type DirectoryStudent = {
    student_ulid: string;
    name: string;
    pseudonym: string;
    photo_url: string | null;
    enrollments: DirectoryEnrollment[];
};

export type Paginator = {
    data: DirectoryStudent[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
};

export type ClassOption = { ulid: string; label: string; subject: string; academic_year: string };
export type YearOption = { ulid: string; label: string };
export type StatusOption = { value: string; label: string };

export type Props = {
    students: Paginator;
    filters: { q: string; class: string | null; year: string | null; status: string | null };
    classes: ClassOption[];
    academicYears: YearOption[];
    statuses: StatusOption[];
};

const props = defineProps<Props>();

const page = usePage();

/**
 * A module the organization may at least CONSULT — `modules` alone would hide
 * these links from a suspended organization, whose whole point is that the data
 * stays readable. Presentation only; each destination re-checks on the server.
 */
function canRead(module: string): boolean {
    return page.props.modules.includes(module) || page.props.readOnlyModules.includes(module);
}

const canFollowUp = computed(() => canRead('student_progress'));
const canOpenClasses = computed(() => canRead('classes'));

// ------------------------------------------------------------ server filters

const term = ref(props.filters.q);
const classUlid = ref(props.filters.class ?? '');
const yearUlid = ref(props.filters.year ?? '');
const status = ref(props.filters.status ?? '');

function reload(): void {
    router.get(
        '/students',
        {
            q: term.value || undefined,
            class: classUlid.value || undefined,
            year: yearUlid.value || undefined,
            status: status.value || undefined,
        },
        { replace: true, preserveState: true, preserveScroll: true },
    );
}

// The selects reload on change; the search box waits for Enter, because a
// blind-index lookup only ever answers a COMPLETE name and reloading on every
// keystroke would just be a run of misses.
watch([classUlid, yearUlid, status], reload);

const hasFilters = computed(
    () => term.value !== '' || classUlid.value !== '' || yearUlid.value !== '' || status.value !== '',
);

function clearFilters(): void {
    term.value = '';
    classUlid.value = '';
    yearUlid.value = '';
    status.value = '';
    pageFilter.value = '';
    reload();
}

// ------------------------------------------------------------- page filter

/**
 * Narrowing what is ALREADY on screen, by any part of the name.
 *
 * The server cannot do this: the name is encrypted and its index answers exact
 * matches only. The rows of this page are decrypted and in memory, though, so a
 * partial match over them costs nothing — and is honestly labelled as covering
 * this page and not the whole account, which is why the label changes as soon as
 * there is more than one page.
 */
const pageFilter = ref('');

function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/gu, '')
        .toLowerCase()
        .trim();
}

const rows = computed(() => {
    const needle = fold(pageFilter.value);

    if (needle === '') {
        return props.students.data;
    }

    return props.students.data.filter(
        (student) => fold(student.name).includes(needle) || fold(student.pseudonym).includes(needle),
    );
});

const paginated = computed(() => props.students.links.length > 3);

// ------------------------------------------------------------- row helpers

/** The turmas behind a student's enrolments, each one listed once. */
function classesOf(student: DirectoryStudent): DirectoryEnrollment[] {
    const seen = new Set<string>();

    return student.enrollments.filter((enrollment) => {
        if (seen.has(enrollment.class_ulid)) {
            return false;
        }

        seen.add(enrollment.class_ulid);

        return true;
    });
}

/**
 * The student's state, DERIVED from the enrolments and never stored: currently
 * enrolled in at least one of this teacher's turmas, or no longer in any of
 * them — in which case the last enrolment's own words say what happened.
 */
function stateOf(student: DirectoryStudent): { label: string; current: boolean } {
    const current = student.enrollments.some((enrollment) => enrollment.is_current);

    if (current) {
        return { label: 'Inscrito', current: true };
    }

    const last = student.enrollments[student.enrollments.length - 1];

    return { label: last?.status_label ?? '—', current: false };
}

function followUpHref(enrollment: DirectoryEnrollment): string {
    return `/classes/${enrollment.class_ulid}/evolucao/${enrollment.enrollment_ulid}`;
}
</script>

<template>
    <Head title="Alunos" />

    <!--
        A toda a largura, como o Painel — pedido no SUP-9S72UL. O `max-w-5xl`
        centrado fazia disto uma ilha num monitor largo, desalinhada da barra
        de filtros do topo, que estica sempre.
    -->
    <div class="w-full space-y-6 p-4">
        <PageHeader title="Alunos" description="Os alunos das suas turmas." />

        <!-- A) NO TURMAS AT ALL. Not "no students": there is nowhere for a
             student to be yet, and the answer is a turma, not a search. -->
        <EmptyState
            v-if="classes.length === 0"
            title="Ainda não tem turmas."
            description="Os alunos aparecem aqui assim que existir uma turma com inscrições."
            :icon="Users"
        >
            <template #action>
                <Button v-if="canOpenClasses" as-child>
                    <Link href="/classes"><Plus class="size-4" /> Ir para Turmas</Link>
                </Button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="flex flex-wrap items-end gap-3">
                <form class="grid gap-1 text-sm" @submit.prevent="reload">
                    <label class="text-xs font-medium text-muted-foreground" for="students-search">
                        Procurar
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            id="students-search"
                            v-model="term"
                            type="search"
                            placeholder="Nome completo ou pseudónimo…"
                            class="h-9 w-64 rounded-md border border-border bg-background px-3 text-sm"
                        />
                        <Button type="submit" variant="outline" size="sm">
                            <Search class="size-4" />
                            <span class="sr-only">Procurar</span>
                        </Button>
                    </div>
                </form>

                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Turma</span>
                    <select v-model="classUlid" class="h-9 rounded-md border border-border bg-background px-2 text-sm">
                        <option value="">Todas</option>
                        <option v-for="option in classes" :key="option.ulid" :value="option.ulid">
                            {{ option.label }} · {{ option.subject }}
                        </option>
                    </select>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Ano letivo</span>
                    <select v-model="yearUlid" class="h-9 rounded-md border border-border bg-background px-2 text-sm">
                        <option value="">Todos</option>
                        <option v-for="option in academicYears" :key="option.ulid" :value="option.ulid">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <label class="grid gap-1 text-sm">
                    <span class="text-xs font-medium text-muted-foreground">Estado</span>
                    <select v-model="status" class="h-9 rounded-md border border-border bg-background px-2 text-sm">
                        <option value="">Todos</option>
                        <option v-for="option in statuses" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>

                <Button v-if="hasFilters" variant="ghost" size="sm" @click="clearFilters">Limpar</Button>
            </div>

            <p class="text-xs text-muted-foreground">
                A pesquisa encontra o <strong>nome completo</strong> ou o início do pseudónimo. Os nomes são
                guardados cifrados, por isso o servidor não procura por partes de um nome.
            </p>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-muted-foreground">
                    <template v-if="students.total === 0">Sem alunos</template>
                    <template v-else-if="paginated">
                        {{ students.from }}–{{ students.to }} de {{ students.total }} alunos
                    </template>
                    <template v-else>{{ students.total }} {{ students.total === 1 ? 'aluno' : 'alunos' }}</template>
                </p>

                <label v-if="students.data.length > 0" class="grid gap-1 text-sm">
                    <input
                        v-model="pageFilter"
                        type="search"
                        :placeholder="paginated ? 'Filtrar esta página…' : 'Filtrar por parte do nome…'"
                        class="h-8 w-56 rounded-md border border-border bg-background px-3 text-sm"
                        aria-label="Filtrar os alunos apresentados"
                    />
                    <span v-if="paginated" class="text-xs text-muted-foreground">
                        Filtra os alunos apresentados nesta página.
                    </span>
                </label>
            </div>

            <!-- C) FILTERS OR SEARCH FOUND NOBODY. Says so about the filters,
                 never about the account: there ARE students, just not these. -->
            <EmptyState
                v-if="students.total === 0 && hasFilters"
                title="Nenhum aluno corresponde a esta pesquisa."
                description="Experimente limpar os filtros. Se procurou por nome, tem de ser o nome completo."
                :icon="Search"
            >
                <template #action>
                    <Button variant="outline" size="sm" @click="clearFilters">Limpar filtros</Button>
                </template>
            </EmptyState>

            <!-- B) TURMAS, BUT NOBODY IN THEM. The answer is the roll, and the
                 roll is imported or typed in the turma — never here. -->
            <EmptyState
                v-else-if="students.total === 0"
                title="As suas turmas ainda não têm alunos."
                description="Os alunos entram pela turma — importando a Relação de Turma ou inscrevendo um a um."
                :icon="GraduationCap"
            >
                <template #action>
                    <Button v-if="canOpenClasses" as-child>
                        <Link href="/classes">Abrir turmas</Link>
                    </Button>
                </template>
            </EmptyState>

            <!-- The page filter emptied the visible rows, but the page is not
                 empty. A different sentence, because it is a different fact. -->
            <EmptyState v-else-if="rows.length === 0" :title="`Nenhum aluno desta página corresponde a «${pageFilter}».`">
                <template #action>
                    <Button variant="ghost" size="sm" @click="pageFilter = ''">Limpar filtro</Button>
                </template>
            </EmptyState>

            <TableShell v-else>
                <template #head>
                    <tr>
                            <th class="px-4 py-2.5 font-medium">Aluno</th>
                            <th class="px-4 py-2.5 font-medium">Turma(s)</th>
                            <th class="px-4 py-2.5 font-medium">Estado</th>
                            <th class="px-4 py-2.5 text-right font-medium">Ações</th>
                    </tr>
                </template>
                <template #body>
                        <tr v-for="student in rows" :key="student.student_ulid" class="align-top">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <StudentAvatar :photo-url="student.photo_url" />
                                    <span>
                                        <span class="block font-medium">{{ student.name }}</span>
                                        <span class="block font-mono text-xs text-muted-foreground">
                                            {{ student.pseudonym }}
                                        </span>
                                    </span>
                                </div>
                            </td>

                            <td class="px-4 py-3">
                                <ul class="space-y-1">
                                    <li v-for="enrollment in classesOf(student)" :key="enrollment.class_ulid">
                                        <span class="font-medium">{{ enrollment.class_label }}</span>
                                        <span class="text-xs text-muted-foreground">
                                            · {{ enrollment.subject }} · {{ enrollment.academic_year }}
                                            <template v-if="enrollment.class_number">
                                                · n.º {{ enrollment.class_number }}
                                            </template>
                                        </span>
                                    </li>
                                </ul>
                            </td>

                            <td class="px-4 py-3">
                                <Badge variant="secondary" :class="statusToneClasses(stateOf(student).current ? 'active' : 'left')">
                                    {{ stateOf(student).label }}
                                </Badge>
                            </td>

                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-1">
                                    <!-- ACOMPANHAMENTO is the point of the page.
                                         One enrolment, one link. Several, a menu:
                                         the reading belongs to a (aluno, turma)
                                         pair, so picking one of them for the
                                         teacher would be inventing an answer. -->
                                    <template v-if="canFollowUp && student.enrollments.length === 1">
                                        <Button as-child variant="outline" size="sm">
                                            <Link :href="followUpHref(student.enrollments[0])">
                                                <Footprints class="size-4" /> Acompanhamento
                                            </Link>
                                        </Button>
                                    </template>
                                    <DropdownMenu v-else-if="canFollowUp && student.enrollments.length > 1">
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="outline" size="sm">
                                                <Footprints class="size-4" /> Acompanhamento
                                                <ChevronDown class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuLabel>Em que turma?</DropdownMenuLabel>
                                            <DropdownMenuItem
                                                v-for="enrollment in student.enrollments"
                                                :key="enrollment.enrollment_ulid"
                                                as-child
                                            >
                                                <Link :href="followUpHref(enrollment)">
                                                    {{ enrollment.class_label }} · {{ enrollment.subject }}
                                                </Link>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>

                                    <!-- ABRIR TURMA — where editing, removing,
                                         the N.º de processo, the photos and the
                                         import all still live. Not recreated
                                         here; pointed at. -->
                                    <template v-if="canOpenClasses && classesOf(student).length === 1">
                                        <Button as-child variant="ghost" size="sm">
                                            <Link :href="`/classes/${classesOf(student)[0].class_ulid}`">
                                                Abrir turma
                                            </Link>
                                        </Button>
                                    </template>
                                    <DropdownMenu v-else-if="canOpenClasses && classesOf(student).length > 1">
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm">
                                                Abrir turma <ChevronDown class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem
                                                v-for="enrollment in classesOf(student)"
                                                :key="enrollment.class_ulid"
                                                as-child
                                            >
                                                <Link :href="`/classes/${enrollment.class_ulid}`">
                                                    {{ enrollment.class_label }} · {{ enrollment.subject }}
                                                </Link>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                    </tr>
                </template>
            </TableShell>

            <div v-if="paginated" class="flex flex-wrap gap-1">
                <template v-for="(link, index) in students.links" :key="index">
                    <!-- The label is the paginator's own («&laquo; Anterior»),
                         so it carries entities and has to be rendered as HTML —
                         on a native element, because v-html on <Link> would
                         replace the anchor it renders. -->
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded-md border border-border px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40'"
                        preserve-scroll
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span
                        v-else
                        class="rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                        v-html="link.label"
                    />
                </template>
            </div>
        </template>
    </div>
</template>
