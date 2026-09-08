import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { Synopsis } from '@/lib/synopsis';
import Summary from './Summary.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: vi.fn() },
}));

/**
 * O QUADRO SÍNTESE, VISTA «POR DOMÍNIO» — a grelha lida por blocos.
 *
 * A REGRA DE PRODUTO QUE ESTES TESTES DEFENDEM: um professor tem de conseguir
 * responder a «como é que este aluno terminou em Oralidade?» sem inferir que a
 * coluna «Final», encostada ao desempenho acumulado dentro do bloco de um
 * domínio, era a média contínua final. A conclusão do ano vive num bloco
 * próprio, domínio a domínio, e o global fecha-o uma vez só.
 *
 * O CENÁRIO É O REAL. Álvaro Simões, Oralidade: 77,3 % no 1.º semestre, 47,5 %
 * no 2.º, e 62,4 % de média final — a média dos dois, sem pesos declarados. Os
 * números não são calculados no browser: chegam no payload, e é isso que estes
 * testes verificam que a grelha mostra sem lhes tocar.
 */

const LEVELS = [
    { code: '1', label: 'Muito Insuficiente', sequence: 1, is_negative: true },
    { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true },
    { code: '3', label: 'Suficiente', sequence: 3, is_negative: false },
    { code: '4', label: 'Bom', sequence: 4, is_negative: false },
    { code: '5', label: 'Muito Bom', sequence: 5, is_negative: false },
];

const SUFICIENTE = { code: '3', label: 'Suficiente', sequence: 3, is_negative: false };
const BOM = { code: '4', label: 'Bom', sequence: 4, is_negative: false };

const DOMAINS = [
    { id: 1, ulid: 'dom-oralidade', name: 'Oralidade' },
    { id: 2, ulid: 'dom-leitura', name: 'Leitura' },
];

const PERIODS = [
    { id: 1, ulid: 'per-1', label: '1.º Semestre', sequence: 1 },
    { id: 2, ulid: 'per-2', label: '2.º Semestre', sequence: 2 },
];

function continuousUnit(periodId: number, label: string, value: string | null) {
    return {
        period_id: periodId,
        label,
        kind_label: 'Semestre',
        weight_percent: '50.0000',
        normalized_value: value,
        counted: value !== null,
        level: null,
    };
}

/** A leitura contínua final de um domínio, tal como o servidor a envia. */
function continuousReading(
    first: string | null,
    second: string | null,
    final: string | null,
    level: typeof SUFICIENTE | null,
    decided: (typeof SUFICIENTE & { scale_level_id: number }) | null = null,
) {
    return {
        units: [continuousUnit(1, '1.º Semestre', first), continuousUnit(2, '2.º Semestre', second)],
        counted_units: [first, second].filter((value) => value !== null).length,
        normalized_value: final,
        proposal: { value: level?.code ?? null, state: 'ok', is_percentage: false },
        level,
        decision: decided === null ? null : { final: decided, final_value: null },
    };
}

function synopsis(decidedOralidade = false): Synopsis {
    return {
        moments: [],
        domains: [
            { domain_id: 1, name: 'Oralidade', sequence: 0, color: '#DCEAFB', weight_percent: '50.0000' },
            { domain_id: 2, name: 'Leitura', sequence: 1, color: '#E1F0E1', weight_percent: '50.0000' },
        ],
        students: [
            {
                enrollment_id: 10,
                enrollment_ulid: 'enr-alvaro',
                class_number: 3,
                name: 'Álvaro Simões',
                moments: [],
                continuous: continuousReading('80.000000', '70.000000', '75.000000', BOM),
                continuous_domains: {
                    // O CENÁRIO DE QA, fixado aqui: 77,3 e 47,5 dão 62,4.
                    1: continuousReading(
                        '77.300000',
                        '47.500000',
                        '62.400000',
                        SUFICIENTE,
                        decidedOralidade ? { ...BOM, scale_level_id: 4 } : null,
                    ),
                    2: continuousReading('82.000000', '92.000000', '87.000000', BOM),
                },
                elements: [],
            },
        ],
        continuous: {
            units: [
                { period_id: 1, label: '1.º Semestre', kind_label: 'Semestre', weight_percent: '50.0000' },
                { period_id: 2, label: '2.º Semestre', kind_label: 'Semestre', weight_percent: '50.0000' },
            ],
            weights_declared: false,
        },
        elements: [],
        periods: [
            { id: 1, ulid: 'per-1', label: '1.º Semestre', kind_label: 'Semestre', sequence: 1 },
            { id: 2, ulid: 'per-2', label: '2.º Semestre', kind_label: 'Semestre', sequence: 2 },
        ],
    };
}

function periodCell(periodId: number, label: string, weighted: string, accumulated: string) {
    return {
        period_id: periodId,
        period_label: label,
        weighted_average: weighted,
        accumulated_average: accumulated,
        coverage_warning: false,
        evolution: null,
        domains: DOMAINS.map((domain) => ({
            domain_id: domain.id,
            weighted_average: weighted,
            accumulated_average: accumulated,
            coverage_warning: false,
            evolution: null,
            self_assessment: null,
            mention: { scale_level_id: 3, ...SUFICIENTE },
        })),
        self_assessment: null,
        classification: null,
    };
}

function props(options: { decided?: boolean; canDecide?: boolean } = {}) {
    return {
        schoolClass: {
            ulid: 'class-1',
            label: '7.º A',
            subject: 'Português',
            academic_year: '2025/2026',
            has_profile: true,
            scale_name: 'Escala 1 a 5',
        },
        decision: {
            label: 'Nível atribuído',
            classifies_by_level: true,
            levels: LEVELS.map((level, index) => ({ id: index + 1, code: level.code, label: level.label })),
            min_value: null,
            max_value: null,
        },
        scaleBands: LEVELS,
        canExportToInovar: false,
        canViewStudentProgress: true,
        canDecideDomains: options.canDecide ?? true,
        progression: {
            periods: PERIODS,
            domains: DOMAINS,
            students: [
                {
                    enrollment_id: 10,
                    enrollment_ulid: 'enr-alvaro',
                    name: 'Álvaro Simões',
                    class_number: 3,
                    periods: [
                        periodCell(1, '1.º Semestre', '77.300000', '75.000000'),
                        periodCell(2, '2.º Semestre', '47.500000', '60.000000'),
                    ],
                },
            ],
        },
        synopsis: synopsis(options.decided ?? false),
    };
}

const stubs = {
    ClassSynopsisTable: true,
    AccumulatedBreakdownPanel: true,
    FinalDomainDecisionDialog: true,
    EmptyState: true,
    Heading: true,
};

/** A vista «Por domínio», que é a que estes testes olham. */
async function domainsView(options: { decided?: boolean; canDecide?: boolean } = {}): Promise<VueWrapper> {
    const wrapper = mount(Summary, { props: props(options), global: { stubs } });

    await wrapper.findAll('button[role="tab"]')[1].trigger('click');

    return wrapper;
}

/** As células de dados da linha do aluno, por ordem. */
function bodyCells(wrapper: VueWrapper): string[] {
    return wrapper.findAll('tbody td').map((cell) => cell.text().trim());
}

async function withoutQuantitative(wrapper: VueWrapper): Promise<VueWrapper> {
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(false);

    return wrapper;
}

/**
 * O SEGUNDO CONTROLO: a leitura acumulada sai da grelha, e mais nada muda.
 *
 * Encontrado pelo RÓTULO e não pela posição: dois controlos lado a lado trocam
 * de ordem à primeira mudança de layout, e um índice trocado faria este teste
 * afirmar coisas sobre o controlo errado sem nunca falhar.
 */
async function withoutAccumulated(wrapper: VueWrapper): Promise<VueWrapper> {
    const label = wrapper.findAll('label').find((candidate) => candidate.text().includes('desempenho acumulado'));

    if (label === undefined) {
        throw new Error('O controlo «Mostrar desempenho acumulado» não está no ecrã.');
    }

    await label.find('input[type="checkbox"]').setValue(false);

    return wrapper;
}

/**
 * A MESMA TURMA COM TRÊS PERÍODOS — porque nada nesta grelha pode saber quantas
 * unidades um ano letivo tem (§2). Uma largura escrita à mão desalinharia todas
 * as colunas à direita dela, e num quadro deste tamanho isso lê-se como o
 * resultado de outro aluno.
 */
async function threePeriodsView(): Promise<VueWrapper> {
    const base = props();
    const periods = [
        { id: 1, ulid: 'per-1', label: '1.º Período', sequence: 1 },
        { id: 2, ulid: 'per-2', label: '2.º Período', sequence: 2 },
        { id: 3, ulid: 'per-3', label: '3.º Período', sequence: 3 },
    ];

    const wrapper = mount(Summary, {
        props: {
            ...base,
            progression: {
                ...base.progression,
                periods,
                students: [
                    {
                        ...base.progression.students[0],
                        periods: periods.map((period) =>
                            periodCell(period.id, period.label, '70.000000', '68.000000'),
                        ),
                    },
                ],
            },
        },
        global: { stubs },
    });

    await wrapper.findAll('button[role="tab"]')[1].trigger('click');

    return wrapper;
}

describe('Quadro Síntese · por domínio — os quatro blocos', () => {
    it('lê-se por blocos: os domínios, cada síntese, e a avaliação contínua final', async () => {
        const headings = (await domainsView()).findAll('thead tr:first-child th').map((cell) => cell.text().trim());

        expect(headings).toEqual([
            'Aluno',
            'Resultados por domínio',
            'Síntese · 1.º Semestre',
            'Síntese · 2.º Semestre',
            'Avaliação Contínua Final',
        ]);
    });

    it('o bloco final repete cada domínio e fecha com o global, uma vez só', async () => {
        const groups = (await domainsView()).findAll('thead tr:nth-child(2) th').map((cell) => cell.text().trim());

        // Os domínios à esquerda, os mesmos domínios no bloco final, e o global.
        expect(groups).toEqual(['Oralidade', 'Leitura', 'Oralidade', 'Leitura', 'Global']);
        expect(groups.filter((label) => label === 'Global')).toHaveLength(1);
    });

    it('nenhuma coluna se chama «Final» nem «Aprec.»', async () => {
        const columns = (await domainsView()).findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        expect(columns).not.toContain('Final');
        expect(columns).not.toContain('Aprec.');
        expect(columns.filter((label) => label === 'Média final')).toHaveLength(3);
        expect(columns.filter((label) => label === 'Menção final')).toHaveLength(2);
    });
});

describe('Quadro Síntese · por domínio — a conclusão do ano', () => {
    it('mostra a média final e a menção de cada domínio, e nunca uma segunda conta', async () => {
        const cells = bodyCells(await domainsView());

        // Álvaro Simões, Oralidade: 77,3 % e 47,5 % nas colunas do domínio…
        expect(cells).toContain('77,3%');
        expect(cells).toContain('47,5%');

        // …e 62,4 % no bloco final, que é a resposta que faltava.
        expect(cells).toContain('62,4%');
        expect(cells).toContain('87,0%');
    });

    it('a média final vem do payload e não muda quando o professor decide', async () => {
        const decided = bodyCells(await domainsView({ decided: true }));

        expect(decided).toContain('62,4%');
    });

    it('sem decisão, a proposta é a que vigora — e não uma pendência', async () => {
        const wrapper = await domainsView();

        // A LEGENDA EXPLICA A MARCA, e por isso a palavra existe sempre na
        // página: o que não pode existir é na LINHA do aluno.
        expect(wrapper.find('tbody').text()).not.toContain('prof.');

        const oralidade = wrapper.find('[aria-label*="apreciação final de Oralidade"]');
        expect(oralidade.exists()).toBe(true);
        expect(oralidade.attributes('title')).toContain('Proposta do Lapispro');
        expect(oralidade.attributes('title')).toContain('vigente');
    });

    it('com decisão, a do professor prevalece e diz-se «prof.»', async () => {
        const wrapper = await domainsView({ decided: true });

        expect(wrapper.find('tbody').text()).toContain('prof.');

        const oralidade = wrapper.find('[aria-label*="apreciação final de Oralidade"]');
        expect(oralidade.attributes('title')).toContain('Decisão do professor: 4 — Bom');
        expect(oralidade.attributes('title')).toContain('Proposta do Lapispro: 3 — Suficiente');
    });

    it('sem autorização a menção lê-se na mesma, mas não é um botão', async () => {
        const wrapper = await domainsView({ canDecide: false });
        const oralidade = wrapper.find('[title*="Oralidade · Avaliação Contínua Final"]');

        expect(oralidade.exists()).toBe(true);
        expect(oralidade.element.tagName).toBe('SPAN');
    });
});

describe('Quadro Síntese · por domínio — os quantitativos', () => {
    it('desligados, as médias saem da grelha e ficam as palavras da escala', async () => {
        const wrapper = await withoutQuantitative(await domainsView());
        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        expect(columns).not.toContain('Média final');
        expect(columns.filter((label) => label === 'Menção final')).toHaveLength(2);

        // A MENÇÃO É A PALAVRA, e não o código: um «3» onde devia estar
        // «Suficiente» não é uma pauta sem números.
        const cells = bodyCells(wrapper);
        expect(cells).not.toContain('62,4%');
        expect(wrapper.find('tbody').text()).toContain('Suficiente');
        expect(wrapper.find('tbody').text()).toContain('Bom');
    });

    it('ligados, a menção usa o código e a média está lá', async () => {
        const wrapper = await domainsView();

        expect(bodyCells(wrapper)).toContain('62,4%');
        expect(wrapper.find('[aria-label*="apreciação final de Oralidade"]').text()).toBe('3');
    });
});

describe('Quadro Síntese · por domínio — a hierarquia do traço', () => {
    it('a fronteira entre grandes blocos é a mais forte, e nenhuma é azul', async () => {
        const wrapper = await domainsView();
        const html = wrapper.html();

        expect(html).toContain('border-l-4 border-l-foreground/25');
        expect(html).not.toContain('border-l-primary');
    });

    it('cada domínio abre com a régua de grupo, mais discreta do que a do bloco', async () => {
        const wrapper = await domainsView();

        expect(wrapper.html()).toContain('border-l-2 border-l-border');
    });
});

describe('Quadro Síntese · por domínio — três períodos', () => {
    it('acrescenta uma unidade a cada domínio sem mexer no bloco final', async () => {
        const wrapper = await threePeriodsView();
        const blocks = wrapper.findAll('thead tr:first-child th').map((cell) => cell.text().trim());

        expect(blocks).toEqual([
            'Aluno',
            'Resultados por domínio',
            'Síntese · 1.º Período',
            'Síntese · 2.º Período',
            'Síntese · 3.º Período',
            'Avaliação Contínua Final',
        ]);

        // Por domínio: 3 unidades + 2 evoluções + acumulado + menção = 7.
        // No bloco final continuam a ser 2 por domínio, porque a conclusão do
        // ano é uma só por muitas unidades que ele tenha.
        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        expect(columns.filter((label) => label === '1.º Período')).toHaveLength(2);
        expect(columns.filter((label) => label === 'Média final')).toHaveLength(3);
        expect(columns.filter((label) => label === 'Menção final')).toHaveLength(2);
    });

    it('as larguras declaradas batem certo com as células que a linha tem', async () => {
        const wrapper = await threePeriodsView();

        const declared = wrapper
            .findAll('thead tr:first-child th')
            .slice(1)
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);

        // UMA CÉLULA A MAIS OU A MENOS DESALINHA A TABELA INTEIRA, e num quadro
        // desta largura isso lê-se como o resultado de outro aluno.
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declared);
    });
});

describe('Quadro Síntese · por domínio — a grelha fecha certa', () => {
    /** A soma dos `colspan` da linha dos grandes blocos, sem a coluna do aluno. */
    function declaredWidth(wrapper: VueWrapper): number {
        return wrapper
            .findAll('thead tr:first-child th')
            .slice(1)
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);
    }

    it('com os quantitativos ligados, o cabeçalho e a linha contam o mesmo', async () => {
        const wrapper = await domainsView();

        expect(wrapper.findAll('thead tr:nth-child(3) th')).toHaveLength(declaredWidth(wrapper));
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declaredWidth(wrapper));
    });

    it('e desligados também — a largura acompanha as colunas que saem', async () => {
        const wrapper = await withoutQuantitative(await domainsView());

        expect(wrapper.findAll('thead tr:nth-child(3) th')).toHaveLength(declaredWidth(wrapper));
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declaredWidth(wrapper));
    });

    it('os grupos do cabeçalho somam a largura do bloco a que pertencem', async () => {
        const wrapper = await domainsView();
        const blocks = wrapper.findAll('thead tr:first-child th').slice(1);
        const groups = wrapper.findAll('thead tr:nth-child(2) th');

        const domainsBlock = Number(blocks[0].attributes('colspan'));
        const finalBlock = Number(blocks[blocks.length - 1].attributes('colspan'));

        const width = (cells: typeof groups) =>
            cells.reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);

        // Os dois primeiros grupos são os domínios; os três últimos, o bloco
        // final (os mesmos domínios outra vez, e o Global).
        expect(width(groups.slice(0, 2))).toBe(domainsBlock);
        expect(width(groups.slice(2))).toBe(finalBlock);
    });
});

describe('Quadro Síntese · por domínio — os casos que faltavam', () => {
    it('um domínio sem leitura contínua mostra «—» e não uma célula em falta', async () => {
        const base = props();
        const synopsisWithoutLeitura = base.synopsis;
        // O ANO PODE NÃO TER FECHADO NUM DOMÍNIO — um domínio sem qualquer
        // resultado formal não tem média nenhuma, e «—» é a resposta honesta.
        // O que não pode acontecer é a célula desaparecer: a linha deixaria de
        // bater certo com o cabeçalho.
        delete synopsisWithoutLeitura.students[0].continuous_domains[2];

        const wrapper = mount(Summary, {
            props: { ...base, synopsis: synopsisWithoutLeitura },
            global: { stubs },
        });
        await wrapper.findAll('button[role="tab"]')[1].trigger('click');

        const declared = wrapper
            .findAll('thead tr:first-child th')
            .slice(1)
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);

        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declared);
        expect(wrapper.find('[title*="Leitura: sem resultados formais"]').exists()).toBe(true);
    });

    it('três períodos com os quantitativos desligados continuam a fechar certo', async () => {
        const wrapper = await withoutQuantitative(await threePeriodsView());

        const declared = wrapper
            .findAll('thead tr:first-child th')
            .slice(1)
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);

        expect(wrapper.findAll('thead tr:nth-child(3) th')).toHaveLength(declared);
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declared);

        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());
        expect(columns).not.toContain('Média final');
        expect(columns.filter((label) => label === 'Menção final')).toHaveLength(2);
    });
});

/**
 * DOIS CONTROLOS, DUAS PERGUNTAS.
 *
 * «Mostrar quantitativos» decide se se veem números; «Mostrar desempenho
 * acumulado» decide se a leitura analítica do ano está na grelha. Um professor
 * que desligue os números não pediu para tirar uma leitura, e um que tire a
 * leitura não pediu para ficar sem números.
 *
 * E DESLIGAR NÃO MUDA CONTA NENHUMA: a avaliação contínua final, a proposta e o
 * nível atribuído não dependem do acumulado, e o que sai da grelha são duas
 * colunas por domínio.
 */
describe('Quadro Síntese · por domínio — o desempenho acumulado', () => {
    it('ligado por omissão, com a coluna e a menção acumulada', async () => {
        const wrapper = await domainsView();
        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        expect(columns).toContain('Desemp. acum.');
        expect(columns).toContain('Menção');
    });

    it('desligado, as duas colunas saem e a grelha estreita', async () => {
        const before = (await domainsView()).findAll('tbody tr:first-child td').length;
        const wrapper = await withoutAccumulated(await domainsView());
        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        expect(columns).not.toContain('Desemp. acum.');
        expect(columns).not.toContain('Menção');

        // Dois domínios × duas colunas, mais a coluna que cada unidade tinha
        // no bloco das sínteses: seis ao todo, e a grelha estreita mesmo.
        expect(wrapper.findAll('tbody tr:first-child td').length).toBe(before - 6);
    });

    it('desligado, a avaliação contínua final não mexe', async () => {
        const wrapper = await withoutAccumulated(await domainsView());
        const columns = wrapper.findAll('thead tr:nth-child(3) th').map((cell) => cell.text().trim());

        // O bloco final continua inteiro: cada domínio com a sua média e a sua
        // menção, e o global a fechar.
        expect(columns.filter((label) => label === 'Média final')).toHaveLength(3);
        expect(columns.filter((label) => label === 'Menção final')).toHaveLength(2);
        expect(bodyCells(wrapper)).toContain('62,4%');
    });

    it('as larguras declaradas continuam a bater certo com ele desligado', async () => {
        const wrapper = await withoutAccumulated(await domainsView());

        const declared = wrapper
            .findAll('thead tr:first-child th')
            .slice(1)
            .reduce((total, cell) => total + Number(cell.attributes('colspan') ?? 1), 0);

        expect(wrapper.findAll('thead tr:nth-child(3) th')).toHaveLength(declared);
        expect(wrapper.findAll('tbody tr:first-child td')).toHaveLength(declared);
    });

    it('os dois controlos não se confundem um com o outro', async () => {
        // Sem números, a leitura acumulada continua na grelha.
        const noNumbers = await withoutQuantitative(await domainsView());
        expect(noNumbers.findAll('thead tr:nth-child(3) th').map((c) => c.text().trim())).toContain('Desemp. acum.');

        // Sem a leitura acumulada, os números continuam.
        const noAccumulated = await withoutAccumulated(await domainsView());
        expect(bodyCells(noAccumulated).some((cell) => cell.includes('%'))).toBe(true);
    });
});
