import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as VueModule from 'vue';
import { nextTick } from 'vue';
import ExceptionsManager from './ExceptionsManager.vue';

const routerDelete = vi.fn();
const routerPost = vi.fn();
const formPost = vi.fn();
const formPut = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof VueModule>('vue');

    return {
        router: {
            delete: (...args: unknown[]) => routerDelete(...args),
            post: (...args: unknown[]) => routerPost(...args),
        },
        // Enough of useForm for this component: the fields themselves, the
        // errors bag the inputs read, and the two verbs it submits with.
        // `defaults()` writes straight onto the form, which is what loading a
        // row into the editor does in the real thing too.
        useForm: (initial: Record<string, unknown>) =>
            reactive({
                ...initial,
                errors: {} as Record<string, string>,
                processing: false,
                defaults(values: Record<string, unknown>) {
                    Object.assign(this, values);
                },
                reset() {},
                clearErrors() {},
                post: (...args: unknown[]) => formPost(...args),
                put: (...args: unknown[]) => formPut(...args),
            }),
    };
});

type CalendarException = {
    ulid: string;
    type: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
};

const TYPES = [
    { value: 'holiday', label: 'Feriado' },
    { value: 'school_break', label: 'Interrupção letiva' },
    { value: 'non_teaching_day', label: 'Dia não letivo' },
];

function exception(
    overrides: Partial<CalendarException> = {},
): CalendarException {
    return {
        ulid: 'exc-a',
        type: 'holiday',
        title: 'Implantação da República',
        starts_on: '2026-10-05',
        ends_on: '2026-10-05',
        note: null,
        ...overrides,
    };
}

const NATAL = exception({
    ulid: 'exc-b',
    type: 'school_break',
    title: 'Interrupção de Natal',
    starts_on: '2026-12-21',
    ends_on: '2027-01-02',
    note: 'Regresso a 5 de janeiro.',
});

function mountManager(exceptions: CalendarException[] = [exception()]) {
    return mount(ExceptionsManager, {
        props: {
            academicYear: {
                ulid: 'year-a',
                starts_on: '2026-09-01',
                ends_on: '2027-07-31',
            },
            exceptions,
            exceptionTypes: TYPES,
        },
        attachTo: document.body,
    });
}

function field(id: string): HTMLInputElement | null {
    return document.body.querySelector<HTMLInputElement>(`#${id}`);
}

/**
 * «Feriados e interrupções», na página de edição do ano letivo.
 *
 * O QUE ESTE FICHEIRO AFIRMA É O DESENHO NOVO. A secção vivia dentro do
 * formulário grande do ano: todas as linhas sempre abertas em campos, uma linha
 * nova a cair no fundo da lista, e um só «Guardar ano letivo» a gravar tudo —
 * que fazia a página prometer um «Guardar» por linha que não existia. Agora cada
 * exceção está em repouso como TEXTO, com «Editar» e «Eliminar»; «Editar» abre
 * aquela linha e só aquela; e «Guardar» é um pedido a sério ao seu próprio
 * endereço.
 */
describe('ExceptionsManager', () => {
    beforeEach(() => {
        routerDelete.mockClear();
        routerPost.mockClear();
        formPost.mockClear();
        formPut.mockClear();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    // ------------------------------------------------ o estado de repouso

    /**
     * UMA LISTA LÊ-SE, e não se preenche. Com quinze feriados, campos de texto
     * abertos em todas as linhas davam um muro de formulários por preencher —
     * exatamente o que a verificação em uso real apanhou.
     */
    it('shows a saved exception as plain text, with no inputs at all', () => {
        const wrapper = mountManager([NATAL]);

        const row = wrapper.get('[data-exception-row="exc-b"]');
        expect(row.text()).toContain('Interrupção letiva');
        expect(row.text()).toContain('Interrupção de Natal');
        expect(row.text()).toContain('21/12/2026 – 02/01/2027');

        expect(row.find('input').exists()).toBe(false);
        expect(row.find('select').exists()).toBe(false);
        // E os dois gestos, escritos e alcançáveis pelo nome.
        expect(row.find('[data-edit-exception="exc-b"]').exists()).toBe(true);
        expect(row.find('[data-delete-exception="exc-b"]').exists()).toBe(true);
    });

    /** Um feriado é de um dia, e um dia diz-se uma vez e não duas. */
    it('writes a single-day exception as one date', () => {
        const wrapper = mountManager();

        expect(wrapper.get('[data-exception-row="exc-a"]').text()).toContain(
            '05/10/2026',
        );
        expect(
            wrapper.get('[data-exception-row="exc-a"]').text(),
        ).not.toContain('05/10/2026 – ');
    });

    it('says so plainly when the year has none yet', () => {
        expect(mountManager([]).text()).toContain(
            'Ainda não há feriados nem interrupções neste ano letivo.',
        );
    });

    // -------------------------------------------------------- modo de edição

    it('opens only the row whose «Editar» was pressed', async () => {
        const wrapper = mountManager([exception(), NATAL]);

        await wrapper.get('[data-edit-exception="exc-a"]').trigger('click');

        const opened = wrapper.get('[data-exception-row="exc-a"]');
        expect(opened.find('input#exception-title').exists()).toBe(true);
        expect(
            (opened.get('input#exception-title').element as HTMLInputElement)
                .value,
        ).toBe('Implantação da República');

        // A OUTRA LINHA NÃO SE MEXEU: continua em texto, com os seus dois botões.
        const untouched = wrapper.get('[data-exception-row="exc-b"]');
        expect(untouched.find('input').exists()).toBe(false);
        expect(untouched.find('[data-edit-exception="exc-b"]').exists()).toBe(
            true,
        );
    });

    it('replaces «Editar»/«Eliminar» with «Cancelar»/«Guardar» on the open row', async () => {
        const wrapper = mountManager();

        await wrapper.get('[data-edit-exception="exc-a"]').trigger('click');

        const row = wrapper.get('[data-exception-row="exc-a"]');
        expect(row.find('[data-save-exception]').exists()).toBe(true);
        expect(row.find('[data-cancel-exception]').exists()).toBe(true);
        expect(row.find('[data-edit-exception="exc-a"]').exists()).toBe(false);
        expect(row.find('[data-delete-exception="exc-a"]').exists()).toBe(
            false,
        );
    });

    /**
     * «GUARDAR» É UM PUT A SÉRIO, ao endereço daquela exceção — e não uma
     * alteração local à espera de outro botão qualquer lá em baixo.
     */
    it('saves an edited row with a real PUT to its own endpoint', async () => {
        const wrapper = mountManager();

        await wrapper.get('[data-edit-exception="exc-a"]').trigger('click');
        await wrapper
            .get('input#exception-title')
            .setValue('Implantação da República (confirmado)');
        await wrapper.get('[data-save-exception]').trigger('submit');

        expect(formPut).toHaveBeenCalledWith(
            '/academic-years/year-a/exceptions/exc-a',
            expect.anything(),
        );
        expect(formPost).not.toHaveBeenCalled();
    });

    /**
     * CANCELAR NÃO PEDE NADA AO SERVIDOR, e o que fica no ecrã são os valores
     * GRAVADOS — não o que se andou a escrever. O que se escreveu era uma cópia
     * dentro do formulário e nunca a linha.
     */
    it('discards typed changes on «Cancelar», without any request', async () => {
        const wrapper = mountManager();

        await wrapper.get('[data-edit-exception="exc-a"]').trigger('click');
        await wrapper.get('input#exception-title').setValue('Rascunho perdido');
        await wrapper.get('[data-cancel-exception]').trigger('click');

        const row = wrapper.get('[data-exception-row="exc-a"]');
        expect(row.find('input').exists()).toBe(false);
        expect(row.text()).toContain('Implantação da República');
        expect(row.text()).not.toContain('Rascunho perdido');

        expect(formPut).not.toHaveBeenCalled();
        expect(formPost).not.toHaveBeenCalled();
        expect(routerDelete).not.toHaveBeenCalled();
    });

    // ------------------------------------------------------- a linha nova

    /**
     * EM CIMA, E NÃO NO FUNDO. Procurar a linha acabada de acrescentar no fim de
     * quinze feriados era o defeito exato que isto corrige.
     */
    it('puts the new row above every saved one', async () => {
        const wrapper = mountManager([exception(), NATAL]);

        await wrapper.get('[data-add-exception]').trigger('click');

        const order = wrapper
            .findAll('[data-exception-row]')
            .map((row) => row.attributes('data-exception-row'));

        expect(order).toEqual(['new', 'exc-a', 'exc-b']);
    });

    it('starts the new row in inputs, empty, with no «Editar» to press', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');

        const row = wrapper.get('[data-exception-row="new"]');
        expect(
            (row.get('input#exception-title').element as HTMLInputElement)
                .value,
        ).toBe('');
        expect(row.find('[data-save-exception]').exists()).toBe(true);
        expect(row.find('[data-cancel-exception]').exists()).toBe(true);
    });

    /** O foco vai para a designação, que é o campo por onde se começa. */
    it('focuses the designação of the new row', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await nextTick();
        await nextTick();

        expect(document.activeElement).toBe(field('exception-title'));
    });

    it('creates with a real POST to the year’s own exceptions endpoint', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await wrapper.get('input#exception-title').setValue('Carnaval');
        await wrapper.get('[data-save-exception]').trigger('submit');

        expect(formPost).toHaveBeenCalledWith(
            '/academic-years/year-a/exceptions',
            expect.anything(),
        );
        expect(formPut).not.toHaveBeenCalled();
    });

    it('removes the new row on «Cancelar», creating nothing', async () => {
        const wrapper = mountManager([exception()]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await wrapper.get('input#exception-title').setValue('Nunca gravado');
        await wrapper.get('[data-cancel-exception]').trigger('click');

        expect(wrapper.find('[data-exception-row="new"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-exception-row]')).toHaveLength(1);
        expect(formPost).not.toHaveBeenCalled();
    });

    // ------------------------------------------------------------ as datas

    /**
     * A DATA DE FIM COMEÇA IGUAL À DE INÍCIO. Um feriado é de um dia, e um dia é
     * «de 5 a 5» — obrigar a escrever a mesma data duas vezes era fazer o caso
     * mais comum custar dois campos.
     */
    it('fills an empty end date from the start date', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await wrapper.get('input#exception-starts-on').setValue('2026-10-05');

        expect(
            (wrapper.get('input#exception-ends-on').element as HTMLInputElement)
                .value,
        ).toBe('2026-10-05');
    });

    /**
     * E SEGUE-A ENQUANTO AS DUAS FOREM A MESMA. Corrigir a data de início de um
     * feriado de um dia — de 26/05 para 02/06 — deixava «de 02/06 a 26/05»: um
     * intervalo ao contrário que o servidor recusa e que ninguém escreveu de
     * propósito.
     */
    it('follows the start date while the two are still the same day', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await wrapper.get('input#exception-starts-on').setValue('2027-05-26');
        await wrapper.get('input#exception-starts-on').setValue('2027-06-02');

        expect(
            (wrapper.get('input#exception-ends-on').element as HTMLInputElement)
                .value,
        ).toBe('2027-06-02');
    });

    /**
     * E NÃO ARRASTA UM INTERVALO ESCOLHIDO. «21/12 a 02/01» é uma decisão, e
     * mexer na data de início não lha pode encolher em silêncio — a diferença
     * entre as duas metades desta regra é o ponto todo dela.
     */
    it('leaves a deliberately multi-day range alone when the start date moves', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');
        await wrapper.get('input#exception-starts-on').setValue('2026-12-21');
        // O professor abriu o intervalo à mão: a partir daqui já não há nada a
        // seguir.
        await wrapper.get('input#exception-ends-on').setValue('2027-01-02');
        await wrapper.get('input#exception-starts-on').setValue('2026-12-19');

        expect(
            (wrapper.get('input#exception-ends-on').element as HTMLInputElement)
                .value,
        ).toBe('2027-01-02');
    });

    /**
     * Os limites do ano nos próprios campos — uma gentileza, e não a guarda: o
     * servidor volta a verificar o mesmo, porque um `min` de HTML é uma palavra
     * que o cliente diz a si próprio.
     */
    it('bounds both date fields by the academic year', async () => {
        const wrapper = mountManager([]);

        await wrapper.get('[data-add-exception]').trigger('click');

        for (const id of ['exception-starts-on', 'exception-ends-on']) {
            const input = wrapper.get(`input#${id}`);
            expect(input.attributes('min')).toBe('2026-09-01');
            expect(input.attributes('max')).toBe('2027-07-31');
        }
    });

    // ---------------------------------------------------------- eliminar

    /**
     * A CONFIRMAÇÃO É DA APLICAÇÃO, e não do navegador — o mesmo desenho das
     * outras confirmações destrutivas daqui (ver Month.vue). Enquanto ela está
     * no ar não parte pedido nenhum.
     */
    it('asks before deleting, and sends nothing while it asks', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm');
        const wrapper = mountManager([NATAL]);

        await wrapper.get('[data-delete-exception="exc-b"]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Eliminar do calendário?');
        expect(document.body.textContent).toContain('«Interrupção de Natal»');
        expect(routerDelete).not.toHaveBeenCalled();
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('deletes nothing when the confirmation is refused', async () => {
        const wrapper = mountManager([NATAL]);

        await wrapper.get('[data-delete-exception="exc-b"]').trigger('click');
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-cancel-delete]')
            ?.click();
        await nextTick();

        expect(routerDelete).not.toHaveBeenCalled();
        // E a linha continua exatamente onde estava.
        expect(wrapper.get('[data-exception-row="exc-b"]').text()).toContain(
            'Interrupção de Natal',
        );
    });

    it('deletes only once the confirmation is given', async () => {
        const wrapper = mountManager([NATAL]);

        await wrapper.get('[data-delete-exception="exc-b"]').trigger('click');
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-confirm-delete]')
            ?.click();
        await nextTick();

        expect(routerDelete).toHaveBeenCalledWith(
            '/academic-years/year-a/exceptions/exc-b',
            expect.anything(),
        );
    });
});

// ══════════════════════════════════════ sugerir feriados nacionais (§17)

type SuggestionState = 'new' | 'exists' | 'correspondence' | 'conflict';

type Suggestion = {
    date: string;
    title: string;
    state: SuggestionState;
    current: {
        ulid: string;
        type_label: string;
        title: string;
        starts_on: string;
        ends_on: string;
        source_label: string;
    } | null;
};

function suggestion(overrides: Partial<Suggestion> = {}): Suggestion {
    return {
        date: '2027-05-01',
        title: 'Dia do Trabalhador',
        state: 'new',
        current: null,
        ...overrides,
    };
}

function stubSuggestions(payload: {
    supported?: boolean;
    message?: string | null;
    suggestions?: Suggestion[];
}): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                json: () =>
                    Promise.resolve({
                        country_code: 'PT',
                        supported: payload.supported ?? true,
                        message: payload.message ?? null,
                        suggestions: payload.suggestions ?? [],
                    }),
            }),
        ),
    );
}

/** Abrir o diálogo é uma leitura assíncrona: dois turnos e a lista está lá. */
async function openSuggestions(
    wrapper: ReturnType<typeof mountManager>,
): Promise<void> {
    await wrapper.get('[data-suggest-holidays]').trigger('click');
    await nextTick();
    await nextTick();
    await nextTick();
}

function row(date: string): HTMLElement | null {
    return document.body.querySelector<HTMLElement>(
        `[data-suggestion="${date}"]`,
    );
}

function checkbox(date: string): HTMLInputElement | null {
    return document.body.querySelector<HTMLInputElement>(
        `[data-suggestion-checkbox="${date}"]`,
    );
}

/**
 * «Sugerir feriados nacionais» — ver, escolher, e só depois gravar.
 *
 * O QUE ESTE BLOCO AFIRMA É SOBRETUDO O QUE NÃO ACONTECE: que abrir a lista não
 * grava nada, que o que já lá está não se pode voltar a marcar, que um conflito
 * nunca se resolve daqui, e que fechar o diálogo não pede nada ao servidor.
 */
describe('ExceptionsManager · feriados nacionais', () => {
    beforeEach(() => {
        routerDelete.mockClear();
        routerPost.mockClear();
        formPost.mockClear();
        formPut.mockClear();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('offers the action next to «Adicionar», and writes nothing until asked', async () => {
        stubSuggestions({ suggestions: [suggestion()] });
        const wrapper = mountManager([]);

        expect(wrapper.find('[data-suggest-holidays]').exists()).toBe(true);

        await openSuggestions(wrapper);

        // ABRIR É LER. Nada foi gravado, e nem sequer pedido para o ser.
        expect(routerPost).not.toHaveBeenCalled();
        expect(formPost).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Feriados nacionais');
        expect(document.body.textContent).toContain('Dia do Trabalhador');
    });

    /**
     * OS NOVOS VÊM MARCADOS e mais nenhum: não há nada para destruir num feriado
     * que ainda não existe, e picar treze caixas para dizer «sim» treze vezes era
     * o custo da alternativa.
     */
    it('pre-ticks only the new rows', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({
                    date: '2026-12-25',
                    title: 'Natal',
                    state: 'new',
                }),
                suggestion({
                    date: '2027-05-01',
                    state: 'exists',
                    current: {
                        ulid: 'exc-c',
                        type_label: 'Feriado',
                        title: 'Dia do Trabalhador',
                        starts_on: '2027-05-01',
                        ends_on: '2027-05-01',
                        source_label: 'Escrita pelo professor',
                    },
                }),
            ],
        });

        await openSuggestions(mountManager([]));

        expect(checkbox('2026-12-25')?.checked).toBe(true);
        expect(checkbox('2027-05-01')?.checked).toBe(false);
    });

    /** O que já lá está igualzinho não tem nada para fazer, e não se pode marcar. */
    it('disables the checkbox of a row that is already there', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({
                    state: 'exists',
                    current: {
                        ulid: 'exc-c',
                        type_label: 'Feriado',
                        title: 'Dia do Trabalhador',
                        starts_on: '2027-05-01',
                        ends_on: '2027-05-01',
                        source_label: 'Importada',
                    },
                }),
            ],
        });

        await openSuggestions(mountManager([]));

        expect(checkbox('2027-05-01')?.disabled).toBe(true);
        expect(row('2027-05-01')?.textContent).toContain('Já existente');
        // E o que já lá está diz-se por inteiro, com a proveniência incluída.
        expect(row('2027-05-01')?.textContent).toContain('Importada');
    });

    /**
     * «DESIGNAÇÃO DIFERENTE» MOSTRA AS DUAS, e diz o que a caixa faz. Marcar não
     * cria uma segunda linha: muda o nome da que já existe, e a frase ao lado da
     * caixa não deixa isso por adivinhar.
     */
    it('shows both titles for a correspondence, unticked, and says what ticking does', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({
                    state: 'correspondence',
                    current: {
                        ulid: 'exc-c',
                        type_label: 'Feriado',
                        title: '1.º de Maio',
                        starts_on: '2027-05-01',
                        ends_on: '2027-05-01',
                        source_label: 'Escrita pelo professor',
                    },
                }),
            ],
        });

        await openSuggestions(mountManager([]));

        const text = row('2027-05-01')?.textContent ?? '';

        expect(text).toContain('Designação diferente');
        expect(text).toContain('1.º de Maio');
        expect(text).toContain('Dia do Trabalhador');
        expect(text).toContain('nenhuma linha nova é criada');

        // Selecionável — é uma decisão a tomar — mas NUNCA pré-selecionada:
        // mudar o nome de uma linha escrita à mão é uma decisão do professor.
        expect(checkbox('2027-05-01')?.disabled).toBe(false);
        expect(checkbox('2027-05-01')?.checked).toBe(false);
    });

    /**
     * UM CONFLITO NUNCA SE RESOLVE POR APROXIMAÇÃO (§32) — e por isso a caixa não
     * se pode marcar: o servidor saltá-lo-ia na mesma, e uma caixa cuja única
     * consequência é «não aconteceu nada» é uma caixa que mente.
     */
    it('shows a conflict with both versions and offers no tick for it', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({
                    date: '2026-12-25',
                    title: 'Natal',
                    state: 'conflict',
                    current: {
                        ulid: 'exc-d',
                        type_label: 'Feriado',
                        title: 'Natal alargado',
                        starts_on: '2026-12-24',
                        ends_on: '2026-12-26',
                        source_label: 'Importada',
                    },
                }),
            ],
        });

        await openSuggestions(mountManager([]));

        expect(checkbox('2026-12-25')?.disabled).toBe(true);
        expect(checkbox('2026-12-25')?.checked).toBe(false);
        expect(row('2026-12-25')?.textContent).toContain('Conflito');
        expect(row('2026-12-25')?.textContent).toContain('Natal alargado');
        expect(row('2026-12-25')?.textContent).toContain(
            '24/12/2026 – 26/12/2026',
        );
    });

    /**
     * O PAÍS QUE ESTA VERSÃO NÃO CONHECE DIZ-SE POR PALAVRAS, e nunca se substitui
     * pelo calendário de outro país.
     */
    it('shows the honest refusal for a country with no provider', async () => {
        stubSuggestions({
            supported: false,
            message: 'Não há feriados nacionais disponíveis para ES.',
            suggestions: [],
        });

        await openSuggestions(mountManager([]));

        expect(
            document.body.querySelector('[data-suggestions-unsupported]')
                ?.textContent,
        ).toContain('Não há feriados nacionais disponíveis para ES.');
        expect(
            document.body.querySelector('[data-suggestion="2027-05-01"]'),
        ).toBeNull();
    });

    /** Só as marcadas, e só as datas: o título com que se grava é o do servidor. */
    it('submits only the ticked dates, to the year’s own endpoint', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({ date: '2026-12-25', title: 'Natal' }),
                suggestion({ date: '2027-05-01' }),
                suggestion({
                    date: '2027-06-10',
                    title: 'Dia de Portugal',
                    state: 'exists',
                    current: {
                        ulid: 'exc-e',
                        type_label: 'Feriado',
                        title: 'Dia de Portugal',
                        starts_on: '2027-06-10',
                        ends_on: '2027-06-10',
                        source_label: 'Sugerida',
                    },
                }),
            ],
        });

        const wrapper = mountManager([]);
        await openSuggestions(wrapper);

        // O professor desmarca um dos dois novos.
        checkbox('2026-12-25')?.click();
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-confirm-suggestions]')
            ?.click();
        await nextTick();

        expect(routerPost).toHaveBeenCalledWith(
            '/academic-years/year-a/holiday-suggestions',
            { dates: ['2027-05-01'] },
            expect.anything(),
        );
    });

    /** Fechar não pede nada ao servidor, por via nenhuma. */
    it('sends nothing on «Cancelar»', async () => {
        stubSuggestions({ suggestions: [suggestion()] });

        const wrapper = mountManager([]);
        await openSuggestions(wrapper);

        document.body
            .querySelector<HTMLButtonElement>('[data-cancel-suggestions]')
            ?.click();
        await nextTick();

        expect(routerPost).not.toHaveBeenCalled();
        expect(formPost).not.toHaveBeenCalled();
        expect(routerDelete).not.toHaveBeenCalled();
    });

    /** Nada marcado é nada para fazer, e o botão di-lo antes de o pedido partir. */
    it('cannot be confirmed with nothing ticked', async () => {
        stubSuggestions({
            suggestions: [
                suggestion({
                    state: 'exists',
                    current: {
                        ulid: 'exc-c',
                        type_label: 'Feriado',
                        title: 'Dia do Trabalhador',
                        starts_on: '2027-05-01',
                        ends_on: '2027-05-01',
                        source_label: 'Sugerida',
                    },
                }),
            ],
        });

        await openSuggestions(mountManager([]));

        const confirm = document.body.querySelector<HTMLButtonElement>(
            '[data-confirm-suggestions]',
        );

        expect(confirm?.disabled).toBe(true);

        confirm?.click();
        await nextTick();

        expect(routerPost).not.toHaveBeenCalled();
    });

    it('explains a failed read instead of showing an empty list', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({ ok: false, json: () => Promise.resolve({}) }),
            ),
        );

        await openSuggestions(mountManager([]));

        expect(
            document.body.querySelector('[data-suggestions-error]')
                ?.textContent,
        ).toContain('Não foi possível obter os feriados nacionais.');
    });
});
