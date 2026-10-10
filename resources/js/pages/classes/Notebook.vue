<script setup lang="ts">
/**
 * Caderno da turma — registos privados do professor sobre a turma como grupo
 * (docs/superpowers/specs/2026-10-09-class-notebook-design.md §7).
 *
 * Guardar é sempre um gesto explícito. Um editor de cada vez (o compositor OU
 * um registo em edição). Se uma gravação falha, o texto fica exatamente como
 * foi escrito: nada aqui é limpo antes de o servidor dizer que guardou.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, BookOpen, Lock, Pencil, Pin, PinOff, Plus, Search, Trash2, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import NotebookEntryForm from '@/pages/classes/partials/NotebookEntryForm.vue';

type Entry = {
    ulid: string;
    title: string | null;
    body: string;
    is_pinned: boolean;
    created_at: string | null;
    edited_at: string | null;
    lock_version: number;
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string | null;
        academic_year: string | null;
        archived: boolean;
    };
    entries: {
        data: Entry[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    filters: { q: string };
    totalEntries: number;
    can: { write: boolean };
}>();

const BLANK_MESSAGE = 'Escreve o registo antes de guardar.';
const DISCARD_MESSAGE = 'Tens alterações por guardar. Queres descartá-las?';
const SERVER_FAILURE_MESSAGE =
    'Não foi possível guardar: o servidor não respondeu como devia. O teu texto continua aqui — tenta outra vez.';
const NETWORK_FAILURE_MESSAGE =
    'Não foi possível guardar: a ligação falhou. O teu texto continua aqui, por gravar — tenta outra vez.';

const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

function formatDateTime(iso: string | null): string | null {
    return iso === null ? null : dateTimeFormatter.format(new Date(iso));
}

const baseUrl = computed(() => `/classes/${props.schoolClass.ulid}/notebook`);

// --- O editor (um de cada vez) ---------------------------------------------

type Editor = { mode: 'create' } | { mode: 'edit'; ulid: string; lockVersion: number };

const editor = ref<Editor | null>(null);
const title = ref('');
const body = ref('');
// O que o editor tinha ao abrir — para saber se há alterações por guardar.
const baseTitle = ref('');
const baseBody = ref('');
const saving = ref(false);
const errors = ref<{ title?: string; body?: string; form?: string }>({});

const dirty = computed(
    () => editor.value !== null && (title.value !== baseTitle.value || body.value !== baseBody.value),
);

function resetEditor(next: Editor | null, nextTitle = '', nextBody = ''): void {
    editor.value = next;
    title.value = nextTitle;
    body.value = nextBody;
    baseTitle.value = nextTitle;
    baseBody.value = nextBody;
    errors.value = {};
}

/** Pode-se largar o editor atual? Com alterações por guardar, pergunta. */
function confirmDiscard(): boolean {
    return !dirty.value || window.confirm(DISCARD_MESSAGE);
}

function openComposer(): void {
    if (editor.value?.mode === 'create') {
        return;
    }

    if (!confirmDiscard()) {
        return;
    }

    resetEditor({ mode: 'create' });
}

function openEdit(entry: Entry): void {
    if (editor.value?.mode === 'edit' && editor.value.ulid === entry.ulid) {
        return;
    }

    if (!confirmDiscard()) {
        return;
    }

    resetEditor({ mode: 'edit', ulid: entry.ulid, lockVersion: entry.lock_version }, entry.title ?? '', entry.body);
}

function cancelEditor(): void {
    if (!confirmDiscard()) {
        return;
    }

    resetEditor(null);
}

function isBlank(text: string): boolean {
    // `\s` já cobre o NBSP e o BOM; o espaço de largura zero não. Escapados,
    // e não literais: um carácter invisível no código-fonte não se revê.
    return text.replace(/[\s\u200b]+/g, '') === '';
}

function save(): void {
    const current = editor.value;

    if (current === null || saving.value) {
        return;
    }

    if (isBlank(body.value)) {
        errors.value = { body: BLANK_MESSAGE };

        return;
    }

    errors.value = {};
    saving.value = true;

    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            resetEditor(null);
        },
        onError: (received: Record<string, string>) => {
            errors.value = {
                title: received.title,
                body: received.body,
                form: received.lock_version ?? (received.title || received.body ? undefined : Object.values(received)[0]),
            };
        },
        onHttpException: () => {
            errors.value = { form: SERVER_FAILURE_MESSAGE };

            return false;
        },
        onNetworkError: () => {
            errors.value = { form: NETWORK_FAILURE_MESSAGE };

            return false;
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (current.mode === 'create') {
        router.post(baseUrl.value, { title: title.value, body: body.value }, options);

        return;
    }

    router.put(
        `${baseUrl.value}/${current.ulid}`,
        { title: title.value, body: body.value, lock_version: current.lockVersion },
        options,
    );
}

// --- Fixar e eliminar (escritas da própria página: não perdem o rascunho) ---

function togglePin(entry: Entry): void {
    router.patch(`${baseUrl.value}/${entry.ulid}/pin`, { pinned: !entry.is_pinned }, {
        preserveScroll: true,
        preserveState: true,
    });
}

const entryToDelete = ref<Entry | null>(null);
const deleting = ref(false);
const deleteOpen = computed({
    get: () => entryToDelete.value !== null,
    set: (open: boolean) => {
        if (!open && !deleting.value) {
            entryToDelete.value = null;
        }
    },
});

function entryLabel(entry: Entry): string {
    if (entry.title) {
        return entry.title;
    }

    const flat = entry.body.replace(/\s+/g, ' ').trim();

    return flat.length > 80 ? `${flat.slice(0, 80)}…` : flat;
}

function confirmDelete(): void {
    const entry = entryToDelete.value;

    if (entry === null || deleting.value) {
        return;
    }

    deleting.value = true;

    router.delete(`${baseUrl.value}/${entry.ulid}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (editor.value?.mode === 'edit' && editor.value.ulid === entry.ulid) {
                resetEditor(null);
            }
        },
        onFinish: () => {
            deleting.value = false;
            entryToDelete.value = null;
        },
    });
}

// --- Pesquisa ---------------------------------------------------------------

const term = ref(props.filters.q);

// O campo segue o servidor: guardar um registo novo volta à lista sem
// pesquisa, e com `preserveState` o termo antigo ficava escrito no campo
// por cima de uma lista que já não o aplica.
watch(
    () => props.filters.q,
    (q) => {
        term.value = q;
    },
);

function search(): void {
    router.get(baseUrl.value, { q: term.value.trim() || undefined }, { replace: true, preserveState: true, preserveScroll: true });
}

function clearSearch(): void {
    term.value = '';
    search();
}

const paginated = computed(() => props.entries.links.length > 3);

/**
 * Os rótulos das setas escritos aqui, e não os do paginador do servidor: o
 * projeto não tem `lang/pt_PT/pagination.php`, e o servidor mandava a chave
 * crua («pagination.previous»). Os números e as reticências vêm como estão,
 * em texto — sem `v-html`.
 */
function pageLinkLabel(index: number, label: string): string {
    if (index === 0) {
        return '‹ Anterior';
    }

    if (index === props.entries.links.length - 1) {
        return 'Seguinte ›';
    }

    return label;
}

// --- Sair com alterações por gravar -----------------------------------------

function warnOnUnload(event: BeforeUnloadEvent): void {
    if (!dirty.value || saving.value) {
        return;
    }

    event.preventDefault();
}

/**
 * Só as NAVEGAÇÕES (GET: ligações, paginação, pesquisa) perguntam. As escritas
 * da própria página — guardar, fixar, eliminar — nunca, para que um rascunho
 * aberto sobreviva a fixar ou eliminar outro registo.
 */
function guardNavigation(event: CustomEvent<{ visit: { method: string } }>): void {
    if (event.detail.visit.method !== 'get' || saving.value || !dirty.value) {
        return;
    }

    if (!window.confirm(DISCARD_MESSAGE)) {
        event.preventDefault();

        return;
    }

    resetEditor(null);
}

let stopGuardingNavigation: (() => void) | null = null;

onMounted(() => {
    window.addEventListener('beforeunload', warnOnUnload);
    stopGuardingNavigation = router.on('before', guardNavigation as never);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', warnOnUnload);
    stopGuardingNavigation?.();
    stopGuardingNavigation = null;
});
</script>

<template>
    <Head :title="`Caderno da turma — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <div class="space-y-3">
            <Link
                :href="`/classes/${schoolClass.ulid}`"
                class="inline-flex min-h-11 items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Voltar à turma
            </Link>

            <Heading
                title="Caderno da turma"
                :description="[schoolClass.label, schoolClass.subject, schoolClass.academic_year].filter(Boolean).join(' · ')"
                class="!mb-0"
            />

            <p class="text-sm text-muted-foreground">
                Regista e consulta observações, informações gerais e assuntos a acompanhar sobre a turma.
            </p>
            <!-- Privado do autor; o suporte técnico só o lê durante um apoio
                 pedido (nunca escreve — ClassNotebookController e os Form
                 Requests recusam a impersonação). A frase diz as duas coisas. -->
            <p class="flex items-start gap-1.5 text-sm text-muted-foreground">
                <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Privado — acessível ao suporte durante o apoio técnico.
            </p>
            <p v-if="schoolClass.archived" class="text-sm text-muted-foreground">
                Turma arquivada — o caderno continua disponível.
            </p>

            <Button v-if="can.write" type="button" class="min-h-11 w-full sm:w-auto" @click="openComposer">
                <Plus class="size-4" aria-hidden="true" />
                Adicionar registo
            </Button>
        </div>

        <Card v-if="can.write && editor?.mode === 'create'" class="py-4 sm:py-6">
            <CardContent class="px-4 sm:px-6">
                <NotebookEntryForm
                    id-prefix="notebook-new"
                    v-model:title="title"
                    v-model:body="body"
                    :saving="saving"
                    :title-error="errors.title"
                    :body-error="errors.body"
                    :form-error="errors.form"
                    @submit="save"
                    @cancel="cancelEditor"
                />
            </CardContent>
        </Card>

        <form v-if="totalEntries > 0" class="flex flex-col gap-2 sm:flex-row" role="search" @submit.prevent="search">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <Input
                    v-model="term"
                    type="search"
                    class="h-11 pl-9"
                    placeholder="Pesquisar no caderno…"
                    aria-label="Pesquisar no caderno"
                    autocomplete="off"
                />
            </div>
            <Button type="submit" variant="secondary" class="min-h-11 w-full sm:w-auto">Pesquisar</Button>
        </form>

        <EmptyState
            v-if="totalEntries === 0 && editor?.mode !== 'create'"
            title="Ainda não tens registos neste caderno."
            :icon="BookOpen"
        >
            <template v-if="can.write" #action>
                <Button type="button" class="min-h-11" @click="openComposer">
                    <Plus class="size-4" aria-hidden="true" />
                    Adicionar registo
                </Button>
            </template>
        </EmptyState>

        <div
            v-else-if="totalEntries > 0 && entries.data.length === 0"
            class="space-y-3 rounded-2xl border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm font-medium">Nenhum registo corresponde a «{{ filters.q }}».</p>
            <Button type="button" variant="outline" class="min-h-11" @click="clearSearch">
                <X class="size-4" aria-hidden="true" />
                Limpar pesquisa
            </Button>
        </div>

        <ul v-if="entries.data.length" class="space-y-3">
            <li v-for="entry in entries.data" :key="entry.ulid">
                <Card :data-notebook-entry="entry.ulid" class="py-4 sm:py-5">
                    <CardContent class="px-4 sm:px-6">
                        <NotebookEntryForm
                            v-if="can.write && editor?.mode === 'edit' && editor.ulid === entry.ulid"
                            :id-prefix="`notebook-${entry.ulid}`"
                            v-model:title="title"
                            v-model:body="body"
                            :saving="saving"
                            :title-error="errors.title"
                            :body-error="errors.body"
                            :form-error="errors.form"
                            @submit="save"
                            @cancel="cancelEditor"
                        />

                        <!-- A data abre o registo, como numa página de caderno, e
                             partilha a linha com as ações: assim um registo sem
                             título não fica com uma linha só de botões. -->
                        <article v-else class="space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                                    <Badge v-if="entry.is_pinned" variant="secondary" class="gap-1">
                                        <Pin class="size-3" aria-hidden="true" />
                                        Fixado
                                    </Badge>
                                    <span>Criado em {{ formatDateTime(entry.created_at) }}</span>
                                    <span v-if="entry.edited_at">· Editado em {{ formatDateTime(entry.edited_at) }}</span>
                                </p>

                                <div v-if="can.write" class="-my-2 -mr-2 flex shrink-0">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="size-11"
                                        :aria-label="entry.is_pinned ? 'Desafixar' : 'Fixar no topo'"
                                        :title="entry.is_pinned ? 'Desafixar' : 'Fixar no topo'"
                                        @click="togglePin(entry)"
                                    >
                                        <PinOff v-if="entry.is_pinned" class="size-4" />
                                        <Pin v-else class="size-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="size-11"
                                        aria-label="Editar"
                                        title="Editar"
                                        @click="openEdit(entry)"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        class="size-11"
                                        aria-label="Eliminar"
                                        title="Eliminar"
                                        @click="entryToDelete = entry"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <h3 v-if="entry.title" data-entry-title class="text-base font-semibold [overflow-wrap:anywhere]">
                                {{ entry.title }}
                            </h3>

                            <p class="text-sm leading-relaxed whitespace-pre-wrap [overflow-wrap:anywhere]">{{ entry.body }}</p>
                        </article>
                    </CardContent>
                </Card>
            </li>
        </ul>

        <nav v-if="paginated" class="flex flex-wrap gap-1" aria-label="Páginas do caderno">
            <template v-for="(link, index) in entries.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-border px-3 text-sm"
                    :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted/40'"
                    :aria-current="link.active ? 'page' : undefined"
                    preserve-scroll
                >
                    {{ pageLinkLabel(index, link.label) }}
                </Link>
                <span
                    v-else
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-border px-3 text-sm text-muted-foreground opacity-50"
                >
                    {{ pageLinkLabel(index, link.label) }}
                </span>
            </template>
        </nav>

        <Dialog v-model:open="deleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Eliminar registo?</DialogTitle>
                    <DialogDescription>
                        Este registo deixa de aparecer no teu caderno.
                        <span v-if="entryToDelete" class="mt-2 block [overflow-wrap:anywhere]">
                            «{{ entryLabel(entryToDelete) }}»
                        </span>
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" class="min-h-11" :disabled="deleting" @click="entryToDelete = null">
                        Cancelar
                    </Button>
                    <Button type="button" variant="destructive" class="min-h-11" :disabled="deleting" @click="confirmDelete">
                        <Trash2 class="size-4" aria-hidden="true" /> Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
