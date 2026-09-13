<script setup lang="ts">
/**
 * «Inserir aula» — pôr uma aula no meio de uma sequência já preparada e
 * empurrar as seguintes para as próximas ocorrências válidas do horário.
 *
 * A PRÉ-VISUALIZAÇÃO NÃO É UM EXTRA (§20). Esta é a única operação desta fatia
 * que mexe em aulas que o professor não está a olhar — pode deslocar quatro
 * aulas preparadas de uma vez —, e confirmá-la sem ver o que vai acontecer é
 * pedir-lhe que confie num número. Aqui mostra-se linha a linha: de que dia para
 * que dia, e de que lição para que lição.
 *
 * A PRÓPRIA PRÉ-VISUALIZAÇÃO É QUE RECUSA, quando há o que recusar: se a
 * sequência contiver uma aula já lecionada, ou se o horário não tiver ocorrências
 * suficientes até ao fim do ano, o servidor devolve a mensagem e não há nada para
 * confirmar. É o mesmo `plan()` que a execução usa, pelo que nunca acontece
 * confirmar uma coisa que depois falha.
 */
import { useForm } from '@inertiajs/vue3';
import { ArrowRight, CalendarPlus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type InsertableClass = {
    ulid: string;
    label: string;
    subject: string;
    groups: { id: number; label: string }[];
};

type Preview = {
    inserted_at: string;
    shifted_count: number;
    moves: {
        ulid: string;
        context_label: string;
        from: string;
        to: string;
        lesson_number: number | null;
    }[];
};

const props = defineProps<{
    classes: InsertableClass[];
    defaultDate: string;
}>();

const open = ref(false);
const classUlid = ref(props.classes[0]?.ulid ?? '');
const classGroupId = ref<string>('');
const insertAt = ref(props.defaultDate);
const preview = ref<Preview | null>(null);
const loading = ref(false);
const failure = ref<string | null>(null);

const form = useForm({
    class: '',
    class_group_id: null as number | null,
    insert_at: '',
});

const selectedClass = computed(
    () => props.classes.find((entry) => entry.ulid === classUlid.value) ?? null,
);

const dayFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

// Trocar de turma limpa o grupo: um id de grupo de outra turma não faz sentido
// nenhum, e o servidor recusá-lo-ia de qualquer maneira.
watch(classUlid, () => {
    classGroupId.value = '';
});

async function loadPreview(): Promise<void> {
    if (!open.value || classUlid.value === '' || insertAt.value === '') {
        return;
    }

    loading.value = true;
    failure.value = null;
    preview.value = null;

    try {
        const response = await fetch('/lessons/insert/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify({
                class: classUlid.value,
                class_group_id: classGroupId.value === '' ? null : Number(classGroupId.value),
                insert_at: insertAt.value,
            }),
        });

        const body = await response.json();

        if (!response.ok) {
            // 422 traz a mensagem real do domínio — «contém uma aula já
            // lecionada em 12/09», «não há ocorrências suficientes» —, e é essa
            // que o professor precisa de ler, não uma genérica.
            failure.value =
                (body?.errors?.insert_at?.[0] as string | undefined) ??
                (body?.message as string | undefined) ??
                'Não foi possível calcular a inserção.';

            return;
        }

        preview.value = body as Preview;
    } catch {
        failure.value = 'Não foi possível calcular a inserção.';
    } finally {
        loading.value = false;
    }
}

watch([open, classUlid, classGroupId, insertAt], loadPreview);

function submit(): void {
    form.class = classUlid.value;
    form.class_group_id = classGroupId.value === '' ? null : Number(classGroupId.value);
    form.insert_at = insertAt.value;
    form.post('/lessons/insert', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm" class="min-h-9">
                <CalendarPlus class="size-4" /> Inserir aula
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader class="space-y-2">
                <DialogTitle>Inserir aula na sequência</DialogTitle>
                <DialogDescription>
                    A aula ocupa a próxima ocorrência do horário a partir da data
                    escolhida. As aulas seguintes ainda não lecionadas são deslocadas
                    para as ocorrências seguintes.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-3">
                <div class="grid gap-1.5">
                    <Label for="insert-class">Turma</Label>
                    <select
                        id="insert-class"
                        v-model="classUlid"
                        class="min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option v-for="entry in classes" :key="entry.ulid" :value="entry.ulid">
                            {{ entry.label }} — {{ entry.subject }}
                        </option>
                    </select>
                </div>

                <!-- Só quando a turma é desdobrada: numa turma sem grupos, um
                     campo «Participantes» com uma única opção é ruído. -->
                <div v-if="(selectedClass?.groups.length ?? 0) > 0" class="grid gap-1.5">
                    <Label for="insert-group">Participantes</Label>
                    <select
                        id="insert-group"
                        v-model="classGroupId"
                        class="min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Turma inteira</option>
                        <option
                            v-for="group in selectedClass?.groups ?? []"
                            :key="group.id"
                            :value="String(group.id)"
                        >
                            {{ group.label }}
                        </option>
                    </select>
                    <p class="text-xs text-muted-foreground">
                        Cada grupo tem a sua própria sequência: inserir em T1 não mexe
                        nas aulas de T2.
                    </p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="insert-at">Inserir a partir de</Label>
                    <Input id="insert-at" v-model="insertAt" type="date" class="min-h-11" />
                </div>
            </div>

            <div class="rounded-lg border bg-muted/30 p-3 text-sm" role="status" aria-live="polite">
                <p v-if="loading" class="text-muted-foreground">A calcular…</p>
                <p v-else-if="failure" class="text-destructive">{{ failure }}</p>
                <template v-else-if="preview">
                    <p class="font-medium">
                        Nova aula a
                        {{ dayFormatter.format(new Date(preview.inserted_at)) }}.
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{
                            preview.shifted_count === 0
                                ? 'Nenhuma aula é deslocada.'
                                : `Esta operação irá deslocar ${preview.shifted_count} aula${preview.shifted_count === 1 ? '' : 's'} preparada${preview.shifted_count === 1 ? '' : 's'}.`
                        }}
                    </p>
                    <ul
                        v-if="preview.moves.length > 0"
                        class="mt-2 max-h-44 space-y-0.5 overflow-y-auto text-xs text-muted-foreground"
                    >
                        <li
                            v-for="move in preview.moves"
                            :key="move.ulid"
                            class="flex items-center gap-1.5 tabular-nums"
                        >
                            <span v-if="move.lesson_number" class="shrink-0"
                                >Lição {{ move.lesson_number }} →
                                {{ move.lesson_number + 1 }}</span
                            >
                            <span class="shrink-0">{{
                                dayFormatter.format(new Date(move.from))
                            }}</span>
                            <ArrowRight class="size-3 shrink-0" />
                            <span class="shrink-0">{{
                                dayFormatter.format(new Date(move.to))
                            }}</span>
                        </li>
                    </ul>
                </template>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button type="button" variant="outline" class="min-h-11">Cancelar</Button>
                </DialogClose>
                <Button
                    type="button"
                    class="min-h-11"
                    :disabled="form.processing || loading || preview === null"
                    @click="submit"
                >
                    Inserir aula
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
