<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Search, UserPlus } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import StudentAvatar from '@/components/StudentAvatar.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * «Adicionar aluno existente» — só numa turma de apoio.
 *
 * O servidor devolve no máximo 20 alunos, e só das turmas que o professor
 * leciona neste ano (App\Services\Classes\ReusableStudents). Nada disto é a
 * autorização: o ULID escolhido é verificado de novo quando chega.
 *
 * Nunca escolhe por nome: dois «João Silva» aparecem os dois, cada um com as
 * suas turmas e o seu número, e é o professor quem diz qual é.
 */
export type ReusableStudent = {
    ulid: string;
    name: string;
    process_number: string | null;
    photo_url: string | null;
    origins: { label: string; class_number: number | null }[];
    already_in_class: boolean;
};

const props = defineProps<{ classUlid: string }>();

const MINIMUM_LENGTH = 2;
const listboxId = `existing-students-${props.classUlid}`;

const query = ref('');
const results = ref<ReusableStudent[]>([]);
const loading = ref(false);
const failed = ref(false);
const searched = ref(false);
const open = ref(false);
const activeIndex = ref(-1);
const adding = ref<string | null>(null);
const added = ref<string | null>(null);
const error = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const tooShort = computed(() => query.value.trim().length < MINIMUM_LENGTH);

watch(query, (value) => {
    clearTimeout(timer);
    controller?.abort();
    error.value = null;
    activeIndex.value = -1;

    // Limpar o campo depois de adicionar não apaga a confirmação; escrever de novo apaga.
    if (value !== '') {
        added.value = null;
    }

    if (value.trim().length < MINIMUM_LENGTH) {
        results.value = [];
        loading.value = false;
        searched.value = false;

        return;
    }

    loading.value = true;
    open.value = true;
    timer = setTimeout(() => search(value.trim()), 250);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

async function search(term: string): Promise<void> {
    controller = new AbortController();
    failed.value = false;

    try {
        const response = await fetch(
            `/classes/${props.classUlid}/existing-students?q=${encodeURIComponent(term)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        results.value = ((await response.json()) as { data: ReusableStudent[] }).data;
        searched.value = true;
    } catch (caught) {
        if ((caught as Error).name === 'AbortError') {
            return;
        }

        results.value = [];
        failed.value = true;
    }

    loading.value = false;
}

function describe(student: ReusableStudent): string {
    const origins = student.origins
        .map((origin) =>
            origin.class_number === null
                ? origin.label
                : `${origin.label} — n.º ${origin.class_number}`,
        )
        .join(' · ');

    return student.process_number
        ? `${origins} · proc. ${student.process_number}`
        : origins;
}

function choose(student: ReusableStudent): void {
    if (student.already_in_class) {
        error.value = 'Este aluno já pertence a esta turma.';

        return;
    }

    if (adding.value !== null) {
        return;
    }

    adding.value = student.ulid;
    error.value = null;

    router.post(
        `/classes/${props.classUlid}/existing-students`,
        { student_ulid: student.ulid },
        {
            preserveScroll: true,
            onSuccess: () => {
                added.value = student.name;
                query.value = '';
                open.value = false;
            },
            onError: (errors) => {
                error.value =
                    errors.student_ulid ?? errors.limit ?? 'Não foi possível adicionar o aluno.';
            },
            onFinish: () => {
                adding.value = null;
            },
        },
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (open.value) {
            event.preventDefault();
            open.value = false;
        } else {
            query.value = '';
        }

        return;
    }

    if (!results.value.length) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        activeIndex.value = (activeIndex.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        open.value = true;
        activeIndex.value =
            activeIndex.value <= 0 ? results.value.length - 1 : activeIndex.value - 1;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const student = results.value[activeIndex.value >= 0 ? activeIndex.value : 0];

        if (student && (activeIndex.value >= 0 || results.value.length === 1)) {
            choose(student);
        }
    }

    if (activeIndex.value >= 0) {
        document
            .getElementById(`${listboxId}-${activeIndex.value}`)
            ?.scrollIntoView({ block: 'nearest' });
    }
}

function onBlur(): void {
    // Deixa o clique num resultado chegar antes de fechar a lista.
    setTimeout(() => {
        open.value = false;
    }, 150);
}
</script>

<template>
    <div class="grid gap-2 rounded-lg border border-border p-4">
        <Label :for="`${listboxId}-input`" class="text-xs">
            Adicionar aluno existente
        </Label>
        <p class="text-xs text-muted-foreground">
            Procure pelo nome ou pelo n.º de processo entre os alunos das suas
            turmas deste ano letivo. O aluno fica também na turma de origem — é
            o mesmo aluno, com o mesmo nome e fotografia.
        </p>
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <Input
                :id="`${listboxId}-input`"
                v-model="query"
                type="search"
                autocomplete="off"
                placeholder="Ex.: João Silva"
                class="pl-8"
                role="combobox"
                :aria-expanded="open && !tooShort"
                :aria-controls="listboxId"
                aria-autocomplete="list"
                :aria-activedescendant="
                    activeIndex >= 0 ? `${listboxId}-${activeIndex}` : undefined
                "
                @keydown="onKeydown"
                @focus="open = !tooShort"
                @blur="onBlur"
            />
            <div
                v-if="open && !tooShort"
                class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-md border border-border bg-popover text-popover-foreground shadow-md"
            >
                <p v-if="loading" class="p-3 text-sm text-muted-foreground" aria-live="polite">
                    A procurar…
                </p>
                <p v-else-if="failed" class="p-3 text-sm text-destructive" aria-live="polite">
                    Não foi possível pesquisar. Tente de novo.
                </p>
                <p
                    v-else-if="searched && results.length === 0"
                    class="p-3 text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Nenhum aluno encontrado nas suas turmas deste ano. Pode
                    adicioná-lo como aluno novo, abaixo.
                </p>
                <ul v-else :id="listboxId" role="listbox" class="py-1">
                    <li
                        v-for="(student, index) in results"
                        :id="`${listboxId}-${index}`"
                        :key="student.ulid"
                        role="option"
                        :aria-selected="index === activeIndex"
                        :aria-disabled="student.already_in_class"
                        class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm"
                        :class="[
                            index === activeIndex ? 'bg-accent' : 'hover:bg-accent/60',
                            student.already_in_class ? 'cursor-default opacity-60' : '',
                        ]"
                        @mousedown.prevent
                        @click="choose(student)"
                        @mouseenter="activeIndex = index"
                    >
                        <StudentAvatar :photo-url="student.photo_url" class="shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ student.name }}</p>
                            <p class="text-xs break-words text-muted-foreground">
                                {{ describe(student) }}
                            </p>
                        </div>
                        <span
                            v-if="student.already_in_class"
                            class="shrink-0 text-xs text-muted-foreground"
                        >
                            Já na turma
                        </span>
                        <Button
                            v-else
                            type="button"
                            size="sm"
                            variant="outline"
                            class="shrink-0"
                            tabindex="-1"
                            :disabled="adding !== null"
                            :aria-label="`Adicionar ${student.name}`"
                        >
                            <UserPlus class="size-4" />
                            <span class="hidden sm:inline">
                                {{ adding === student.ulid ? 'A adicionar…' : 'Adicionar' }}
                            </span>
                        </Button>
                    </li>
                </ul>
            </div>
        </div>
        <p v-if="added" class="flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400" aria-live="polite">
            <Check class="size-4" /> {{ added }} foi adicionado à turma.
        </p>
        <InputError :message="error ?? undefined" />
    </div>
</template>
