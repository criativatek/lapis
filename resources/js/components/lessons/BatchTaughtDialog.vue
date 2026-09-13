<script setup lang="ts">
/**
 * «Marcar aulas como lecionadas» em lote — os quatro modos num só diálogo.
 *
 * UM DIÁLOGO E NÃO QUATRO BOTÕES SOLTOS: os quatro modos respondem à mesma
 * pergunta («quais?») e acabam todos na mesma confirmação com a mesma contagem.
 * Espalhados pela barra, cada um precisaria da sua própria confirmação, e seriam
 * quatro sítios onde a contagem podia passar a dizer outra coisa.
 *
 * A CONTAGEM VEM DO SERVIDOR, sempre. Contar as aulas visíveis no ecrã daria um
 * número parecido e por vezes errado — o modo «Hoje» fala de um dia que pode não
 * estar na semana apresentada, e a elegibilidade é uma regra de domínio, não uma
 * propriedade do que está desenhado. O `preview` devolve o que o `store` vai
 * fazer, pela mesma consulta.
 */
import { router, useForm } from '@inertiajs/vue3';
import { CheckCheck } from '@lucide/vue';
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

type Mode = 'today' | 'week' | 'selection' | 'range';

type Preview = {
    found: number;
    eligible: number;
    range: { from: string | null; to: string | null };
    lessons: { ulid: string; context_label: string; lesson_number: number | null; starts_at: string }[];
    ineligible: { ulid: string; context_label: string; starts_at: string; reason: string }[];
};

const props = defineProps<{
    weekStart: string;
    today: string;
    selected: string[];
}>();

const open = ref(false);
const mode = ref<Mode>('today');
const from = ref(props.weekStart);
const to = ref(props.weekStart);
const preview = ref<Preview | null>(null);
const loading = ref(false);
const failure = ref<string | null>(null);

const form = useForm({
    mode: 'today' as Mode,
    week: props.weekStart,
    from: props.weekStart,
    to: props.weekStart,
    ulids: [] as string[],
});

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'Europe/Lisbon',
});
const dayTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

/**
 * «Hoje» e «Dia selecionado» são coisas diferentes e têm nomes diferentes
 * (§30): o ecrã navega para outras semanas, e um «Hoje» que seguisse a vista
 * marcaria aulas de outra semana sem o dizer.
 */
const modes = computed<{ value: Mode; label: string; hint: string; disabled?: boolean }[]>(() => [
    {
        value: 'today',
        label: 'Hoje',
        hint: `O dia de hoje (${dateFormatter.format(new Date(`${props.today}T12:00:00Z`))}), esteja ou não na semana apresentada.`,
    },
    {
        value: 'week',
        label: 'Semana apresentada',
        hint: 'A semana que está neste momento no ecrã, de segunda a domingo.',
    },
    {
        value: 'selection',
        label: 'Aulas selecionadas',
        hint:
            props.selected.length === 0
                ? 'Seleciona primeiro aulas na lista ou no horário.'
                : `${props.selected.length} aula${props.selected.length === 1 ? '' : 's'} selecionada${props.selected.length === 1 ? '' : 's'}.`,
        disabled: props.selected.length === 0,
    },
    {
        value: 'range',
        label: 'Outro intervalo',
        hint: 'Escolhe uma data inicial e uma data final.',
    },
]);

function payload(): Record<string, unknown> {
    return {
        mode: mode.value,
        week: props.weekStart,
        from: from.value,
        to: to.value,
        ulids: props.selected,
    };
}

/** Recarregada sempre que o modo ou as datas mudam: o número na confirmação
 *  nunca pode ser o de uma pergunta anterior. */
async function loadPreview(): Promise<void> {
    if (!open.value) {
        return;
    }

    loading.value = true;
    failure.value = null;
    preview.value = null;

    try {
        const response = await fetch('/lessons/batch-taught/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
            body: JSON.stringify(payload()),
        });

        if (!response.ok) {
            failure.value = 'Não foi possível calcular as aulas abrangidas.';

            return;
        }

        preview.value = (await response.json()) as Preview;
    } catch {
        failure.value = 'Não foi possível calcular as aulas abrangidas.';
    } finally {
        loading.value = false;
    }
}

watch([open, mode, from, to, () => props.selected], loadPreview, { immediate: false });

watch(open, (value) => {
    if (value) {
        from.value = props.weekStart;
        to.value = props.weekStart;
    }
});

function submit(): void {
    form.mode = mode.value;
    form.week = props.weekStart;
    form.from = from.value;
    form.to = to.value;
    form.ulids = [...props.selected];
    form.post('/lessons/batch-taught', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            router.reload({ only: ['lessons'] });
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm" class="min-h-9">
                <CheckCheck class="size-4" /> Marcar lecionadas
            </Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-lg">
            <DialogHeader class="space-y-2">
                <DialogTitle>Marcar aulas como lecionadas</DialogTitle>
                <DialogDescription>
                    Escolhe quais. Nada é alterado antes de confirmares.
                </DialogDescription>
            </DialogHeader>

            <fieldset class="space-y-2">
                <legend class="sr-only">Que aulas marcar</legend>
                <label
                    v-for="option in modes"
                    :key="option.value"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
                    :class="mode === option.value ? 'border-primary bg-muted/40' : ''"
                >
                    <input
                        v-model="mode"
                        type="radio"
                        name="batch-mode"
                        class="mt-1 size-4"
                        :value="option.value"
                        :disabled="option.disabled"
                    />
                    <span class="min-w-0">
                        <span class="block font-medium">{{ option.label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                    </span>
                </label>
            </fieldset>

            <div v-if="mode === 'range'" class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="batch-from">Data inicial</Label>
                    <Input id="batch-from" v-model="from" type="date" class="min-h-11" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="batch-to">Data final</Label>
                    <Input id="batch-to" v-model="to" type="date" class="min-h-11" />
                </div>
            </div>

            <div class="rounded-lg border bg-muted/30 p-3 text-sm" role="status" aria-live="polite">
                <p v-if="loading" class="text-muted-foreground">A calcular…</p>
                <p v-else-if="failure" class="text-destructive">{{ failure }}</p>
                <template v-else-if="preview">
                    <p class="font-medium">
                        {{ preview.eligible }} aula{{ preview.eligible === 1 ? '' : 's' }} por marcar,
                        de {{ preview.found }} encontrada{{ preview.found === 1 ? '' : 's' }}.
                    </p>
                    <p
                        v-if="preview.range.from"
                        class="mt-0.5 text-xs text-muted-foreground tabular-nums"
                    >
                        {{ dateFormatter.format(new Date(`${preview.range.from}T12:00:00Z`)) }} –
                        {{ dateFormatter.format(new Date(`${preview.range.to}T12:00:00Z`)) }}
                    </p>
                    <ul
                        v-if="preview.lessons.length > 0"
                        class="mt-2 max-h-40 space-y-0.5 overflow-y-auto text-xs text-muted-foreground"
                    >
                        <li v-for="lesson in preview.lessons" :key="lesson.ulid" class="tabular-nums">
                            {{ dayTimeFormatter.format(new Date(lesson.starts_at)) }} ·
                            {{ lesson.context_label }}
                            <span v-if="lesson.lesson_number">· Lição {{ lesson.lesson_number }}</span>
                        </li>
                    </ul>
                    <!-- O que NÃO vai ser tocado, e porquê: sem isto, uma aula
                         que fica por marcar parece um erro silencioso. -->
                    <div v-if="preview.ineligible.length > 0" class="mt-2 border-t pt-2">
                        <p class="text-xs font-medium">
                            {{ preview.ineligible.length }} não elegíve{{
                                preview.ineligible.length === 1 ? 'l' : 'is'
                            }}:
                        </p>
                        <ul class="mt-0.5 max-h-24 space-y-0.5 overflow-y-auto text-xs text-muted-foreground">
                            <li v-for="lesson in preview.ineligible" :key="lesson.ulid">
                                {{ dayTimeFormatter.format(new Date(lesson.starts_at)) }} ·
                                {{ lesson.context_label }} — {{ lesson.reason }}
                            </li>
                        </ul>
                    </div>
                </template>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button type="button" variant="outline" class="min-h-11">Cancelar</Button>
                </DialogClose>
                <Button
                    type="button"
                    class="min-h-11"
                    :disabled="form.processing || loading || (preview?.eligible ?? 0) === 0"
                    @click="submit"
                >
                    Marcar {{ preview?.eligible ?? 0 }} aula{{
                        (preview?.eligible ?? 0) === 1 ? '' : 's'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
