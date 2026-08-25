<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarOff, Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

type Period = {
    // Present for a period loaded from an existing year — its identity across
    // saves. Absent for a period added client-side via addPeriod(): the
    // backend then knows this is a brand-new period, never one being renamed.
    ulid?: string;
    label: string;
    kind: string;
    sequence: number;
    starts_on: string;
    ends_on: string;
};

/**
 * Uma exceção letiva — um feriado, uma interrupção ou um dia não letivo.
 *
 * VIVE NESTE FORMULÁRIO, e não numa página própria, porque é o que ela é: a
 * outra metade da estrutura deste ano. Quem define os períodos define isto, ao
 * mesmo tempo e no mesmo botão de guardar — não há aqui um segundo menu, um
 * segundo controlador nem uma segunda autorização.
 */
type Exception = {
    // Presente numa exceção carregada de um ano que já existe — a sua
    // identidade através das gravações. Ausente numa acrescentada aqui com
    // addException(): o servidor sabe então que é nova, e nunca uma a ser
    // renomeada. Exatamente a convenção dos períodos acima.
    ulid?: string;
    type: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
};

type YearData = {
    label: string;
    starts_on: string;
    ends_on: string;
    status: string;
    country_code: string;
    region_code: string | null;
    periods: Period[];
    exceptions: Exception[];
};

const props = defineProps<{
    statuses: Option[];
    periodKinds: Option[];
    exceptionTypes: Option[];
    initial?: YearData;
    submitUrl: string;
    method: 'post' | 'put';
}>();

const form = useForm<YearData>(
    props.initial ?? {
        label: '',
        starts_on: '',
        ends_on: '',
        status: 'draft',
        country_code: 'PT',
        region_code: null,
        periods: [
            { label: '1.º Semestre', kind: 'semester', sequence: 1, starts_on: '', ends_on: '' },
            { label: '2.º Semestre', kind: 'semester', sequence: 2, starts_on: '', ends_on: '' },
        ],
        // Um ano novo começa SEM exceção nenhuma, ao contrário dos períodos, que
        // começam com dois semestres por omissão. Não há aqui palpite honesto
        // nenhum para dar: os feriados de um ano dependem do país, da região e
        // do calendário que a escola publicar, e inventar uma lista seria
        // escrever no calendário do professor datas que ninguém confirmou.
        exceptions: [],
    },
);

function addPeriod(): void {
    form.periods.push({
        label: '',
        kind: 'semester',
        sequence: form.periods.length + 1,
        starts_on: '',
        ends_on: '',
    });
}

function removePeriod(index: number): void {
    form.periods.splice(index, 1);
}

// Period errors arrive under dot-keys (periods.0.starts_on) that the typed
// error map does not know about, so read them through a string index.
function periodError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[`periods.${index}.${field}`];
}

function addException(): void {
    form.exceptions.push({
        type: 'holiday',
        title: '',
        starts_on: '',
        ends_on: '',
        note: null,
    });
}

function removeException(index: number): void {
    form.exceptions.splice(index, 1);
}

// As mesmas chaves com pontos (exceptions.0.starts_on), lidas da mesma maneira.
function exceptionError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[
        `exceptions.${index}.${field}`
    ];
}

function submit(): void {
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="label">Designação</Label>
                    <Input id="label" v-model="form.label" placeholder="Ex.: 2026/2027" />
                    <InputError :message="form.errors.label" />
                </div>
                <div class="grid gap-2">
                    <Label for="status">Estado</Label>
                    <select
                        id="status"
                        v-model="form.status"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="status in statuses" :key="status.value" :value="status.value">
                            {{ status.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.status" />
                </div>
                <div class="grid gap-2">
                    <Label for="starts_on">Início</Label>
                    <Input id="starts_on" v-model="form.starts_on" type="date" />
                    <InputError :message="form.errors.starts_on" />
                </div>
                <div class="grid gap-2">
                    <Label for="ends_on">Fim</Label>
                    <Input id="ends_on" v-model="form.ends_on" type="date" />
                    <InputError :message="form.errors.ends_on" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Períodos de avaliação</h2>
                    <p class="text-sm text-muted-foreground">
                        Semestres, trimestres ou outra divisão. Cada período tem de estar dentro do ano.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addPeriod">
                    <Plus class="size-4" /> Adicionar período
                </Button>
            </div>
            <InputError :message="form.errors.periods" />

            <div
                v-for="(period, index) in form.periods"
                :key="index"
                class="grid items-end gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_1fr_5rem_1fr_1fr_auto]"
            >
                <div class="grid gap-1.5">
                    <Label :for="`period-label-${index}`" class="text-xs">Designação</Label>
                    <Input :id="`period-label-${index}`" v-model="period.label" placeholder="Ex.: 1.º Semestre" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-kind-${index}`" class="text-xs">Tipo</Label>
                    <select
                        :id="`period-kind-${index}`"
                        v-model="period.kind"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="kind in periodKinds" :key="kind.value" :value="kind.value">
                            {{ kind.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-seq-${index}`" class="text-xs">Ordem</Label>
                    <Input :id="`period-seq-${index}`" v-model.number="period.sequence" type="number" min="1" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-start-${index}`" class="text-xs">Início</Label>
                    <Input :id="`period-start-${index}`" v-model="period.starts_on" type="date" />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`period-end-${index}`" class="text-xs">Fim</Label>
                    <Input :id="`period-end-${index}`" v-model="period.ends_on" type="date" />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="form.periods.length <= 1"
                    aria-label="Remover período"
                    @click="removePeriod(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
                <InputError class="sm:col-span-6" :message="periodError(index, 'starts_on')" />
            </div>
        </section>

        <!--
            OS DIAS EM QUE NÃO HÁ AULA — a outra metade da estrutura do ano, e
            por isso aqui e não noutra página. Um feriado, uma interrupção
            letiva ou um dia não letivo é uma decisão sobre a forma DESTE ano
            letivo, tal como um semestre, e faz-se com os mesmos gestos: um
            botão de acrescentar, uma linha por cada, um caixote para remover, e
            um só «Guardar» no fim para tudo.

            E É DIFERENTE DE UM ACONTECIMENTO, que se cria no próprio calendário
            e é pessoal: uma reunião não apaga nenhuma aula, e isto é
            precisamente a coisa que diz que naquele dia não a há.

            A DATA DE FIM É OBRIGATÓRIA E COMEÇA IGUAL À DE INÍCIO. Um feriado é
            de um dia, e um dia é «de 5 a 5» — que é a mesma convenção que o
            resto da aplicação já usa e não uma segunda maneira de dizer o
            mesmo. Escrever a data de início preenche a de fim quando ela ainda
            está vazia, para o caso comum não custar dois campos.
        -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <CalendarOff class="size-4" aria-hidden="true" />
                        Feriados e interrupções
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Os dias em que não há aula. Aparecem no Calendário do
                        Ano Letivo e têm de estar dentro do ano.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addException">
                    <Plus class="size-4" /> Adicionar
                </Button>
            </div>
            <InputError :message="form.errors.exceptions" />

            <p
                v-if="form.exceptions.length === 0"
                class="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"
            >
                Ainda não há feriados nem interrupções neste ano letivo.
            </p>

            <div
                v-for="(exception, index) in form.exceptions"
                :key="index"
                class="grid items-end gap-3 rounded-lg border border-border p-4 sm:grid-cols-[10rem_1fr_1fr_1fr_auto]"
            >
                <div class="grid gap-1.5">
                    <Label :for="`exception-type-${index}`" class="text-xs">Tipo</Label>
                    <select
                        :id="`exception-type-${index}`"
                        v-model="exception.type"
                        class="border-input h-9 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="type in exceptionTypes" :key="type.value" :value="type.value">
                            {{ type.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`exception-title-${index}`" class="text-xs">Designação</Label>
                    <Input
                        :id="`exception-title-${index}`"
                        v-model="exception.title"
                        placeholder="Ex.: Interrupção de Natal"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`exception-start-${index}`" class="text-xs">Início</Label>
                    <Input
                        :id="`exception-start-${index}`"
                        v-model="exception.starts_on"
                        type="date"
                        @change="
                            exception.ends_on === ''
                                ? (exception.ends_on = exception.starts_on)
                                : undefined
                        "
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`exception-end-${index}`" class="text-xs">Fim</Label>
                    <Input :id="`exception-end-${index}`" v-model="exception.ends_on" type="date" />
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Remover feriado ou interrupção"
                    @click="removeException(index)"
                >
                    <Trash2 class="size-4" />
                </Button>
                <div class="grid gap-1.5 sm:col-span-5">
                    <Label :for="`exception-note-${index}`" class="text-xs">Observação (opcional)</Label>
                    <textarea
                        :id="`exception-note-${index}`"
                        v-model="exception.note"
                        rows="2"
                        class="border-input rounded-md border bg-transparent px-3 py-2 text-sm"
                    />
                </div>
                <InputError class="sm:col-span-5" :message="exceptionError(index, 'type')" />
                <InputError class="sm:col-span-5" :message="exceptionError(index, 'title')" />
                <InputError class="sm:col-span-5" :message="exceptionError(index, 'starts_on')" />
                <InputError class="sm:col-span-5" :message="exceptionError(index, 'ends_on')" />
                <InputError class="sm:col-span-5" :message="exceptionError(index, 'ulid')" />
            </div>
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">Guardar ano letivo</Button>
        </div>
    </form>
</template>
