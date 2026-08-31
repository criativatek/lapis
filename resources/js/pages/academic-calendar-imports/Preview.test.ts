import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type * as VueModule from 'vue';
import Preview from './Preview.vue';

const formPost = vi.fn();

/**
 * Os formulários que a página criou, por ordem. É assim — e não a espreitar o
 * `setupState` da instância — que se chega ao `transform()`: o que interessa
 * afirmar é o payload que sairia daqui para o servidor, e ele é produzido pelo
 * transformador que a página registou neste objeto.
 */
const created = vi.hoisted(() => ({
    forms: [] as Record<string, unknown>[],
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof VueModule>('vue');

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        // O suficiente de useForm para esta página: os campos, o saco de erros
        // que os inputs leem, o transformador e o verbo com que ela submete.
        useForm: (initial: Record<string, unknown>) => {
            const form = reactive({
                ...initial,
                errors: {} as Record<string, string>,
                processing: false,
                transform(callback: (data: unknown) => unknown) {
                    form.transformer = callback;

                    return form;
                },
                transformer: null as ((data: unknown) => unknown) | null,
                post: (...args: unknown[]) => formPost(...args),
            });

            created.forms.push(form);

            return form;
        },
    };
});

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * O ECRÃ QUE DIZIA «FERIADOS» A TUDO O QUE O DOCUMENTO MARCASSE.
 *
 * Um calendário escolar traz feriados, mas traz também reuniões, apresentações,
 * atividades e convívios — e todos apareciam debaixo do título «Feriados». Nesta
 * aplicação isso não é um rótulo infeliz: «feriado» é uma
 * `AcademicCalendarException`, e uma exceção é a afirmação de que naquele dia NÃO
 * HÁ AULA.
 *
 * Estes testes seguram as duas metades da correção que se veem daqui: o título e
 * o texto de apoio dizem o que a lista é, e nenhuma linha imprime «Feriado» sem
 * que o servidor o tenha dito daquela linha.
 */
type Row = {
    key: string;
    destination: 'academic_calendar_exception' | 'calendar_event';
    type: string;
    type_label: string;
    type_short_label?: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
    raw_text: string;
    explanation?: string;
    state:
        | 'new'
        | 'exists'
        | 'correspondence'
        | 'changed'
        | 'conflict'
        | 'needs_choice'
        | 'out_of_year';
    current: null;
    include: boolean;
};

const academicYear = {
    ulid: '01JQ0000000000000000000000',
    label: '2030/2031',
    starts_on: '2030-09-01',
    ends_on: '2031-07-31',
};

function datedItem(overrides: Partial<Row> = {}): Row {
    return {
        key: 'exception-0',
        destination: 'academic_calendar_exception',
        type: 'holiday',
        type_label: 'Feriado',
        type_short_label: 'FERIADO',
        title: 'Natal',
        starts_on: '2030-12-25',
        ends_on: '2030-12-25',
        note: null,
        raw_text: '25 Natal',
        state: 'new',
        current: null,
        include: true,
        ...overrides,
    };
}

const meeting = datedItem({
    key: 'event-0',
    destination: 'calendar_event',
    type: 'meeting',
    type_label: 'Reunião',
    type_short_label: undefined,
    title: 'Reunião de avaliação',
    starts_on: '2030-10-16',
    ends_on: '2030-10-16',
    raw_text: '16 Reunião de avaliação',
    include: false,
    explanation:
        'O documento marca esta data sem lhe chamar feriado nem interrupção.',
});

function render(datedItems: Row[]) {
    created.forms.length = 0;

    return mount(Preview, {
        props: {
            semesters: [],
            schoolBreaks: [],
            datedItems,
            counts: {
                new: datedItems.length,
                exists: 0,
                correspondence: 0,
                changed: 0,
                conflict: 0,
                needs_choice: 0,
                out_of_year: 0,
            },
            academicYear,
            schoolName: 'Agrupamento de Escolas de Exemplo',
            fileAcademicYear: '2030/2031',
            yearMismatch: false,
        },
        global: {
            stubs: { Heading: true },
        },
    });
}

describe('a pré-visualização da importação do calendário', () => {
    it('chama à secção «Datas e eventos escolares» e nunca «Feriados»', () => {
        const text = render([datedItem()]).text();

        expect(text).toContain('Datas e eventos escolares');

        // O TÍTULO ANTIGO NÃO PODE ESTAR EM LADO NENHUM. «Feriados», no plural e
        // como cabeçalho, era a afirmação sobre o conjunto inteiro que esta
        // correção existe para desfazer.
        expect(text).not.toContain('Feriados');
    });

    it('explica que nem tudo o que vem no documento é um feriado', () => {
        const text = render([datedItem()]).text();

        expect(text).toContain(
            'Selecione as datas e eventos que pretende importar.',
        );
        expect(text).toContain('reuniões, atividades, apresentações');
    });

    it('imprime em cada linha a espécie que o servidor lhe deu', () => {
        const rows = render([datedItem(), meeting]).findAll('li');

        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Feriado');
        expect(rows[1].text()).toContain('Reunião');

        // E A REUNIÃO NÃO É UM FERIADO EM SÍTIO NENHUM DA SUA LINHA — que é a
        // regressão exata que isto guarda.
        expect(rows[1].text()).not.toContain('Feriado');
    });

    it('nunca inventa «Feriado» para uma data que o servidor não classificou', () => {
        const text = render([
            datedItem({
                key: 'event-0',
                destination: 'calendar_event',
                type: 'other',
                type_label: 'Data relevante',
                type_short_label: undefined,
                title: 'Apresentação dos alunos',
                starts_on: '2030-09-17',
                ends_on: '2030-09-17',
                raw_text: '17 Apresentação dos alunos',
                include: false,
                explanation:
                    'O documento marca esta data sem lhe chamar feriado nem interrupção.',
            }),
        ]).text();

        expect(text).toContain('Data relevante');
        expect(text).not.toContain('Feriado');
    });

    it('separa por destino o que envia ao servidor, mostrando-o numa lista só', () => {
        render([datedItem(), { ...meeting, include: true }]);

        const form = created.forms.at(-1) as {
            transformer: (data: unknown) => {
                exceptions: { title: string }[];
                events: { title: string }[];
            };
        };

        const payload = form.transformer(form);

        expect(payload.exceptions.map((row) => row.title)).toEqual(['Natal']);
        expect(payload.events.map((row) => row.title)).toEqual([
            'Reunião de avaliação',
        ]);
    });
});
