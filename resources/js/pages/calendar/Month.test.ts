import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as VueModule from 'vue';
import { nextTick } from 'vue';
import type {
    CalendarAssessment,
    CalendarDay,
    CalendarEvent,
    CalendarException,
    CalendarPeriod,
} from './calendar';
import { EXCEPTION_DAY_TINT, periodDayTint, periodTint } from './calendar';
import Month from './Month.vue';

const routerGet = vi.fn();
const formPost = vi.fn();
const formPut = vi.fn();
const formDelete = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent: define, h: hyper, reactive } =
        await vi.importActual<typeof VueModule>('vue');

    return {
        Head: define({ setup: (_, { slots }) => () => hyper('div', slots.default?.()) }),
        Link: define({
            inheritAttrs: false,
            setup: (_, { attrs, slots }) => () => hyper('a', attrs, slots.default?.()),
        }),
        router: { get: (...args: unknown[]) => routerGet(...args) },
        // Enough of useForm for this page: the fields themselves, the errors
        // bag the inputs read, and the three verbs it submits with.
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
                delete: (...args: unknown[]) => formDelete(...args),
            }),
    };
});

function assessment(overrides: Partial<CalendarAssessment> = {}): CalendarAssessment {
    return {
        ulid: 'inst-a',
        title: 'Teste de Frações',
        applied_on: '2026-10-15',
        class_ulid: 'class-a',
        class_label: '7.º C',
        subject: 'Matemática',
        type: 'Teste',
        status: 'prepared',
        status_label: 'Preparado',
        href: '/instruments/inst-a',
        ...overrides,
    };
}

function period(overrides: Partial<CalendarPeriod> = {}): CalendarPeriod {
    return {
        ulid: 'period-1',
        label: '1.º Período',
        kind: 'term',
        kind_label: 'Período',
        sequence: 1,
        starts_on: '2026-09-01',
        ends_on: '2026-12-18',
        ...overrides,
    };
}

function event(overrides: Partial<CalendarEvent> = {}): CalendarEvent {
    return {
        ulid: 'event-a',
        type: 'meeting',
        type_label: 'Reunião',
        type_short_label: 'REUNIÃO',
        title: 'Conselho de turma',
        starts_on: '2026-10-15',
        ends_on: '2026-10-15',
        starts_at: null,
        ends_at: null,
        description: null,
        school_classes: [],
        ...overrides,
    };
}

/**
 * The real October 2026 grid: the month opens on a Thursday, so the grid runs
 * from Monday 28 September to Sunday 1 November — 35 cells.
 */
function octoberDays(
    fill: (date: string) => Partial<CalendarDay> = () => ({}),
): CalendarDay[] {
    const days: CalendarDay[] = [];
    const cursor = new Date('2026-09-28T00:00:00Z');

    for (let index = 0; index < 35; index += 1) {
        const date = cursor.toISOString().slice(0, 10);

        days.push({
            date,
            day: cursor.getUTCDate(),
            in_month: date >= '2026-10-01' && date <= '2026-10-31',
            is_today: false,
            period: null,
            exception: null,
            assessments: [],
            events: [],
            ...fill(date),
        });

        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return days;
}

/**
 * The real September 2026 grid: the month opens on a Tuesday, so it runs from
 * Monday 31 August to Sunday 4 October — 35 cells. It is the month a year that
 * opens on 11 September splits in two, which is what the per-day tint has to be
 * exact about.
 */
function septemberDays(
    fill: (date: string) => Partial<CalendarDay> = () => ({}),
): CalendarDay[] {
    const days: CalendarDay[] = [];
    const cursor = new Date('2026-08-31T00:00:00Z');

    for (let index = 0; index < 35; index += 1) {
        const date = cursor.toISOString().slice(0, 10);

        days.push({
            date,
            day: cursor.getUTCDate(),
            in_month: date >= '2026-09-01' && date <= '2026-09-30',
            is_today: false,
            period: null,
            exception: null,
            assessments: [],
            events: [],
            ...fill(date),
        });

        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return days;
}

/** Every cell an acontecimento covers, the way the server fills them. */
function covering(target: CalendarEvent) {
    return (date: string): Partial<CalendarDay> =>
        date >= target.starts_on && date <= target.ends_on
            ? { events: [target] }
            : {};
}

function exception(
    overrides: Partial<CalendarException> = {},
): CalendarException {
    return {
        ulid: 'exception-a',
        type: 'holiday',
        type_label: 'Feriado',
        type_short_label: 'FERIADO',
        title: 'Implantação da República',
        starts_on: '2026-10-05',
        ends_on: '2026-10-05',
        note: null,
        ...overrides,
    };
}

/**
 * Every cell an exceção covers, the way the server fills them — one `exception`
 * per day, never a list, exactly as `period` already is.
 */
function coveredBy(target: CalendarException) {
    return (date: string): Partial<CalendarDay> =>
        date >= target.starts_on && date <= target.ends_on
            ? { exception: target }
            : {};
}

function mountPage(overrides: Partial<InstanceType<typeof Month>['$props']> = {}) {
    return mount(Month, {
        props: {
            academicYear: {
                ulid: 'year-a',
                label: '2026/2027',
                starts_on: '2026-09-01',
                ends_on: '2027-07-31',
            },
            month: {
                value: '2026-10',
                starts_on: '2026-10-01',
                ends_on: '2026-10-31',
            },
            days: octoberDays(),
            periods: [],
            exceptions: [],
            navigation: {
                previous: '2026-09',
                next: '2026-11',
                home: '2026-10',
                home_is_today: true,
            },
            itemsPerDay: 3,
            classes: [{ ulid: 'class-a', label: '7.º C', subject: 'Matemática' }],
            ...overrides,
        },
    });
}

/** The panel is teleported to the body, so that is where it is read. */
function panelText(): string {
    return document.body.textContent ?? '';
}

function panelInput(id: string): HTMLInputElement | null {
    return document.body.querySelector<HTMLInputElement>(`#${id}`);
}

/**
 * «Calendário do Ano Letivo», vista Mês — a estrutura do ano, as avaliações e
 * os acontecimentos do próprio professor, lidos juntos.
 *
 * A PÁGINA DEIXOU DE SER SÓ UMA LEITURA (Fase 5.3), e só numa direção: o
 * professor pode criar, alterar e eliminar ACONTECIMENTOS — a reunião, a
 * atividade, a visita de estudo, o «outro» — que são as únicas coisas datadas
 * sem casa noutro sítio. As avaliações e os períodos continuam a ser lidos e a
 * ser alterados nas suas próprias páginas, e nada aqui lhes toca. As aulas
 * continuam ausentes de propósito: essa é a pergunta do «Horário do Professor».
 */
describe('calendar/Month', () => {
    beforeEach(() => {
        routerGet.mockClear();
        formPost.mockClear();
        formPut.mockClear();
        formDelete.mockClear();
        document.body.innerHTML = '';
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    // ------------------------------------------- o mês é o título da página

    /**
     * A PRIMEIRA PERGUNTA DE QUEM ABRE UM CALENDÁRIO é «que mês é este?», e era
     * a mais difícil de responder da página: «Outubro de 2026» estava enterrado
     * a meio de uma linha esbatida de contexto, do mesmo tamanho e do mesmo peso
     * que o número de avaliações. Agora é o elemento mais forte do cabeçalho.
     */
    it('makes the month being viewed the loudest thing in the header', () => {
        const wrapper = mountPage();
        const title = wrapper.find('[data-month-title]');

        expect(title.exists()).toBe(true);
        expect(title.text()).toBe('Outubro de 2026');

        // Grande e pesado — e afirmado como classe e não como pixéis, que é o
        // que esta camada pode mesmo saber.
        expect(title.classes().join(' ')).toContain('font-bold');
        expect(title.classes().join(' ')).toMatch(/text-(2xl|3xl|4xl)/);

        // E mais forte do que tudo o resto que está lá: nem a identidade da
        // página nem o contexto secundário levam este peso.
        const others = wrapper
            .find('header')
            .findAll('p, h1, h2, h3')
            .filter((node) => node.attributes('data-month-title') === undefined);

        expect(others.length).toBeGreaterThan(0);

        for (const node of others) {
            expect(node.classes().join(' ')).not.toContain('font-bold');
            expect(node.classes().join(' ')).not.toMatch(/text-(2xl|3xl|4xl)/);
        }
    });

    /**
     * A IDENTIDADE DA PÁGINA NÃO DESAPARECEU — trocou de lugar com o mês, que é
     * o que muda de ecrã para ecrã. E o contexto secundário continua todo lá, só
     * que sem repetir o mês que já está escrito em grande logo acima.
     */
    it('keeps the page identity and the secondary context, subordinate to the month', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? { assessments: [assessment()], events: [event()] }
                    : {},
            ),
        });

        const header = wrapper.find('header');

        expect(header.text()).toContain('Calendário do Ano Letivo');
        expect(header.text()).toContain('2026/2027');
        expect(header.text()).toContain('1 avaliação');
        expect(header.text()).toContain('1 acontecimento');

        // O mês está escrito UMA vez, e não duas: o título já o diz.
        expect(header.text().split('Outubro de 2026')).toHaveLength(2);
    });

    /**
     * ANDAR ENTRE MESES É UMA COISA DO MÊS, e por isso vive colada ao título
     * dele — e não solta numa linha própria por baixo, onde se confundia com o
     * seletor de vista. As duas navegações respondem a perguntas diferentes: uma
     * troca de VISTA, a outra anda dentro da vista de Mês.
     */
    it('groups the month navigation with the month title, kept distinct from the view switcher', () => {
        const wrapper = mountPage();
        const title = wrapper.find('[data-month-title]');

        // Irmãos na mesma caixa, e não em duas zonas separadas da página.
        expect(
            title.element.parentElement?.querySelector(
                'nav[aria-label="Navegação entre meses"]',
            ),
        ).not.toBeNull();

        // E tratados de maneiras diferentes: a vista ativa é um botão cheio, os
        // botões de andar entre meses são leves.
        const viewButtons = wrapper.findAll('nav[aria-label="Vista do calendário"] button');
        const monthButtons = wrapper.findAll('nav[aria-label="Navegação entre meses"] button');

        expect(viewButtons[0]!.classes().join(' ')).toContain('bg-primary');
        expect(monthButtons).toHaveLength(3);

        for (const button of monthButtons) {
            expect(button.classes().join(' ')).not.toContain('bg-primary');
        }
    });

    it('lays the month out as whole weeks of seven days', () => {
        const wrapper = mountPage();

        const cells = wrapper.findAll('[data-date]');

        expect(cells).toHaveLength(35);
        expect(cells[0]!.attributes('data-date')).toBe('2026-09-28');
        expect(cells[34]!.attributes('data-date')).toBe('2026-11-01');
    });

    it('names the weekdays in Portuguese without capitalising every word', () => {
        const text = mountPage().text();

        // capitalizeFirst, never the CSS `capitalize` class: the day after the
        // hyphen stays lower case, and so does every "de" on this page.
        expect(text).toContain('Seg');
        expect(text).toContain('Sáb');
        expect(mountPage().html()).not.toContain('capitalize');
    });

    it('writes the month heading with only its first letter raised', () => {
        const text = mountPage().text();

        expect(text).toContain('Outubro de 2026');
        expect(text).not.toContain('Outubro De 2026');
    });

    it('renders an assessment as a link to the instrument\'s own page', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: [assessment()] } : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');
        const link = cell.find('a');

        expect(link.attributes('href')).toBe('/instruments/inst-a');
        expect(cell.text()).toContain('Teste de Frações');
        expect(cell.text()).toContain('7.º C');
        expect(cell.text()).toContain('Teste');
    });

    it('shows a período as structural context, distinguished from an assessment by more than colour', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        // The legend names the período in words — the tint is never the only
        // thing carrying it.
        expect(wrapper.text()).toContain('1.º Período');
        expect(wrapper.text()).toContain('Período');

        // An avaliação carries a border, an icon and emphasis; a período band
        // carries none of the three, so the two stay apart on a monochrome
        // screen and for a reader who does not see the hue.
        const entry = wrapper.find('[data-date="2026-10-15"]').find('a');
        expect(entry.classes().join(' ')).toContain('border');
        expect(entry.classes().join(' ')).toContain('font-medium');
        expect(entry.find('svg').exists()).toBe(true);
    });

    /**
     * O NOME DE UM PERÍODO JÁ DIZ A ESPÉCIE — «1.º Semestre», «2.º Período» — e
     * a faixa escrevia-a outra vez logo a seguir («1.º Semestre · Semestre ·
     * 11/09 – 29/01»), o que não acrescentava nada a ninguém.
     */
    it('names a período once, without repeating its kind right after it', () => {
        const semester = period({
            label: '1.º Semestre',
            kind: 'semester',
            kind_label: 'Semestre',
            starts_on: '2026-09-11',
            ends_on: '2027-01-29',
        });

        const legend = mountPage({ periods: [semester] }).find(
            'section[aria-label="Períodos deste mês"]',
        );

        expect(legend.text()).toContain('1.º Semestre');
        expect(legend.text()).not.toContain('· Semestre ·');
        expect(legend.text()).not.toContain('Semestre · Semestre');
        // As datas continuam lá: é o que a faixa tem para dizer que o nome não diz.
        expect(legend.text()).toContain('–');
    });

    // --------------------------- a faixa estrutural, deste MÊS e não da grelha

    const SEMESTER_ONE = period({
        ulid: 'sem-1',
        label: '1.º Semestre',
        kind: 'semester',
        kind_label: 'Semestre',
        starts_on: '2026-09-11',
        ends_on: '2027-01-29',
    });

    const SEMESTER_TWO = period({
        ulid: 'sem-2',
        label: '2.º Semestre',
        kind: 'semester',
        kind_label: 'Semestre',
        sequence: 2,
        starts_on: '2027-02-11',
        ends_on: '2027-07-31',
    });

    function inMonth(value: string, startsOn: string, endsOn: string, periods: CalendarPeriod[]) {
        return mountPage({ month: { value, starts_on: startsOn, ends_on: endsOn }, periods });
    }

    function bandText(wrapper: ReturnType<typeof mountPage>): string {
        return wrapper.find('section[aria-label="Períodos deste mês"]').text();
    }

    /**
     * UM MÊS INTEIRAMENTE DENTRO DE UM PERÍODO é o caso simples, e continua a
     * dizer exatamente o que sempre disse: o período de uma ponta à outra.
     */
    it('states a fully contained month plainly, with the período\'s own whole range', () => {
        const wrapper = inMonth('2026-10', '2026-10-01', '2026-10-31', [SEMESTER_ONE]);

        expect(bandText(wrapper)).toContain('1.º Semestre · 11/09 – 29/01');
        expect(bandText(wrapper)).not.toContain('desde');
        expect(bandText(wrapper)).not.toContain('até');
    });

    /**
     * UM MÊS DE TRANSIÇÃO DIZ ONDE É QUE O PERÍODO REALMENTE ABRE OU FECHA.
     * Setembro, num ano que abre a 11 de setembro, dizia «1.º Semestre · 11/09 –
     * 29/01» — verdadeiro sobre o semestre, e falso sobre os dez primeiros dias
     * do mês que se está a ver. E É A MESMA FRASE QUE A VISTA DE ANO JÁ DIZIA
     * sobre o mesmo mês, porque é a mesma função a construí-la.
     */
    it('says «desde» in the month a período opens in, and «até» in the month it closes in', () => {
        const september = inMonth('2026-09', '2026-09-01', '2026-09-30', [SEMESTER_ONE]);
        expect(bandText(september)).toContain('1.º Semestre · desde 11/09');
        expect(bandText(september)).not.toContain('29/01');

        const january = inMonth('2027-01', '2027-01-01', '2027-01-31', [SEMESTER_ONE]);
        expect(bandText(january)).toContain('1.º Semestre · até 29/01');
        expect(bandText(january)).not.toContain('11/09');

        const february = inMonth('2027-02', '2027-02-01', '2027-02-28', [SEMESTER_TWO]);
        expect(bandText(february)).toContain('2.º Semestre · desde 11/02');
        expect(bandText(february)).not.toContain('1.º Semestre');
    });

    it('writes both ends when a período opens and closes inside the same month', () => {
        const wrapper = inMonth('2026-10', '2026-10-01', '2026-10-31', [
            period({
                ulid: 'p-brief',
                label: 'Módulo A',
                kind: 'module',
                kind_label: 'Módulo',
                starts_on: '2026-10-05',
                ends_on: '2026-10-23',
            }),
        ]);

        expect(bandText(wrapper)).toContain('Módulo A · de 5/10 a 23/10');
    });

    /**
     * DOIS PERÍODOS A TOCAREM O MESMO MÊS APARECEM OS DOIS — nunca só o
     * primeiro, e nunca um deles escondido: um mês em que um período fecha e o
     * seguinte abre é as duas coisas ao mesmo tempo.
     */
    it('shows both períodos when two of them touch the same month', () => {
        const wrapper = inMonth('2026-11', '2026-11-01', '2026-11-30', [
            period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-11-05' }),
            period({
                ulid: 'p2',
                label: '2.º Período',
                sequence: 2,
                starts_on: '2026-11-15',
                ends_on: '2027-01-31',
            }),
        ]);

        const bands = wrapper.findAll('[data-period-band]');

        expect(bands).toHaveLength(2);
        expect(bands[0]!.text()).toContain('1.º Período · até 5/11');
        expect(bands[1]!.text()).toContain('2.º Período · desde 15/11');

        // E OS DOIS DENTRO DA MESMA FAIXA, e não em duas faixas ao lado uma da
        // outra: o mês é um só, e o que se lê é a estrutura dele.
        const strips = wrapper.findAll('section[aria-label="Períodos deste mês"]');

        expect(strips).toHaveLength(1);
        expect(strips[0]!.findAll('[data-period-band]')).toHaveLength(2);
    });

    /**
     * A FAIXA É UMA FAIXA, e não uma pastilha à solta. Era uma etiqueta pequena
     * num canto — «um crachá solto», nas palavras de quem a foi ver — e passava
     * despercebida justamente à pessoa que abre o calendário para saber em que
     * período está. Agora é uma tira baixa e larga, imediatamente por cima da
     * grelha, com a mesma largura do que está por baixo dela.
     */
    it('lays the período context out as one low, full-width strip above the grid', () => {
        const wrapper = mountPage({ periods: [SEMESTER_ONE] });
        const strip = wrapper.find('section[aria-label="Períodos deste mês"]');
        const classes = strip.classes().join(' ');
        const segments = strip.findAll('[data-period-band]');

        // Uma tira: larga por omissão (nada a encolhe para o tamanho do texto),
        // e com uma moldura só à volta de tudo.
        expect(classes).not.toContain('inline');
        expect(classes).toMatch(/\bborder\b/);

        // Baixa, e com folga horizontal a sério — a medida está no segmento,
        // que com um período só é a faixa inteira.
        expect(segments).toHaveLength(1);
        const segment = segments[0]!.classes().join(' ');
        expect(segment).toMatch(/\bpx-4\b/);
        expect(segment).toMatch(/\bpy-2(\.5)?\b/);
        expect(segment).toMatch(/\bflex-1\b/);

        // E NÃO É UM BOTÃO nem um acontecimento: nada em que carregar, nenhuma
        // sombra, nenhum ícone, nenhuma moldura de cor.
        expect(strip.findAll('button')).toHaveLength(0);
        expect(strip.findAll('a')).toHaveLength(0);
        expect(strip.find('svg').exists()).toBe(false);
        expect(strip.html()).not.toMatch(/shadow|cursor-pointer|hover:/);

        // Imediatamente antes da grelha, e não algures noutro sítio da página.
        expect(
            strip.element.nextElementSibling?.getAttribute('aria-label'),
        ).toBe('Grelha do mês');
    });

    /**
     * A FAIXA FALA DO MÊS, E NÃO DA GRELHA. `periods` chega preenchido a partir
     * do intervalo VISÍVEL — que inclui os últimos dias de setembro e os
     * primeiros de novembro, para as linhas fecharem — e um período que só toca
     * o dia 29 de setembro não é estrutura de outubro nenhuma.
     */
    it('ignores a período that only touches the grid\'s leading days, not the month itself', () => {
        const wrapper = inMonth('2026-10', '2026-10-01', '2026-10-31', [
            period({
                ulid: 'p-setembro',
                label: 'Período de setembro',
                starts_on: '2026-09-01',
                ends_on: '2026-09-29',
            }),
        ]);

        // Nem faixa, nem período por omissão, nem um aviso inventado a dizer que
        // o ano não tem períodos — que seria falso, porque tem.
        expect(wrapper.find('section[aria-label="Períodos deste mês"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Período de setembro');
        expect(wrapper.text()).not.toContain('ainda não tem períodos definidos');
    });

    // ----------------------------------- o tom de cada DIA, exato ao dia

    /** O fundo que uma célula da grelha está mesmo a levar. */
    function cellBackgrounds(
        wrapper: ReturnType<typeof mountPage>,
        date: string,
    ): string[] {
        return wrapper
            .find(`[data-date="${date}"]`)
            .classes()
            .filter((name) => name.startsWith('bg-'));
    }

    /**
     * A ESTRUTURA DO ANO VOLTOU À GRELHA — a sussurrar, e não a gritar. A grelha
     * inteiramente branca era honesta e ilegível: a forma do ano letivo
     * desaparecia da única página feita para a mostrar. O que voltou é um tom
     * pálido por dia; o que NÃO voltou é o banho de cor antigo, de peso 100 e
     * por mês inteiro.
     */
    it('tints a day that sits inside a período with that período\'s own soft tone', () => {
        const wrapper = mountPage({
            periods: [SEMESTER_ONE],
            days: octoberDays(() => ({ period: SEMESTER_ONE })),
        });

        const backgrounds = cellBackgrounds(wrapper, '2026-10-15');

        expect(backgrounds).toContain(periodDayTint(SEMESTER_ONE).split(' ')[0]);
        // E a célula continua sem nomear o período: a faixa já o diz.
        expect(wrapper.find('[data-date="2026-10-15"]').text()).not.toContain(
            'Semestre',
        );
        expect(bandText(wrapper)).toContain('1.º Semestre');
    });

    /**
     * E É EXATO AO DIA, tão exato como a faixa. É a fronteira que importa: o dia
     * ANTES de um período abrir não é dele, e o dia em que ele abre é. Num
     * setembro de um ano que começa a 11, os dias 1 a 10 ficam por pintar —
     * pintar setembro inteiro é dizer uma coisa falsa sobre eles.
     */
    it('paints not one day more than the período covers, on either side of its edge', () => {
        const wrapper = mountPage({
            month: { value: '2026-09', starts_on: '2026-09-01', ends_on: '2026-09-30' },
            periods: [SEMESTER_ONE],
            days: septemberDays((date) => ({
                period: date >= SEMESTER_ONE.starts_on ? SEMESTER_ONE : null,
            })),
        });

        const tint = periodDayTint(SEMESTER_ONE).split(' ')[0] as string;

        // Os dez primeiros dias, antes do semestre abrir: nenhum tom.
        for (const date of ['2026-09-01', '2026-09-05', '2026-09-10']) {
            expect(cellBackgrounds(wrapper, date)).not.toContain(tint);
            expect(cellBackgrounds(wrapper, date)).toEqual([]);
        }

        // A fronteira: o dia 10 sem tom, o dia 11 com ele.
        expect(cellBackgrounds(wrapper, '2026-09-10')).not.toContain(tint);
        expect(cellBackgrounds(wrapper, '2026-09-11')).toContain(tint);
        expect(cellBackgrounds(wrapper, '2026-09-30')).toContain(tint);
    });

    /**
     * O INTERVALO ENTRE DOIS PERÍODOS FICA POR PINTAR, que é o que ele é — e os
     * dois períodos que tocam o mesmo mês trazem cada um o SEU tom, e não o
     * mesmo: a fronteira entre eles é justamente o que aquele mês tem para
     * mostrar.
     */
    it('gives each período its own tone, and the gap between them none at all', () => {
        const first = period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-10-10' });
        const second = period({
            ulid: 'p2',
            label: '2.º Período',
            sequence: 2,
            starts_on: '2026-10-15',
        });

        const wrapper = mountPage({
            periods: [first, second],
            days: octoberDays((date) => ({
                period:
                    date <= '2026-10-10'
                        ? first
                        : date >= '2026-10-15'
                          ? second
                          : null,
            })),
        });

        const firstTint = periodDayTint(first).split(' ')[0] as string;
        const secondTint = periodDayTint(second).split(' ')[0] as string;

        expect(firstTint).not.toBe(secondTint);
        expect(cellBackgrounds(wrapper, '2026-10-05')).toContain(firstTint);
        expect(cellBackgrounds(wrapper, '2026-10-20')).toContain(secondTint);

        // Os dias entre os dois: sem tom nenhum, e não com metade de um.
        for (const date of ['2026-10-12', '2026-10-13', '2026-10-14']) {
            expect(cellBackgrounds(wrapper, date)).toEqual([]);
        }
    });

    /**
     * «FORA DO MÊS» GANHA SEMPRE. Um dia que a grelha só mostra para a linha
     * fechar pode estar dentro de um período, e está: mas o que é preciso saber
     * sobre ele, primeiro e acima de tudo, é que não é deste mês. As duas tintas
     * ao mesmo tempo não dizem as duas coisas, dizem uma cor confusa.
     */
    it('keeps a leading grid day reading as «not this month» before anything else', () => {
        const wrapper = mountPage({
            periods: [SEMESTER_ONE],
            days: octoberDays(() => ({ period: SEMESTER_ONE })),
        });

        // 28 de setembro está dentro do semestre e fora de outubro.
        expect(cellBackgrounds(wrapper, '2026-09-28')).toEqual(['bg-muted/30']);
        expect(cellBackgrounds(wrapper, '2026-11-01')).toEqual(['bg-muted/30']);
    });

    /**
     * E O TOM DA CÉLULA NUNCA COMPETE COM O QUE ESTÁ DENTRO DELA. Uma avaliação e
     * um acontecimento trazem moldura, ícone e fundo próprios, que assentam POR
     * CIMA do fundo da célula: o que os separa dela não é a matiz, é o peso.
     */
    it('leaves an avaliação and an acontecimento reading over the tint, not against it', () => {
        const wrapper = mountPage({
            periods: [SEMESTER_ONE],
            days: octoberDays((date) => ({
                period: SEMESTER_ONE,
                ...(date === '2026-10-15'
                    ? { assessments: [assessment()], events: [event()] }
                    : {}),
            })),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        // A célula leva o tom do período…
        expect(cell.classes()).toContain(
            periodDayTint(SEMESTER_ONE).split(' ')[0],
        );

        // …e as entradas continuam com o seu próprio fundo e a sua própria
        // moldura, exatamente como antes: nenhuma delas herda o tom da célula.
        const entry = cell.find('a').classes().join(' ');
        expect(entry).toContain('bg-background/80');
        expect(entry).toContain('border-foreground/25');
        expect(entry).toContain('font-medium');
        expect(cell.find('a').find('svg').exists()).toBe(true);

        const meeting = cell.find('[data-event-ulid="event-a"]').classes().join(' ');
        expect(meeting).toContain('bg-background/70');
        expect(meeting).toContain('border-indigo-500/70');
        expect(meeting).not.toContain('bg-amber-50/70');
    });

    /**
     * O TOM DA FAIXA É O MESMO TOM DA GRELHA. A lista de períodos por cima da
     * grelha é a legenda do que está por baixo dela, e uma legenda de outra cor
     * não é uma legenda. Mesma matiz, peso diferente: a faixa é uma tira baixa,
     * a célula é uma superfície de altura inteira, e a mesma tinta nas duas
     * deixava de ser discreta na segunda.
     */
    it('carries the same per-período hue in the strip as in the grid below it', () => {
        const wrapper = mountPage({
            periods: [SEMESTER_ONE],
            days: octoberDays(() => ({ period: SEMESTER_ONE })),
        });

        const segment = wrapper.find('[data-period-band="sem-1"]').classes();

        expect(segment.join(' ')).toContain(periodTint(SEMESTER_ONE));

        // A mesma família de cor nos dois sítios, mais fraca no dia.
        const hue = (name: string) => name.replace(/^bg-([a-z]+)-.*$/, '$1');
        const stripHue = hue(periodTint(SEMESTER_ONE).split(' ')[0] as string);
        const dayHue = hue(periodDayTint(SEMESTER_ONE).split(' ')[0] as string);

        expect(dayHue).toBe(stripHue);
        expect(periodDayTint(SEMESTER_ONE)).toContain('/70');
    });

    /**
     * NUM MÊS DE TRANSIÇÃO, CADA METADE DA FAIXA NO SEU TOM. A faixa era de uma
     * cor só, e os dois períodos ficavam com o mesmo fundo — a mesma tinta a
     * apagar justamente a fronteira que aquele mês tem para mostrar. Continua a
     * ser UMA faixa, com uma moldura só; o que mudou foi ficar repartida por
     * dentro.
     */
    it('tints each half of the strip in its own período\'s tone in a transition month', () => {
        const first = period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-11-05' });
        const second = period({
            ulid: 'p2',
            label: '2.º Período',
            sequence: 2,
            starts_on: '2026-11-15',
            ends_on: '2027-01-31',
        });

        const wrapper = inMonth('2026-11', '2026-11-01', '2026-11-30', [first, second]);

        // Uma faixa só, e dois segmentos lá dentro.
        expect(wrapper.findAll('section[aria-label="Períodos deste mês"]')).toHaveLength(1);

        const one = wrapper.find('[data-period-band="p1"]').classes().join(' ');
        const other = wrapper.find('[data-period-band="p2"]').classes().join(' ');

        expect(one).toContain(periodTint(first));
        expect(other).toContain(periodTint(second));
        expect(periodTint(first)).not.toBe(periodTint(second));

        // E o que os separa nunca é só a cor: cada segmento diz o nome do seu
        // período, e há um traço desenhado entre os dois.
        expect(wrapper.find('[data-period-band="p1"]').text()).toContain('1.º Período');
        expect(wrapper.find('[data-period-band="p2"]').text()).toContain('2.º Período');
        expect(other).toMatch(/\bborder-l\b/);
    });

    /**
     * E OS TONS CONTINUAM PÁLIDOS. Nem o arco-íris de peso 100 que aqui esteve —
     * que pintava a página inteira de azul só porque se estava a meio de um
     * semestre — nem o cinzento que se lia como sujidade e desaparecia.
     */
    it('keeps every período tone at the palest weight, and none of the old loud ones', () => {
        const wrapper = mountPage({
            periods: [SEMESTER_ONE],
            days: octoberDays(() => ({ period: SEMESTER_ONE })),
        });

        // Peso 50, e nunca os pesos saturados de um acontecimento.
        for (const tone of [periodTint(SEMESTER_ONE), periodDayTint(SEMESTER_ONE)]) {
            expect(tone).toMatch(/bg-[a-z]+-50/);
            expect(tone).not.toMatch(/-(600|700|800)\b/);
            expect(tone).not.toContain('border-');
            expect(tone).not.toContain('text-');
        }

        // O cinzento antigo foi-se embora de toda a página.
        expect(wrapper.html()).not.toContain('stone');

        // E a moldura da faixa é NEUTRA: com uma moldura de âmbar, a estrutura do
        // ano passaria a ler-se como a cor de uma visita de estudo.
        const strip = wrapper.find('section[aria-label="Períodos deste mês"]');
        expect(strip.html()).not.toMatch(/border-amber|border-orange/);

        for (const tint of [
            'bg-sky-100/70',
            'bg-amber-100/70',
            'bg-emerald-100/70',
            'bg-violet-100/70',
            'bg-rose-100/70',
            'bg-teal-100/70',
        ]) {
            expect(wrapper.html()).not.toContain(tint);
        }
    });

    /**
     * A CÉLULA DO DIA DEIXOU DE NOMEAR O PERÍODO. Nomeava-o onde ele começava —
     * a célula do dia 11 escrevia «1.º Semestre» — a um centímetro de uma faixa
     * que já diz «1.º Semestre · desde 11/09» por cima da grelha inteira e com
     * muito mais peso. Era a mesma coisa dita duas vezes, e a segunda só fazia
     * ruído dentro do dia.
     */
    it('never names a período inside a grid day cell, now that the strip says it', () => {
        const first = period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-10-10' });
        const second = period({ ulid: 'p2', label: '2.º Período', starts_on: '2026-10-15' });

        const wrapper = mountPage({
            month: { value: '2026-10', starts_on: '2026-10-01', ends_on: '2026-10-31' },
            periods: [first, second],
            days: octoberDays((date) => ({
                // Um período, um intervalo por dizer, e o período seguinte: as
                // três situações em que a célula chegou a escrever um nome.
                period:
                    date <= '2026-10-10'
                        ? first
                        : date >= '2026-10-15'
                          ? second
                          : null,
            })),
        });

        // Nem na primeira célula da grelha, nem naquela onde a faixa muda, nem
        // em nenhuma das trinta e cinco.
        for (const cell of wrapper.findAll('[data-date]')) {
            expect(cell.text()).not.toContain('Período');
        }

        // E continua dito — uma vez, na faixa, por cima da grelha inteira.
        expect(bandText(wrapper)).toContain('1.º Período');
        expect(bandText(wrapper)).toContain('2.º Período');
    });

    it('caps a crowded day and offers the rest behind one control', () => {
        const crowded = ['a', 'b', 'c', 'd', 'e'].map((key) =>
            assessment({ ulid: key, title: `Ficha ${key.toUpperCase()}`, href: `/instruments/${key}` }),
        );

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: crowded } : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        // Three shown, two behind «+2 mais» — the cell does not grow without bound.
        expect(cell.findAll('li')).toHaveLength(3);
        expect(cell.text()).toContain('+2 mais');
        expect(cell.text()).not.toContain('Ficha E');
    });

    it('shows the whole crowded day once the overflow control is used, and closes again', async () => {
        const crowded = ['a', 'b', 'c', 'd', 'e'].map((key) =>
            assessment({ ulid: key, title: `Ficha ${key.toUpperCase()}`, href: `/instruments/${key}` }),
        );

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: crowded } : {},
            ),
        });

        await wrapper.find('[data-date="2026-10-15"]').find('[data-overflow]').trigger('click');

        let cell = wrapper.find('[data-date="2026-10-15"]');
        expect(cell.findAll('li')).toHaveLength(5);
        expect(cell.text()).toContain('Ficha E');
        expect(cell.text()).toContain('Ver menos');

        await cell.find('[data-overflow]').trigger('click');

        cell = wrapper.find('[data-date="2026-10-15"]');
        expect(cell.findAll('li')).toHaveLength(3);
        expect(cell.text()).toContain('+2 mais');
    });

    it('offers no overflow control on a day that fits', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? { assessments: [assessment(), assessment({ ulid: 'b', title: 'Ficha B' })] }
                    : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        expect(cell.findAll('li')).toHaveLength(2);
        expect(cell.find('[data-overflow]').exists()).toBe(false);
    });

    it('navigates to the previous and next month by their real values', async () => {
        const wrapper = mountPage();
        const buttons = wrapper.findAll('nav[aria-label="Navegação entre meses"] button');

        await buttons[0]!.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-09' },
            expect.anything(),
        );

        await buttons[2]!.trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-11' },
            expect.anything(),
        );
    });

    it('offers «Mês atual» when the year is running, and «Início do ano» when it is not', async () => {
        const running = mountPage();
        expect(running.text()).toContain('Mês atual');

        await running
            .findAll('nav[aria-label="Navegação entre meses"] button')[1]!
            .trigger('click');
        expect(routerGet).toHaveBeenLastCalledWith(
            '/calendar',
            { month: '2026-10' },
            expect.anything(),
        );

        const notRunning = mountPage({
            month: { value: '2026-09', starts_on: '2026-09-01', ends_on: '2026-09-30' },
            navigation: {
                previous: '2026-08',
                next: '2026-10',
                home: '2026-09',
                home_is_today: false,
            },
        });

        expect(notRunning.text()).toContain('Início do ano');
        expect(notRunning.text()).not.toContain('Mês atual');
    });

    it('links to the Ano view, which is an address and not a toggle', () => {
        const hrefs = mountPage()
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/calendar/ano');
    });

    it('still draws a real grid for a year with no períodos and no avaliações', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('[data-date]')).toHaveLength(35);
        expect(wrapper.text()).toContain('ainda não tem períodos definidos');
        // Never a blank page, and never invented content to fill it.
        expect(wrapper.find('section[aria-label="Grelha do mês"]').exists()).toBe(true);
    });

    it('explains itself instead of drawing a grid when there is no academic year at all', () => {
        const wrapper = mountPage({
            academicYear: null,
            month: null,
            navigation: null,
            days: [],
        });

        expect(wrapper.text()).toContain('Ainda não há um ano letivo para mostrar');
        expect(wrapper.findAll('[data-date]')).toHaveLength(0);
    });

    it('reads the month as an agenda for a narrow viewport, listing only the days that carry something', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15' ? { assessments: [assessment()] } : {},
            ),
        });

        const agenda = wrapper.find('section[aria-label="Agenda do mês"]');

        expect(agenda.exists()).toBe(true);
        expect(agenda.findAll('section')).toHaveLength(1);
        expect(agenda.text()).toContain('Quinta-feira, 15 de outubro');
        expect(agenda.text()).toContain('Matemática');
    });

    /**
     * THE PRODUCT DECISION, ASSERTED. Aulas belong to «Horário do Professor»;
     * this calendar answers a different question, and must never grow a lesson
     * count, a workload dot, or anything else derived from them.
     */
    it('shows nothing whatsoever about aulas', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15'
                    ? { assessments: [assessment()], events: [event()] }
                    : {}),
            })),
        });

        const text = wrapper.text();

        expect(text).not.toContain('aula');
        expect(text).not.toContain('Aula');
        expect(text).not.toContain('horário');
        expect(text).not.toContain('Horário');
    });

    // ------------------------------------------------ os acontecimentos

    it('renders an acontecimento with its icon and its written type, never colour alone', () => {
        const wrapper = mountPage({
            days: octoberDays(covering(event())),
        });

        const entry = wrapper.find('[data-event-ulid="event-a"]');

        expect(entry.exists()).toBe(true);
        // A palavra escrita, o ícone, e só então a cor.
        expect(entry.text()).toContain('REUNIÃO');
        expect(entry.text()).toContain('Conselho de turma');
        expect(entry.find('svg').exists()).toBe(true);
    });

    /**
     * A HORA DE INÍCIO no próprio cartão. «Às 16:30» e «durante o dia» são
     * coisas diferentes, e a grelha do mês dizia-as exatamente da mesma
     * maneira: o professor tinha de abrir o acontecimento para saber a que
     * horas era.
     */
    it('writes the start time on the card of an acontecimento that has one', () => {
        const wrapper = mountPage({
            days: octoberDays(
                covering(event({ starts_at: '16:30', ends_at: '17:00' })),
            ),
        });

        expect(
            wrapper.find('[data-date="2026-10-15"] [data-event-ulid="event-a"]').text(),
        ).toContain('REUNIÃO · 16:30');
    });

    /**
     * E NUNCA UMA HORA INVENTADA: a ausência de hora é informação — é o dia
     * inteiro — e não uma hora que ninguém chegou a escrever.
     */
    it('writes no time at all on an acontecimento that lasts the whole day', () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        const card = wrapper.find(
            '[data-date="2026-10-15"] [data-event-ulid="event-a"]',
        );

        expect(card.text()).toContain('REUNIÃO');
        expect(card.text()).toContain('Conselho de turma');
        expect(card.text()).not.toContain('·');
        expect(card.text()).not.toContain(':');
    });

    it('writes the start time the same way for each of the four kinds', () => {
        const kinds = [
            { type: 'meeting', short: 'REUNIÃO' },
            { type: 'activity', short: 'ATIVIDADE' },
            { type: 'field_trip', short: 'VISITA' },
            { type: 'other', short: 'OUTRO' },
        ] as const;

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          events: kinds.map((kind) =>
                              event({
                                  ulid: `event-${kind.type}`,
                                  type: kind.type,
                                  type_short_label: kind.short,
                                  starts_at: '09:05',
                                  ends_at: '10:35',
                              }),
                          ),
                      }
                    : {},
            ),
            itemsPerDay: 4,
        });

        for (const kind of kinds) {
            expect(
                wrapper.find(`[data-event-ulid="event-${kind.type}"]`).text(),
            ).toContain(`${kind.short} · 09:05`);
        }
    });

    /**
     * O «+N mais» abre a MESMA marcação, e por isso a hora vem com ela: um
     * acontecimento escondido atrás do limite do dia não é um acontecimento
     * diferente.
     */
    it('keeps the start time on the cards that «+N mais» reveals', async () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          assessments: [
                              assessment({ ulid: 'i1', title: 'Ficha A' }),
                              assessment({ ulid: 'i2', title: 'Ficha B' }),
                              assessment({ ulid: 'i3', title: 'Ficha C' }),
                          ],
                          events: [
                              event({
                                  ulid: 'escondido',
                                  title: 'Reunião escondida',
                                  starts_at: '18:00',
                                  ends_at: null,
                              }),
                          ],
                      }
                    : {},
            ),
        });

        await wrapper
            .find('[data-date="2026-10-15"] [data-overflow]')
            .trigger('click');

        expect(
            wrapper.find('[data-event-ulid="escondido"]').text(),
        ).toContain('REUNIÃO · 18:00');
    });

    it('gives each of the four kinds its own written label and its own icon', () => {
        const kinds = [
            { type: 'meeting', short: 'REUNIÃO' },
            { type: 'activity', short: 'ATIVIDADE' },
            { type: 'field_trip', short: 'VISITA' },
            { type: 'other', short: 'OUTRO' },
        ] as const;

        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          events: kinds.map((kind, index) =>
                              event({
                                  ulid: `event-${kind.type}`,
                                  type: kind.type,
                                  type_short_label: kind.short,
                                  title: `Acontecimento ${index}`,
                              }),
                          ),
                      }
                    : {},
            ),
            itemsPerDay: 4,
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');
        const icons = new Set<string>();

        for (const kind of kinds) {
            const entry = cell.find(`[data-event-ulid="event-${kind.type}"]`);

            expect(entry.exists()).toBe(true);
            expect(entry.text()).toContain(kind.short);
            expect(entry.attributes('data-event-type')).toBe(kind.type);
            icons.add(entry.find('svg').html());
        }

        // Quatro ícones diferentes: a cor nunca é o que separa os quatro.
        expect(icons.size).toBe(4);
    });

    /**
     * UMA AVALIAÇÃO CONTINUA A SER O TRATAMENTO MAIS FORTE DA PÁGINA. Nada do
     * que a Fase 5.3 acrescentou lhe faz sombra: a avaliação tem moldura sólida
     * e fundo cheio; um «outro», o mais neutro dos quatro, tem moldura pontuada
     * e texto esbatido.
     */
    it('keeps an avaliação heavier than every kind of acontecimento', () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          assessments: [assessment()],
                          events: [
                              event({ ulid: 'e-meeting', type: 'meeting' }),
                              event({
                                  ulid: 'e-other',
                                  type: 'other',
                                  type_short_label: 'OUTRO',
                              }),
                          ],
                      }
                    : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');
        const assessmentClasses = cell.find('a').classes().join(' ');
        const meetingClasses = cell
            .find('[data-event-ulid="e-meeting"]')
            .classes()
            .join(' ');
        const otherClasses = cell
            .find('[data-event-ulid="e-other"]')
            .classes()
            .join(' ');

        // A avaliação: moldura sólida, fundo cheio, peso de texto.
        expect(assessmentClasses).toContain('bg-background/80');
        expect(assessmentClasses).toContain('font-medium');
        expect(assessmentClasses).not.toContain('border-dashed');
        expect(assessmentClasses).not.toContain('border-dotted');

        // A reunião: peso intermédio — moldura sólida, mas mais leve.
        expect(meetingClasses).toContain('font-medium');
        expect(meetingClasses).not.toContain('border-dotted');

        // O «outro»: o mais neutro de todos.
        expect(otherClasses).toContain('border-dotted');
        expect(otherClasses).toContain('text-muted-foreground');
        expect(otherClasses).not.toContain('font-medium');
    });

    it('shows a multi-day acontecimento in every cell it covers', () => {
        const visit = event({
            ulid: 'visit',
            type: 'field_trip',
            type_short_label: 'VISITA',
            title: 'Visita a Évora',
            starts_on: '2026-10-14',
            ends_on: '2026-10-16',
        });

        const wrapper = mountPage({ days: octoberDays(covering(visit)) });

        for (const date of ['2026-10-14', '2026-10-15', '2026-10-16']) {
            expect(
                wrapper.find(`[data-date="${date}"] [data-event-ulid="visit"]`).exists(),
            ).toBe(true);
        }

        expect(
            wrapper.find('[data-date="2026-10-13"] [data-event-ulid="visit"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-date="2026-10-17"] [data-event-ulid="visit"]').exists(),
        ).toBe(false);
    });

    /**
     * UM SÓ MECANISMO DE EXCESSO. Um dia com duas avaliações e três
     * acontecimentos está exatamente tão cheio como um dia com cinco
     * avaliações, e o «+N mais» conta as duas espécies juntas — nunca um
     * segundo mecanismo ao lado do primeiro.
     */
    it('counts avaliações and acontecimentos together under the same cap', async () => {
        const wrapper = mountPage({
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          assessments: [
                              assessment({ ulid: 'i1', title: 'Ficha A' }),
                              assessment({ ulid: 'i2', title: 'Ficha B' }),
                          ],
                          events: [
                              event({ ulid: 'e1', title: 'Reunião A' }),
                              event({ ulid: 'e2', title: 'Reunião B' }),
                              event({ ulid: 'e3', title: 'Reunião C' }),
                          ],
                      }
                    : {},
            ),
        });

        let cell = wrapper.find('[data-date="2026-10-15"]');

        // Três de cinco, e a avaliação primeiro: é o tratamento mais forte.
        expect(cell.findAll('li')).toHaveLength(3);
        expect(cell.text()).toContain('Ficha A');
        expect(cell.text()).toContain('Ficha B');
        expect(cell.text()).toContain('Reunião A');
        expect(cell.text()).not.toContain('Reunião B');
        expect(cell.text()).toContain('+2 mais');

        await cell.find('[data-overflow]').trigger('click');

        cell = wrapper.find('[data-date="2026-10-15"]');
        expect(cell.findAll('li')).toHaveLength(5);
        expect(cell.text()).toContain('Reunião C');
    });

    it('lists acontecimentos in the narrow-viewport agenda too', () => {
        const wrapper = mountPage({
            days: octoberDays(
                covering(
                    event({
                        starts_at: '17:30',
                        ends_at: '19:00',
                        school_classes: [{ ulid: 'class-a', label: '7.º C' }],
                    }),
                ),
            ),
        });

        const agenda = wrapper.find('section[aria-label="Agenda do mês"]');

        expect(agenda.text()).toContain('Conselho de turma');
        expect(agenda.text()).toContain('REUNIÃO');
        expect(agenda.text()).toContain('17:30 – 19:00');
        expect(agenda.text()).toContain('7.º C');
    });

    it('says «dia inteiro» when an acontecimento carries no hour at all', () => {
        const wrapper = mountPage({
            days: octoberDays(covering(event({ title: 'Dia da escola' }))),
        });

        expect(wrapper.find('section[aria-label="Agenda do mês"]').text()).toContain(
            'Dia inteiro',
        );
    });

    // --------------------------------------------- criar, alterar, eliminar

    /**
     * O BOTÃO EXPLÍCITO EXISTE E BASTA-SE. Criar um acontecimento não está
     * escondido dentro de um clique num dia que seja preciso adivinhar: o botão
     * está sempre visível, fora da grelha, e abre o formulário sozinho.
     */
    it('offers an always-visible «Novo acontecimento» button that works with no day clicked', async () => {
        const wrapper = mountPage();

        const button = wrapper.find('[data-new-event]');

        expect(button.exists()).toBe(true);
        expect(button.text()).toContain('Novo acontecimento');
        // Fora da grelha: não depende de célula nenhuma.
        expect(wrapper.find('section[aria-label="Grelha do mês"] [data-new-event]').exists()).toBe(false);

        await button.trigger('click');
        await nextTick();

        expect(panelText()).toContain('Novo acontecimento');
        expect(panelText()).toContain('Adicionar ao calendário');
        // Sem dia carregado, a data proposta é o primeiro dia do mês visto.
        expect(panelInput('event-starts-on')?.value).toBe('2026-10-01');
    });

    it('pre-fills the day that was clicked in the grid', async () => {
        const wrapper = mountPage();

        await wrapper
            .find('[data-date="2026-10-15"] [data-add-on-day]')
            .trigger('click');
        await nextTick();

        expect(panelInput('event-starts-on')?.value).toBe('2026-10-15');
    });

    it('submits a new acontecimento to its own route', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-new-event]').trigger('click');
        await nextTick();

        document.body
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await nextTick();

        expect(formPost).toHaveBeenCalledWith(
            '/calendar/acontecimentos',
            expect.anything(),
        );
        expect(formPut).not.toHaveBeenCalled();
    });

    it('opens an existing acontecimento pre-filled, and saves it with put', async () => {
        const wrapper = mountPage({
            days: octoberDays(
                covering(
                    event({
                        title: 'Conselho de turma',
                        starts_on: '2026-10-14',
                        ends_on: '2026-10-16',
                        starts_at: '17:30',
                        ends_at: '19:00',
                    }),
                ),
            ),
        });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        expect(panelInput('event-title')?.value).toBe('Conselho de turma');
        expect(panelInput('event-starts-on')?.value).toBe('2026-10-14');
        expect(panelInput('event-ends-on')?.value).toBe('2026-10-16');
        expect(panelInput('event-starts-at')?.value).toBe('17:30');
        expect(panelInput('event-ends-at')?.value).toBe('19:00');
        expect(panelText()).toContain('Guardar alterações');

        document.body
            .querySelector('form')
            ?.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        await nextTick();

        expect(formPut).toHaveBeenCalledWith(
            '/calendar/acontecimentos/event-a',
            expect.anything(),
        );
        expect(formPost).not.toHaveBeenCalled();
    });

    it('leaves the end date empty for an acontecimento that lasts one day', async () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        expect(panelInput('event-starts-on')?.value).toBe('2026-10-15');
        expect(panelInput('event-ends-on')?.value).toBe('');
    });

    /**
     * A CONFIRMAÇÃO É DA APLICAÇÃO, e não do navegador: acontece dentro do
     * mesmo painel que já estava aberto — com o mesmo desenho das outras
     * confirmações destrutivas desta aplicação (ver PasskeyItem.vue) — e não
     * numa caixa cinzenta que não se parece com nada do resto da página.
     */
    it('asks inside its own panel before deleting, and sends nothing while it asks', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm');
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-delete-event]')
            ?.click();
        await nextTick();

        // A pergunta, no sítio do formulário, e com o nome verdadeiro lá dentro.
        expect(panelText()).toContain('Eliminar acontecimento?');
        expect(panelText()).toContain(
            '«Conselho de turma» será eliminada. As avaliações e a estrutura do ano letivo não serão afetadas.',
        );
        // Nenhum pedido partiu ainda, e nenhuma caixa do navegador foi aberta.
        expect(formDelete).not.toHaveBeenCalled();
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('returns to the form when the confirmation is refused, without ever asking the server', async () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-delete-event]')
            ?.click();
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-cancel-delete]')
            ?.click();
        await nextTick();

        // O formulário está de volta, tal como estava, e nada foi eliminado.
        expect(panelText()).not.toContain('Eliminar acontecimento?');
        expect(panelInput('event-title')?.value).toBe('Conselho de turma');
        expect(formDelete).not.toHaveBeenCalled();
    });

    it('deletes only once the teacher has confirmed inside the panel', async () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-delete-event]')
            ?.click();
        await nextTick();

        document.body
            .querySelector<HTMLButtonElement>('[data-confirm-delete]')
            ?.click();
        await nextTick();

        expect(formDelete).toHaveBeenCalledWith(
            '/calendar/acontecimentos/event-a',
            expect.anything(),
        );
    });

    it('never opens on the confirmation, however the panel was last left', async () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();
        document.body
            .querySelector<HTMLButtonElement>('[data-delete-event]')
            ?.click();
        await nextTick();
        expect(panelText()).toContain('Eliminar acontecimento?');

        // Reaberto — pelo botão explícito, que é outro caminho — está no
        // formulário e não a meio de uma pergunta que já ninguém fez.
        await wrapper.find('[data-new-event]').trigger('click');
        await nextTick();

        expect(panelText()).not.toContain('Eliminar acontecimento?');
        expect(panelText()).toContain('Adicionar ao calendário');
    });

    /**
     * SAIR TEM NOME. Fechar sem guardar é uma escolha a sério, e uma escolha a
     * sério tem um botão escrito — não só o X do canto, que continua a
     * funcionar e a fazer exatamente o mesmo.
     */
    it('closes the panel from an explicit «Cancelar», submitting nothing', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-new-event]').trigger('click');
        await nextTick();
        expect(panelText()).toContain('Adicionar ao calendário');

        const cancel = document.body.querySelector<HTMLButtonElement>(
            '[data-cancel-event]',
        );
        expect(cancel).not.toBeNull();
        expect(cancel?.textContent).toContain('Cancelar');

        cancel?.click();
        await nextTick();

        expect(formPost).not.toHaveBeenCalled();
        expect(formPut).not.toHaveBeenCalled();
        expect(formDelete).not.toHaveBeenCalled();
    });

    it('offers the same «Cancelar» when an existing acontecimento is being edited', async () => {
        const wrapper = mountPage({ days: octoberDays(covering(event())) });

        await wrapper.find('[data-event-ulid="event-a"]').trigger('click');
        await nextTick();

        expect(
            document.body.querySelector('[data-cancel-event]'),
        ).not.toBeNull();
        expect(panelText()).toContain('Guardar alterações');
    });

    /**
     * A COPY DO FORMULÁRIO não promete privacidade nenhuma: dizer «só tu o vês»
     * é uma promessa sobre uma versão futura que esta não pode garantir.
     */
    it('describes the form without promising who can see it', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-new-event]').trigger('click');
        await nextTick();

        expect(panelText()).toContain(
            'Regista uma reunião, atividade, visita de estudo ou outro acontecimento relevante.',
        );
        expect(panelText()).not.toContain('só tu o vês');
        expect(panelText()).not.toContain('É teu');
    });

    /**
     * O QUE ESTA PÁGINA ESCREVE, E O QUE NÃO ESCREVE. Só nasce daqui um
     * acontecimento. Não há botão de eliminar numa avaliação, e não há
     * formulário nenhum para os períodos do ano: essas coisas têm as suas
     * páginas, e continuam a tê-las.
     */
    it('offers no control at all that would create or change an avaliação or um período', () => {
        const wrapper = mountPage({
            periods: [period()],
            days: octoberDays((date) => ({
                period: period(),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        // Uma avaliação é um link para a sua própria página, e nada mais.
        const entry = wrapper.find('[data-date="2026-10-15"]').find('a');
        expect(entry.attributes('href')).toBe('/instruments/inst-a');
        expect(entry.element.tagName).toBe('A');

        // Nenhum formulário está aberto enquanto o painel não for pedido.
        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(document.body.querySelector('form')).toBeNull();

        // E a faixa do período continua a ser texto, sem controlo nenhum.
        const legend = wrapper.find('section[aria-label="Períodos deste mês"]');
        expect(legend.findAll('button')).toHaveLength(0);
        expect(legend.findAll('input')).toHaveLength(0);
    });

    // ------------------------- os dias em que não há aula (Fase 5.4)

    /**
     * A REGRA CENTRAL DESTA FASE, e a que a separa de um acontecimento de vários
     * dias: uma interrupção de onze dias é UMA coisa com onze dias, e não onze
     * coisas. Nomeia-se onde começa, e os restantes dias mostram-se pelo tom.
     */
    it('names a multi-day exception once, at the day it starts, and tints every day it covers', () => {
        const natal = exception({
            ulid: 'exception-natal',
            type: 'school_break',
            type_label: 'Interrupção letiva',
            type_short_label: 'INTERRUPÇÃO',
            title: 'Interrupção de Natal',
            starts_on: '2026-10-12',
            ends_on: '2026-10-22',
        });

        const wrapper = mountPage({
            exceptions: [natal],
            days: octoberDays(coveredBy(natal)),
        });

        // UMA vez nomeada dentro da grelha, e não onze.
        const markers = wrapper.findAll('[data-exception-start]');
        expect(markers).toHaveLength(1);
        expect(
            wrapper
                .find('[data-date="2026-10-12"]')
                .find('[data-exception-start]')
                .exists(),
        ).toBe(true);
        expect(
            wrapper
                .find('[data-date="2026-10-15"]')
                .find('[data-exception-start]')
                .exists(),
        ).toBe(false);

        // NUNCA SÓ A COR: a espécie vai escrita, e o título também.
        expect(markers[0]!.text()).toContain('INTERRUPÇÃO');
        expect(markers[0]!.text()).toContain('Interrupção de Natal');

        // E cada um dos onze dias fica tingido de não letivo.
        for (const date of ['2026-10-12', '2026-10-15', '2026-10-22']) {
            expect(
                wrapper.find(`[data-date="${date}"]`).classes().join(' '),
            ).toContain(EXCEPTION_DAY_TINT.split(' ')[0] as string);
        }

        // E o dia seguinte ao fim, não.
        expect(
            wrapper.find('[data-date="2026-10-23"]').classes().join(' '),
        ).not.toContain(EXCEPTION_DAY_TINT.split(' ')[0] as string);
    });

    it('reads the range once, above the grid, with its type and its dates', () => {
        const wrapper = mountPage({
            exceptions: [
                exception({
                    type: 'school_break',
                    type_label: 'Interrupção letiva',
                    title: 'Interrupção de Natal',
                    starts_on: '2026-10-12',
                    ends_on: '2026-10-22',
                }),
            ],
        });

        const band = wrapper.find('section[aria-label="Dias não letivos deste mês"]');

        expect(band.exists()).toBe(true);
        expect(band.text()).toContain('Interrupção de Natal');
        expect(band.text()).toContain('Interrupção letiva');
        expect(band.text()).toContain('12/10');
        expect(band.text()).toContain('22/10');

        // Estrutura, e não um controlo: não há aqui nada em que carregar.
        expect(band.findAll('button')).toHaveLength(0);
        expect(band.findAll('input')).toHaveLength(0);
    });

    it('writes a single-day feriado as one date and not as a range of itself', () => {
        const wrapper = mountPage({ exceptions: [exception()] });

        const band = wrapper.find('section[aria-label="Dias não letivos deste mês"]');

        expect(band.text()).toContain('Feriado');
        expect(band.text()).toContain('5/10');
        expect(band.text()).not.toContain('5/10 – 5/10');
    });

    it('says nothing at all about dias não letivos in a month that has none', () => {
        const wrapper = mountPage();

        expect(
            wrapper.find('section[aria-label="Dias não letivos deste mês"]').exists(),
        ).toBe(false);
        expect(wrapper.findAll('[data-exception-start]')).toHaveLength(0);
    });

    /**
     * UM DIA PODE SER DAS DUAS COISAS, e a regra é a do enunciado: para AQUELE
     * dia, o facto operacionalmente relevante é que não há aula. Que ele
     * pertença ao 1.º Semestre continua escrito na faixa por cima da grelha,
     * que é onde o período já se dizia de qualquer maneira.
     */
    it('lets the exception win the cell over the período it also falls in', () => {
        const semester = period({ starts_on: '2026-09-01', ends_on: '2026-12-18' });
        const feriado = exception({ starts_on: '2026-10-05', ends_on: '2026-10-05' });

        const wrapper = mountPage({
            periods: [semester],
            exceptions: [feriado],
            days: octoberDays((date) => ({
                period: semester,
                ...coveredBy(feriado)(date),
            })),
        });

        const nonTeaching = wrapper.find('[data-date="2026-10-05"]').classes().join(' ');
        const ordinary = wrapper.find('[data-date="2026-10-06"]').classes().join(' ');

        expect(nonTeaching).toContain(EXCEPTION_DAY_TINT.split(' ')[0] as string);
        expect(nonTeaching).not.toContain(periodDayTint(semester).split(' ')[0] as string);

        // E o dia ao lado, que é do período e de mais nada, continua com o tom
        // do período — a exceção não apaga a estrutura, só ganha ao seu dia.
        expect(ordinary).toContain(periodDayTint(semester).split(' ')[0] as string);

        // E o período continua nomeado por cima da grelha.
        expect(
            wrapper.find('section[aria-label="Períodos deste mês"]').text(),
        ).toContain('1.º Período');
    });

    /**
     * O QUE NUNCA PODE ACONTECER: um teste marcado num dia que passou a não
     * letivo é exatamente a coisa que o professor tem de ver. A exceção pinta o
     * fundo; a avaliação continua a ser a coisa com moldura, fundo e peso, e
     * está desenhada por cima.
     */
    it('never hides an avaliação or an acontecimento standing on a non-teaching day', () => {
        const feriado = exception({ starts_on: '2026-10-15', ends_on: '2026-10-15' });

        const wrapper = mountPage({
            exceptions: [feriado],
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? {
                          exception: feriado,
                          assessments: [assessment()],
                          events: [event()],
                      }
                    : {},
            ),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        expect(cell.text()).toContain('Teste de Frações');
        expect(cell.text()).toContain('Conselho de turma');
        expect(cell.find('a').attributes('href')).toBe('/instruments/inst-a');
        expect(cell.find('[data-event-ulid="event-a"]').exists()).toBe(true);
        // E a avaliação continua com o seu fundo próprio, que assenta POR CIMA
        // do tom da célula.
        expect(cell.find('a').classes().join(' ')).toContain('bg-background/80');
    });

    it('tells the narrow-screen agenda that a day with something marked is not a teaching day', () => {
        const feriado = exception({ starts_on: '2026-10-15', ends_on: '2026-10-15' });

        const wrapper = mountPage({
            exceptions: [feriado],
            days: octoberDays((date) =>
                date === '2026-10-15'
                    ? { exception: feriado, assessments: [assessment()] }
                    : {},
            ),
        });

        const marker = wrapper.find('[data-agenda-exception="exception-a"]');

        expect(marker.exists()).toBe(true);
        expect(marker.text()).toContain('Feriado');
        expect(marker.text()).toContain('Implantação da República');
    });

    /**
     * UMA EXCEÇÃO NÃO É UM ACONTECIMENTO, e a página não a deixa passar por um:
     * não é uma entrada da lista da célula, não abre o painel de acontecimentos
     * e não conta para o limite de itens por dia.
     */
    it('keeps an exception out of the day\'s item list and out of its overflow count', () => {
        const natal = exception({ starts_on: '2026-10-12', ends_on: '2026-10-22' });

        const wrapper = mountPage({
            exceptions: [natal],
            days: octoberDays((date) => ({
                ...coveredBy(natal)(date),
                ...(date === '2026-10-15' ? { assessments: [assessment()] } : {}),
            })),
        });

        const cell = wrapper.find('[data-date="2026-10-15"]');

        // Um item na lista, e é a avaliação — a exceção não está lá.
        expect(cell.findAll('ul > li')).toHaveLength(1);
        // E nenhum «+N mais» nasceu de a contar.
        expect(cell.find('[data-overflow]').exists()).toBe(false);
        // Nem há botão nenhum a abrir um painel a partir dela.
        expect(cell.find('[data-exception-start]').exists()).toBe(false);
    });
});
