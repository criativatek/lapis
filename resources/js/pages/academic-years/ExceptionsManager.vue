<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CalendarOff, Pencil, Plus, Sparkles, Trash2 } from '@lucide/vue';
import type { ComponentPublicInstance } from 'vue';
import { computed, nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { value: string; label: string };

/** Um feriado, uma interrupção letiva ou um dia não letivo, JÁ GRAVADO. */
type CalendarException = {
    ulid: string;
    type: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
};

/** Os campos editáveis — o que um pedido leva, e nada mais. */
type ExceptionFields = {
    type: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string;
};

const props = defineProps<{
    academicYear: { ulid: string; starts_on: string; ends_on: string };
    exceptions: CalendarException[];
    exceptionTypes: Option[];
}>();

/**
 * OS DIAS EM QUE NÃO HÁ AULA — cada um com os seus próprios gestos.
 *
 * O QUE MUDOU, E PORQUÊ. Isto vivia dentro do formulário grande do ano letivo:
 * todas as linhas sempre abertas em campos de texto, uma linha nova a cair no
 * fundo de uma lista comprida, e um só «Guardar ano letivo» lá em baixo a
 * gravar tudo. A verificação em uso real apanhou as três consequências: não se
 * via como editar (estava tudo sempre «em edição»), não se via a linha
 * acabada de acrescentar, e a página parecia prometer um «Guardar» por linha
 * que não existia.
 *
 * AGORA CADA EXCEÇÃO É UMA COISA COM TRÊS BOTÕES. Em repouso é TEXTO — o tipo,
 * a designação e as datas, lidos de relance — com «Editar» e «Eliminar» ao lado.
 * «Editar» abre AQUELA linha, e só aquela, em campos, com «Cancelar» e
 * «Guardar». E «Guardar» é um PUT verdadeiro: quando ele volta, o que está no
 * ecrã é o que está gravado, porque são as props da própria página que o Inertia
 * traz de volta — e não uma cópia local a fingir que sim.
 *
 * A LINHA NOVA APARECE EM CIMA, e não no fundo. Uma exceção acabada de escrever
 * é a única coisa que interessa ver naquele instante; procurá-la no fim de
 * quinze feriados era o defeito exato que isto corrige. Assim que fica gravada
 * deixa de ter esse privilégio: vai para onde a sua data manda, na ordem que o
 * servidor decide (starts_on, depois id) e que esta página nunca reordena.
 *
 * OS PERÍODOS NÃO ESTÃO AQUI, e não mudaram: continuam no formulário do ano, com
 * o seu botão de sempre. São duas coisas diferentes e ficaram com dois desenhos
 * diferentes.
 */

// --------------------------------------------------- o que está aberto

/**
 * UMA LINHA ABERTA DE CADA VEZ, e sai de graça: há um só `useForm`, pelo que
 * abrir uma linha (ou a linha nova) é escolher o que ele está a editar. Não há
 * aqui mecanismo nenhum a «fechar as outras» — não há outras.
 */
const editingUlid = ref<string | null>(null);
const creating = ref(false);

/** A pergunta antes de eliminar. Ver o Dialog no fim do ficheiro. */
const confirmingDelete = ref<CalendarException | null>(null);

const form = useForm<ExceptionFields>({
    type: 'holiday',
    title: '',
    starts_on: '',
    ends_on: '',
    note: '',
});

/**
 * As linhas por desenhar: a que ainda não existe primeiro — por não fazer parte
 * da lista gravada e ordenada — e a seguir as gravadas, na ordem em que o
 * servidor as mandou.
 */
type Row = { key: string; exception: CalendarException | null };

const rows = computed<Row[]>(() => [
    ...(creating.value ? [{ key: 'new', exception: null }] : []),
    ...props.exceptions.map((exception) => ({
        key: exception.ulid,
        exception,
    })),
]);

function isEditing(row: Row): boolean {
    // Uma linha que ainda não existe não tem estado de repouso nenhum para
    // mostrar: nasce em campos, que é a única forma que ela tem.
    return row.exception === null || editingUlid.value === row.exception.ulid;
}

// ---------------------------------------------------------- abrir e fechar

/**
 * O foco vai para a designação, que é o campo por onde se começa. E mais nada:
 * o próprio navegador leva à vista o que acaba de receber o foco, e a linha nova
 * está por cima da lista e não no fundo dela — não há aqui nada para corrigir
 * com um salto de página escrito à mão.
 */
const titleInput = ref<HTMLInputElement | null>(null);

/**
 * Uma FUNÇÃO de ref, e não um ref de nome: um ref de nome escrito dentro de um
 * `v-for` chega sempre como array — mesmo aqui, onde há uma linha aberta de cada
 * vez e o array teria sempre um elemento. E guarda o `<input>` verdadeiro, e não
 * o componente que o embrulha, para quem lê a seguir não ter de saber disso.
 */
function bindTitleInput(
    element: Element | ComponentPublicInstance | null,
): void {
    const node = element !== null && '$el' in element ? element.$el : element;

    titleInput.value = node instanceof HTMLInputElement ? node : null;
}

function focusTitle(): void {
    void nextTick(() => titleInput.value?.focus());
}

function startCreate(): void {
    editingUlid.value = null;
    creating.value = true;
    form.clearErrors();
    form.defaults({
        // Um feriado é o caso mais comum, e é o primeiro do enum — mas as datas
        // ficam por preencher: não há palpite honesto nenhum para dar sobre QUE
        // dia é que o professor está a pensar.
        type: props.exceptionTypes[0]?.value ?? 'holiday',
        title: '',
        starts_on: '',
        ends_on: '',
        note: '',
    });
    form.reset();
    focusTitle();
}

function startEdit(exception: CalendarException): void {
    creating.value = false;
    editingUlid.value = exception.ulid;
    form.clearErrors();
    form.defaults({
        type: exception.type,
        title: exception.title,
        starts_on: exception.starts_on,
        ends_on: exception.ends_on,
        note: exception.note ?? '',
    });
    form.reset();
    focusTitle();
}

/**
 * CANCELAR NÃO PEDE NADA AO SERVIDOR. Numa linha que já existe, o que fica no
 * ecrã são os valores GRAVADOS — que estão intactos nas props desta página,
 * porque o que se andou a escrever era uma cópia dentro do formulário e nunca
 * a linha. Numa linha nova, cancelar é a linha deixar de existir.
 */
function cancel(): void {
    creating.value = false;
    editingUlid.value = null;
    form.clearErrors();
}

// ------------------------------------------------------------- as datas

/**
 * A DATA DE FIM SEGUE A DE INÍCIO ENQUANTO AS DUAS FOREM A MESMA — e deixa de a
 * seguir no instante em que deixam de ser.
 *
 * Um feriado é de um dia, e um dia é «de 5 a 5»: escolher 5 de outubro e ficar
 * com a data de fim vazia obrigava a escrever a mesma data duas vezes. Pior:
 * corrigir DEPOIS a data de início — de 26/05 para 02/06 — deixava «de 02/06 a
 * 26/05», um intervalo ao contrário que o servidor recusa e que o professor não
 * escreveu de propósito.
 *
 * E NÃO ARRASTA UM INTERVALO ESCOLHIDO. «21/12 a 02/01» é uma decisão: mexer na
 * data de início não lhe pode encolher o fim em silêncio. Daí a pergunta ser
 * feita ANTES de escrever — se as duas eram iguais — e não depois.
 */
function setStartsOn(next: string): void {
    const wasSingleDay = form.ends_on === form.starts_on;

    form.starts_on = next;

    if (form.ends_on === '' || wasSingleDay) {
        form.ends_on = next;
    }
}

// ---------------------------------------------------- gravar e eliminar

function exceptionUrl(exception: CalendarException): string {
    return `/academic-years/${props.academicYear.ulid}/exceptions/${exception.ulid}`;
}

/**
 * UM PEDIDO A SÉRIO, e um por gesto. A resposta do Inertia traz as props da
 * página já refrescadas — a lista reordenada, a linha com o que ficou gravado —
 * pelo que fechar a linha é tudo o que aqui há para fazer. Se a validação
 * recusar, nada disto corre: a linha fica aberta, com o que estava escrito e com
 * os erros do servidor por baixo dos campos.
 */
function save(row: Row): void {
    if (row.exception === null) {
        form.post(`/academic-years/${props.academicYear.ulid}/exceptions`, {
            preserveScroll: true,
            onSuccess: () => {
                creating.value = false;
            },
        });

        return;
    }

    form.put(exceptionUrl(row.exception), {
        preserveScroll: true,
        onSuccess: () => {
            editingUlid.value = null;
        },
    });
}

/**
 * Fechar por qualquer via — o X do próprio Dialog, a tecla Escape, o clique
 * fora — é o mesmo que «Cancelar»: a pergunta desaparece e não se elimina nada.
 */
function onDeleteDialogToggle(open: boolean): void {
    if (!open) {
        confirmingDelete.value = null;
    }
}

/**
 * A pergunta é DA APLICAÇÃO e não do navegador — o mesmo desenho das outras
 * confirmações destrutivas daqui (ver Month.vue e PasskeyItem.vue), e não uma
 * caixa cinzenta que não se parece com nada do resto da página. Enquanto ela
 * está no ar não parte pedido nenhum, e é só aqui que ele parte.
 */
function destroy(): void {
    const target = confirmingDelete.value;

    if (target === null) {
        return;
    }

    confirmingDelete.value = null;

    router.delete(exceptionUrl(target), { preserveScroll: true });
}

// ------------------------------------------------------- o estado de repouso

function typeLabel(value: string): string {
    return (
        props.exceptionTypes.find((type) => type.value === value)?.label ??
        value
    );
}

/**
 * «05/10/2026», e COM O ANO — ao contrário da forma compacta que o calendário
 * usa nas suas próprias faixas. Aqui a lista atravessa dois anos civis («21/12 –
 * 02/01») e omitir o ano seria deixar por dizer justamente a coisa que distingue
 * as duas pontas de uma interrupção de Natal.
 */
const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

function formatDate(date: string): string {
    return dateFormatter.format(new Date(`${date}T00:00:00Z`));
}

/**
 * Um dia só diz-se uma vez; um intervalo diz as duas pontas.
 *
 * Pede as DUAS DATAS e não uma exceção inteira: serve tanto uma linha desta
 * lista como o retrato do que já lá está que o diálogo de sugestões mostra, e as
 * duas são a mesma pergunta.
 */
function dateRange(range: { starts_on: string; ends_on: string }): string {
    return range.starts_on === range.ends_on
        ? formatDate(range.starts_on)
        : `${formatDate(range.starts_on)} – ${formatDate(range.ends_on)}`;
}

// ─────────────────────────────────────── sugerir feriados nacionais (§17)

/**
 * OS FERIADOS OFICIAIS DO PAÍS DESTE ANO LETIVO, propostos linha a linha.
 *
 * O PAÍS NÃO SE PERGUNTA AQUI. Sai de `academic_years.country_code`, que o
 * formulário do ano já tem e que é o único sítio onde se decide (§16). Um ano
 * cujo país esta versão não conheça recebe uma frase a dizê-lo — e nunca o
 * calendário português por engano.
 *
 * A LISTA VEM DO SERVIDOR JÁ COMPARADA com o que está no calendário: cada linha
 * chega com o seu estado. Este componente não sabe deduplicar feriados nem
 * precisa de saber — a regra é uma só, vive em MatchAcademicCalendarExceptions,
 * e é a mesma que a importação do .xlsx usa. E é ela outra vez, contra a base de
 * dados, que decide o que se escreve quando o botão de confirmar é premido: o
 * que esta lista disse há dez segundos nunca é a garantia.
 */
type SuggestionState = 'new' | 'exists' | 'correspondence' | 'conflict';

/** O que já está no calendário naquele dia, quando já está lá alguma coisa. */
type MatchedException = {
    ulid: string;
    type_label: string;
    title: string;
    starts_on: string;
    ends_on: string;
    source_label: string;
};

type SuggestedHoliday = {
    date: string;
    title: string;
    state: SuggestionState;
    current: MatchedException | null;
};

type SuggestionsResponse = {
    country_code: string;
    supported: boolean;
    message: string | null;
    suggestions: SuggestedHoliday[];
};

const suggesting = ref(false);
const loadingSuggestions = ref(false);
const suggestions = ref<SuggestedHoliday[]>([]);
const suggestionsSupported = ref(true);
const suggestionsMessage = ref<string | null>(null);
const suggestionsFailed = ref(false);
/** As datas marcadas, por data — a chave natural de um feriado nesta lista. */
const chosenDates = ref<Record<string, boolean>>({});
const savingSuggestions = ref(false);

const suggestionsUrl = computed(
    () => `/academic-years/${props.academicYear.ulid}/holiday-suggestions`,
);

const stateLabels: Record<SuggestionState, string> = {
    new: 'Novo',
    exists: 'Já existente',
    correspondence: 'Designação diferente',
    conflict: 'Conflito',
};

/**
 * SÓ SE PODE MARCAR O QUE TEM ALGUMA COISA PARA ACONTECER.
 *
 * «Novo» cria. «Designação diferente» muda o nome da linha que já lá está — não
 * cria uma segunda, e é isso que a frase ao lado da caixa diz. Os outros dois
 * não têm ação nenhuma por trás: «já existente» está lá igualzinho, e um
 * «conflito» nunca é resolvido por aproximação (§32) — o servidor saltá-lo-ia na
 * mesma, e uma caixa cuja única consequência é «não aconteceu nada» é uma caixa
 * que mente. Ficam à vista, com as duas versões, e resolvem-se na lista de cima,
 * que é onde se editam e se apagam exceções.
 */
function isChoosable(state: SuggestionState): boolean {
    return state === 'new' || state === 'correspondence';
}

/** O que a caixa daquela linha FAZ, dito por palavras ao lado dela. */
function suggestionHint(row: SuggestedHoliday): string | null {
    if (row.state === 'correspondence') {
        return `Marcar muda a designação para «${row.title}». As datas e a proveniência ficam como estão; nenhuma linha nova é criada.`;
    }

    if (row.state === 'exists') {
        return 'Já está no calendário com esta designação. Não há nada a fazer.';
    }

    if (row.state === 'conflict') {
        return 'Sobrepõe-se a algo que já está no calendário sem ser o mesmo intervalo. Resolva-o na lista acima — nada é criado nem alterado por aqui.';
    }

    return null;
}

const chosenCount = computed(
    () =>
        suggestions.value.filter(
            (row) => isChoosable(row.state) && chosenDates.value[row.date],
        ).length,
);

async function openSuggestions(): Promise<void> {
    suggesting.value = true;
    loadingSuggestions.value = true;
    suggestionsFailed.value = false;
    suggestions.value = [];
    chosenDates.value = {};

    try {
        const response = await fetch(suggestionsUrl.value, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            suggestionsFailed.value = true;

            return;
        }

        const payload = (await response.json()) as SuggestionsResponse;

        suggestionsSupported.value = payload.supported;
        suggestionsMessage.value = payload.message;
        suggestions.value = payload.suggestions;

        // OS NOVOS VÊM MARCADOS, e mais nenhum: não há nada para destruir num
        // feriado que ainda não existe, e a alternativa era picar treze caixas
        // para dizer «sim» treze vezes. Uma designação diferente não vem marcada
        // nunca — mudar o nome de uma linha que o professor escreveu é uma
        // decisão dele e não um efeito secundário de abrir uma lista.
        for (const row of payload.suggestions) {
            chosenDates.value[row.date] = row.state === 'new';
        }
    } catch {
        suggestionsFailed.value = true;
    } finally {
        loadingSuggestions.value = false;
    }
}

/**
 * Fechar por qualquer via — «Cancelar», o X, Escape, o clique fora — não pede
 * nada ao servidor e não escreve nada. A leitura que abriu a lista já tinha
 * acontecido; fechar é deitá-la fora.
 */
function onSuggestionsDialogToggle(open: boolean): void {
    if (!open) {
        suggesting.value = false;
    }
}

/**
 * SÓ AS MARCADAS, e só as datas. O título com que cada feriado é gravado é o do
 * servidor e nunca o desta página — é isso que faz de `source = suggested` uma
 * palavra honesta.
 */
function confirmSuggestions(): void {
    const dates = suggestions.value
        .filter((row) => isChoosable(row.state) && chosenDates.value[row.date])
        .map((row) => row.date);

    if (dates.length === 0) {
        return;
    }

    savingSuggestions.value = true;

    router.post(
        suggestionsUrl.value,
        { dates },
        {
            preserveScroll: true,
            // A lista de exceções da página vem refrescada nas props da própria
            // resposta, como em todas as outras escritas deste componente.
            onSuccess: () => {
                suggesting.value = false;
            },
            onFinish: () => {
                savingSuggestions.value = false;
            },
        },
    );
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <CalendarOff class="size-4" aria-hidden="true" />
                    Feriados e interrupções
                </h2>
                <p class="text-sm text-muted-foreground">
                    Os dias em que não há aula. Aparecem no Calendário do Ano
                    Letivo e têm de estar dentro do ano. Cada um guarda-se por
                    si.
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <!--
                    OS FERIADOS OFICIAIS, A UM BOTÃO DE DISTÂNCIA — e nunca
                    escritos sem passar por aqui. Abre uma lista para rever; não
                    grava nada.
                -->
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-suggest-holidays
                    @click="openSuggestions"
                >
                    <Sparkles class="size-4" /> Sugerir feriados nacionais
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-add-exception
                    @click="startCreate"
                >
                    <Plus class="size-4" /> Adicionar
                </Button>
            </div>
        </div>

        <p
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"
        >
            Ainda não há feriados nem interrupções neste ano letivo.
        </p>

        <div
            v-for="row in rows"
            :key="row.key"
            class="rounded-lg border border-border p-4"
            :data-exception-row="row.exception?.ulid ?? 'new'"
        >
            <!--
                EM REPOUSO É TEXTO, e não campos vazios: uma página com quinze
                feriados deixa de parecer um muro de formulários por preencher, e
                passa a ler-se de relance — que é o que se faz a uma lista de
                feriados na esmagadora maioria das vezes que se abre esta página.
            -->
            <div
                v-if="!isEditing(row) && row.exception"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <div class="min-w-0 space-y-0.5">
                    <p class="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <span
                            class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                            >{{ typeLabel(row.exception.type) }}</span
                        >
                        <span class="font-medium">{{
                            row.exception.title
                        }}</span>
                        <span class="text-muted-foreground">{{
                            dateRange(row.exception)
                        }}</span>
                    </p>
                    <p
                        v-if="row.exception.note"
                        class="text-sm text-muted-foreground"
                    >
                        {{ row.exception.note }}
                    </p>
                </div>

                <!--
                    OS DOIS GESTOS, ESCRITOS. «Editar» faltava por completo — a
                    linha estava sempre aberta e por isso nunca houve um botão a
                    dizê-lo.
                -->
                <div class="flex shrink-0 items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Editar ${row.exception.title}`"
                        :data-edit-exception="row.exception.ulid"
                        @click="startEdit(row.exception)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Eliminar ${row.exception.title}`"
                        :data-delete-exception="row.exception.ulid"
                        @click="confirmingDelete = row.exception"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </div>

            <!--
                E EM EDIÇÃO SÃO CAMPOS — só esta linha, e com «Cancelar» e
                «Guardar» no lugar de «Editar» e «Eliminar». O «Guardar» é desta
                exceção e de mais nenhuma: não é o botão do ano letivo, que está
                noutra secção e trata de outra coisa.
            -->
            <form v-else class="space-y-3" @submit.prevent="save(row)">
                <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <div class="grid gap-1.5">
                        <Label for="exception-type" class="text-xs">Tipo</Label>
                        <select
                            id="exception-type"
                            v-model="form.type"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="type in exceptionTypes"
                                :key="type.value"
                                :value="type.value"
                            >
                                {{ type.label }}
                            </option>
                        </select>
                        <InputError :message="form.errors.type" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="exception-title" class="text-xs"
                            >Designação</Label
                        >
                        <Input
                            id="exception-title"
                            :ref="bindTitleInput"
                            v-model="form.title"
                            placeholder="Ex.: Interrupção de Natal"
                        />
                        <InputError :message="form.errors.title" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="exception-starts-on" class="text-xs"
                            >Início</Label
                        >
                        <!--
                            `min`/`max` são uma GENTILEZA e não a guarda: o
                            servidor volta a verificar que a data cai dentro do
                            ano, porque um limite escrito em HTML é uma palavra
                            que o cliente diz a si próprio.
                        -->
                        <Input
                            id="exception-starts-on"
                            type="date"
                            :model-value="form.starts_on"
                            :min="academicYear.starts_on"
                            :max="academicYear.ends_on"
                            @update:model-value="setStartsOn(String($event))"
                        />
                        <InputError :message="form.errors.starts_on" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="exception-ends-on" class="text-xs"
                            >Fim</Label
                        >
                        <Input
                            id="exception-ends-on"
                            v-model="form.ends_on"
                            type="date"
                            :min="academicYear.starts_on"
                            :max="academicYear.ends_on"
                        />
                        <InputError :message="form.errors.ends_on" />
                        <p class="text-xs text-muted-foreground">
                            Num feriado de um dia é igual à data de início.
                        </p>
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <Label for="exception-note" class="text-xs"
                        >Observação (opcional)</Label
                    >
                    <textarea
                        id="exception-note"
                        v-model="form.note"
                        rows="2"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                    />
                    <InputError :message="form.errors.note" />
                </div>

                <div class="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        data-cancel-exception
                        @click="cancel"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        size="sm"
                        data-save-exception
                        :disabled="form.processing"
                    >
                        Guardar
                    </Button>
                </div>
            </form>
        </div>

        <!--
            ELIMINAR PERGUNTA, E NÃO ELIMINA. O pedido só parte depois da
            confirmação, e o texto diz o que desaparece e o que não desaparece —
            para ninguém ter de o adivinhar.
        -->
        <Dialog
            :open="confirmingDelete !== null"
            @update:open="onDeleteDialogToggle"
        >
            <DialogContent class="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Eliminar do calendário?</DialogTitle>
                    <DialogDescription>
                        «{{ confirmingDelete?.title }}» deixa de constar do
                        calendário deste ano letivo, e aqueles dias voltam a ser
                        letivos. Os períodos, as avaliações, o horário e os
                        acontecimentos não são afetados.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        data-cancel-delete
                        @click="confirmingDelete = null"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        data-confirm-delete
                        @click="destroy"
                    >
                        <Trash2 class="size-4" /> Eliminar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!--
            SUGERIR FERIADOS NACIONAIS — ver, escolher, e só depois gravar.

            O MESMO DESENHO DA CONFIRMAÇÃO DE ELIMINAR aqui em cima: um Dialog
            da aplicação, com o mesmo «Cancelar» à esquerda e a ação à direita.
            Nada parte para o servidor enquanto ele está no ar — a leitura que o
            encheu já tinha acontecido — e «Cancelar» é fechá-lo e deitar a
            lista fora.
        -->
        <Dialog :open="suggesting" @update:open="onSuggestionsDialogToggle">
            <DialogContent class="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Feriados nacionais</DialogTitle>
                    <DialogDescription>
                        Os feriados oficiais do país deste ano letivo que caem
                        dentro dele. Escolha os que quer acrescentar — nada é
                        gravado até confirmar.
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="loadingSuggestions"
                    class="py-4 text-sm text-muted-foreground"
                    data-suggestions-loading
                >
                    A calcular os feriados deste ano letivo…
                </p>

                <p
                    v-else-if="suggestionsFailed"
                    class="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"
                    data-suggestions-error
                >
                    Não foi possível obter os feriados nacionais. Tente de novo.
                </p>

                <!--
                    O PAÍS QUE ESTA VERSÃO NÃO CONHECE DIZ-SE POR PALAVRAS. Nunca
                    o calendário de outro país em silêncio: treze feriados
                    errados escritos sem aviso era o pior desfecho disponível.
                -->
                <p
                    v-else-if="!suggestionsSupported"
                    class="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"
                    data-suggestions-unsupported
                >
                    {{ suggestionsMessage }}
                </p>

                <p
                    v-else-if="suggestions.length === 0"
                    class="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground"
                >
                    Nenhum feriado nacional cai dentro das datas deste ano
                    letivo.
                </p>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="row in suggestions"
                        :key="row.date"
                        class="space-y-2 py-3"
                        :data-suggestion="row.date"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <label class="flex min-w-0 items-start gap-2.5">
                                <input
                                    v-model="chosenDates[row.date]"
                                    type="checkbox"
                                    class="mt-1"
                                    :disabled="!isChoosable(row.state)"
                                    :aria-label="
                                        row.state === 'correspondence'
                                            ? `Adotar a designação «${row.title}»`
                                            : `Acrescentar ${row.title}`
                                    "
                                    :data-suggestion-checkbox="row.date"
                                />
                                <span class="min-w-0 text-sm">
                                    <span class="block font-medium">{{
                                        row.title
                                    }}</span>
                                    <span class="block text-muted-foreground">
                                        {{ formatDate(row.date) }}
                                    </span>
                                    <span
                                        v-if="suggestionHint(row)"
                                        class="mt-1 block text-xs text-muted-foreground"
                                    >
                                        {{ suggestionHint(row) }}
                                    </span>
                                </span>
                            </label>
                            <Badge
                                :variant="
                                    row.state === 'new'
                                        ? 'secondary'
                                        : 'outline'
                                "
                                :data-suggestion-state="row.date"
                            >
                                {{ stateLabels[row.state] }}
                            </Badge>
                        </div>

                        <!--
                            AS DUAS VERSÕES LADO A LADO, e não só a proposta:
                            escolher entre dois nomes só é escolher se os dois
                            estiverem à vista ao mesmo tempo.
                        -->
                        <div
                            v-if="row.current"
                            class="rounded-md bg-muted/40 p-3 text-sm"
                            :data-suggestion-current="row.date"
                        >
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                No calendário deste ano letivo
                            </p>
                            <p class="mt-1">
                                {{ row.current.title }} ·
                                {{ dateRange(row.current) }}
                                <span class="text-xs text-muted-foreground">
                                    · {{ row.current.source_label }}
                                </span>
                            </p>
                        </div>
                    </li>
                </ul>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        data-cancel-suggestions
                        @click="suggesting = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        data-confirm-suggestions
                        :disabled="chosenCount === 0 || savingSuggestions"
                        @click="confirmSuggestions"
                    >
                        Adicionar selecionados ({{ chosenCount }})
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
