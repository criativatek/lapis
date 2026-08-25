import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { CalendarPeriod } from './calendar';
import { PERIOD_TINT } from './calendar';
import Year from './Year.vue';

/**
 * As cores do arco-íris por índice de período, que ESTA fase apagou. Ficam aqui
 * escritas para se poder afirmar que desapareceram: um mês inteiramente dentro
 * de um período continua a ter tom, mas é sempre o MESMO tom discreto, e nunca
 * uma cor tirada da ordem em que o período calhou vir.
 */
const RAINBOW = [
    'bg-sky-100/70',
    'bg-amber-100/70',
    'bg-emerald-100/70',
    'bg-violet-100/70',
    'bg-rose-100/70',
    'bg-teal-100/70',
];

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

type YearPeriod = CalendarPeriod & { assessments_count: number };

function period(overrides: Partial<YearPeriod> = {}): YearPeriod {
    return {
        ulid: 'period-1',
        label: '1.º Período',
        kind: 'term',
        kind_label: 'Período',
        sequence: 1,
        starts_on: '2026-09-01',
        ends_on: '2026-12-18',
        assessments_count: 0,
        ...overrides,
    };
}

/** O último dia real do mês, tal como o servidor o envia. */
function lastDayOf(value: string): string {
    const [year, monthNumber] = value.split('-').map(Number) as [number, number];

    return new Date(Date.UTC(year, monthNumber, 0)).toISOString().slice(0, 10);
}

function month(value: string, overrides: Record<string, unknown> = {}) {
    return {
        value,
        starts_on: `${value}-01`,
        ends_on: lastDayOf(value),
        assessments_count: 0,
        events_count: 0,
        period_ulids: [] as string[],
        is_current: false,
        ...overrides,
    };
}

function mountPage(overrides: Partial<InstanceType<typeof Year>['$props']> = {}) {
    return mount(Year, {
        props: {
            academicYear: {
                ulid: 'year-a',
                label: '2026/2027',
                starts_on: '2026-09-01',
                ends_on: '2027-07-31',
            },
            months: [month('2026-09'), month('2026-10'), month('2026-11')],
            periods: [],
            periodsCountLabel: '0 períodos',
            assessmentsTotal: 0,
            eventsTotal: 0,
            ...overrides,
        },
    });
}

/**
 * «Calendário do Ano Letivo», vista Ano — the whole year at a glance: its
 * períodos as bands, and how many avaliações fall in each month and in each
 * band. A SYNOPSIS AND NOT A LIST, and never a day grid: the itemized listing
 * already exists elsewhere, and a hundred rows here would bury the one thing
 * this view is for. Aulas do not appear, here as anywhere in this calendar.
 */
describe('calendar/Year', () => {
    it('lays out every month of the year, each linking into its own Mês view', () => {
        const wrapper = mountPage();

        const tiles = wrapper.findAll('[data-month]');

        expect(tiles.map((tile) => tile.attributes('data-month'))).toEqual([
            '2026-09',
            '2026-10',
            '2026-11',
        ]);
        expect(tiles[1]!.attributes('href')).toBe('/calendar?month=2026-10');
    });

    it('writes each month with only its first letter raised', () => {
        const text = mountPage().text();

        expect(text).toContain('Setembro de 2026');
        expect(text).not.toContain('Setembro De 2026');
    });

    it('shows the períodos as bands with their kind, range and count', () => {
        const wrapper = mountPage({
            periods: [
                period({ assessments_count: 3 }),
                period({
                    ulid: 'period-2',
                    label: '2.º Período',
                    sequence: 2,
                    starts_on: '2027-01-05',
                    ends_on: '2027-04-02',
                    assessments_count: 1,
                }),
            ],
            periodsCountLabel: '2 períodos',
            assessmentsTotal: 4,
        });

        const bands = wrapper.find('section[aria-label="Períodos do ano letivo"]');

        expect(bands.text()).toContain('1.º Período');
        expect(bands.text()).toContain('2.º Período');
        expect(bands.text()).toContain('Período');
        expect(bands.text()).toContain('3 avaliações');
        expect(bands.text()).toContain('1 avaliação');
        expect(wrapper.text()).toContain('2 períodos');
        expect(wrapper.text()).toContain('4 avaliações');
    });

    it('counts a month\'s avaliações instead of listing them', () => {
        const wrapper = mountPage({
            months: [
                month('2026-09'),
                month('2026-10', { assessments_count: 4, period_ulids: ['period-1'] }),
            ],
            periods: [period({ assessments_count: 4 })],
            assessmentsTotal: 4,
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.text()).toContain('4 avaliações');
        expect(october.text()).toContain('1.º Período');
        expect(wrapper.find('[data-month="2026-09"]').text()).toContain(
            'Sem avaliações',
        );
    });

    it('marks a month sitting in two períodos with both', () => {
        const first = period();
        const second = period({
            ulid: 'period-2',
            label: '2.º Período',
            sequence: 2,
            starts_on: '2026-10-20',
            ends_on: '2026-12-18',
        });

        const wrapper = mountPage({
            months: [month('2026-10', { period_ulids: ['period-1', 'period-2'] })],
            periods: [first, second],
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.text()).toContain('1.º Período');
        expect(october.text()).toContain('2.º Período');
    });

    /**
     * «2 SEMESTRES», E NÃO «2 PERÍODOS». Quantos são e de que espécie são é uma
     * pergunta cuja resposta está escrita no `kind` de cada período, e é lá que
     * ela é respondida — no servidor. Esta página escreve o que lhe é dado e
     * não volta a derivar o plural a partir do número, porque derivá-lo aqui
     * seria voltar a dizer «períodos» a um ano que tem semestres.
     */
    it('writes the server\'s own count of the year\'s períodos, verbatim', () => {
        const semesters = mountPage({
            periods: [
                period({
                    ulid: 'sem-1',
                    label: '1.º Semestre',
                    kind: 'semester',
                    kind_label: 'Semestre',
                    starts_on: '2026-09-11',
                    ends_on: '2027-01-29',
                }),
                period({
                    ulid: 'sem-2',
                    label: '2.º Semestre',
                    kind: 'semester',
                    kind_label: 'Semestre',
                    sequence: 2,
                    starts_on: '2027-02-11',
                    ends_on: '2027-08-31',
                }),
            ],
            periodsCountLabel: '2 semestres',
        });

        expect(semesters.text()).toContain('2 semestres');
        expect(semesters.text()).not.toContain('2 períodos');

        // E a mesma página escreve «3 períodos» quando é isso que lhe dizem: o
        // que muda é o dado, e nunca uma regra escondida aqui.
        expect(mountPage({ periodsCountLabel: '3 períodos' }).text()).toContain(
            '3 períodos',
        );
    });

    // -------------------------------------------------- os meses de transição

    /**
     * UM MÊS DE TRANSIÇÃO NÃO É DE NINGUÉM. Setembro, num ano de semestres que
     * abre a 11 de setembro, aparecia pintado de uma ponta à outra com a cor do
     * 1.º Semestre — o que é falso sobre os dez primeiros dias do mês, e é a
     * mesma tinta que apagava o intervalo entre dois períodos.
     */
    it('never paints a whole month in the colour of a período that only covers part of it', () => {
        const semester = period({
            ulid: 'sem-1',
            label: '1.º Semestre',
            kind: 'semester',
            kind_label: 'Semestre',
            starts_on: '2026-09-11',
            ends_on: '2027-01-29',
        });

        const wrapper = mountPage({
            months: [
                month('2026-09', { period_ulids: ['sem-1'] }),
                month('2026-10', { period_ulids: ['sem-1'] }),
                month('2027-01', { period_ulids: ['sem-1'] }),
            ],
            periods: [semester],
            periodsCountLabel: '1 semestre',
        });

        const september = wrapper.find('[data-month="2026-09"]');
        const october = wrapper.find('[data-month="2026-10"]');
        const january = wrapper.find('[data-month="2027-01"]');

        // Setembro: o semestre só abre a 11, e o cartão di-lo em vez de o pintar.
        expect(september.classes().join(' ')).not.toContain(PERIOD_TINT);
        expect(september.text()).toContain('1.º Semestre desde 11/09');

        // Outubro está inteiro dentro dele: o caso simples, tal e qual como era.
        expect(october.classes().join(' ')).toContain(PERIOD_TINT);
        expect(october.text()).toContain('1.º Semestre');
        expect(october.text()).not.toContain('desde');
        expect(october.text()).not.toContain('até');

        // Janeiro: o semestre fecha a 29, e o que vem depois não é dele.
        expect(january.classes().join(' ')).not.toContain(PERIOD_TINT);
        expect(january.text()).toContain('1.º Semestre até 29/01');
    });

    /**
     * O ARCO-ÍRIS FOI-SE EMBORA. A estrutura do ano tem de estar visível sem
     * mandar na página, e um fundo tirado da ordem em que cada período calhou
     * vir pintava o calendário inteiro de azul só porque se estava a meio do
     * 1.º Semestre. Fica UM tom, quente e discreto, o mesmo em toda a parte —
     * nos cartões dos meses e nas faixas dos períodos, aqui e na vista de Mês.
     */
    it('tints every structural surface in the same quiet tone, never in a per-período colour', () => {
        const wrapper = mountPage({
            months: [
                month('2026-10', { period_ulids: ['p1'] }),
                month('2027-03', { period_ulids: ['p2'] }),
            ],
            periods: [
                period({ ulid: 'p1' }),
                period({
                    ulid: 'p2',
                    label: '2.º Período',
                    sequence: 2,
                    starts_on: '2027-01-05',
                    ends_on: '2027-04-30',
                }),
            ],
            periodsCountLabel: '2 períodos',
        });

        // Os dois meses inteiramente dentro de um período: o MESMO tom, e não um
        // por índice de período.
        expect(wrapper.find('[data-month="2026-10"]').classes().join(' ')).toContain(
            PERIOD_TINT,
        );
        expect(wrapper.find('[data-month="2027-03"]').classes().join(' ')).toContain(
            PERIOD_TINT,
        );

        // As faixas da lista de períodos, acima da grelha: o mesmo tom outra vez.
        const rows = wrapper.findAll('section[aria-label="Períodos do ano letivo"] > div');

        expect(rows).toHaveLength(2);

        for (const row of rows) {
            expect(row.classes().join(' ')).toContain(PERIOD_TINT);
        }

        // E nenhuma das seis cores antigas em lado nenhum da página.
        for (const tint of RAINBOW) {
            expect(wrapper.html()).not.toContain(tint);
        }
    });

    /**
     * O TOM ESCOLHIDO NÃO É O DO ÂMBAR, e não é por acaso: o âmbar já é da
     * «Visita de estudo» e do próprio `--brand-amber` da aplicação, e a estrutura
     * do ano a usá-lo faria as duas coisas colidirem. É um cinzento-quente —
     * `stone` — e a cor nunca é o que diz qual é o período: o nome está sempre
     * escrito ao lado dela.
     */
    it('keeps the structural tone off the amber that «Visita de estudo» already owns', () => {
        expect(PERIOD_TINT).toContain('stone');
        expect(PERIOD_TINT).not.toContain('amber');
        expect(PERIOD_TINT).not.toContain('orange');
    });

    it('says where the next período begins in the month it begins in', () => {
        const second = period({
            ulid: 'sem-2',
            label: '2.º Semestre',
            kind: 'semester',
            kind_label: 'Semestre',
            sequence: 2,
            starts_on: '2027-02-11',
            ends_on: '2027-08-31',
        });

        const wrapper = mountPage({
            months: [
                month('2027-02', { period_ulids: ['sem-2'] }),
                month('2027-03', { period_ulids: ['sem-2'] }),
            ],
            periods: [second],
            periodsCountLabel: '2 semestres',
        });

        const february = wrapper.find('[data-month="2027-02"]');

        expect(february.classes().join(' ')).not.toContain(PERIOD_TINT);
        expect(february.text()).toContain('2.º Semestre desde 11/02');
        // E março, inteiramente dentro dele, volta a ser o caso simples.
        expect(wrapper.find('[data-month="2027-03"]').classes().join(' ')).toContain(
            PERIOD_TINT,
        );
    });

    it('leaves a month touched by two períodos in neither of their colours', () => {
        const first = period({ ulid: 'p1', ends_on: '2026-10-10' });
        const second = period({
            ulid: 'p2',
            label: '2.º Período',
            sequence: 2,
            starts_on: '2026-10-20',
            ends_on: '2026-12-18',
        });

        const wrapper = mountPage({
            months: [month('2026-10', { period_ulids: ['p1', 'p2'] })],
            periods: [first, second],
            periodsCountLabel: '2 períodos',
        });

        const october = wrapper.find('[data-month="2026-10"]');
        const classes = october.classes().join(' ');

        expect(classes).not.toContain(PERIOD_TINT);
        // As duas metades do mês, ditas por extenso, e o intervalo entre elas
        // deixado por dizer em vez de ser pintado de uma cor qualquer.
        expect(october.text()).toContain('1.º Período até 10/10');
        expect(october.text()).toContain('2.º Período desde 20/10');
    });

    it('writes both ends when a período begins and ends inside the same month', () => {
        const brief = period({
            ulid: 'p-brief',
            label: 'Módulo A',
            kind: 'module',
            kind_label: 'Módulo',
            starts_on: '2026-10-05',
            ends_on: '2026-10-23',
        });

        const wrapper = mountPage({
            months: [month('2026-10', { period_ulids: ['p-brief'] })],
            periods: [brief],
            periodsCountLabel: '1 módulo',
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.classes().join(' ')).not.toContain(PERIOD_TINT);
        expect(october.text()).toContain('Módulo A de 5/10 a 23/10');
    });

    it('says nothing at all about períodos in a month no período touches', () => {
        const wrapper = mountPage({
            months: [month('2027-02')],
            periods: [period({ ends_on: '2026-12-18' })],
            periodsCountLabel: '1 período',
        });

        const february = wrapper.find('[data-month="2027-02"]');

        expect(february.classes().join(' ')).not.toContain(PERIOD_TINT);
        expect(february.text()).not.toContain('Período');
        expect(february.text()).not.toContain('desde');
    });

    it('distinguishes a count of avaliações from a período band by more than colour', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
            assessmentsTotal: 2,
        });

        // The count carries a border, an icon and emphasis; the band around it
        // carries none of the three.
        const badge = wrapper
            .find('[data-month="2026-10"]')
            .findAll('span')
            .find((span) => span.classes().join(' ').includes('border'));

        expect(badge).toBeTruthy();
        expect(badge!.classes().join(' ')).toContain('font-medium');
        expect(badge!.find('svg').exists()).toBe(true);
    });

    /**
     * OS ACONTECIMENTOS ENTRAM PELA MESMA REGRA da Fase 5.2 — uma contagem
     * compacta por mês, e nunca a lista. E distinguem-se de uma contagem de
     * avaliações por ícone E por palavra, nunca só por posição ou cor.
     */
    it('counts a month\'s acontecimentos compactly, beside the avaliações and never mixed with them', () => {
        const wrapper = mountPage({
            months: [
                month('2026-09'),
                month('2026-10', {
                    assessments_count: 2,
                    events_count: 3,
                    period_ulids: ['period-1'],
                }),
                month('2026-11', { events_count: 1 }),
            ],
            periods: [period({ assessments_count: 2 })],
            assessmentsTotal: 2,
            eventsTotal: 4,
        });

        const october = wrapper.find('[data-month="2026-10"]');

        expect(october.text()).toContain('2 avaliações');
        expect(october.text()).toContain('3 acontecimentos');

        // Um mês com acontecimentos e sem avaliações diz honestamente as duas
        // coisas, em vez de deixar uma delas por dizer.
        const november = wrapper.find('[data-month="2026-11"]');
        expect(november.text()).toContain('Sem avaliações');
        expect(november.text()).toContain('1 acontecimento');

        // E um mês sem nada continua a não inventar nada.
        expect(wrapper.find('[data-month="2026-09"]').text()).toContain('Sem avaliações');
        expect(wrapper.find('[data-month="2026-09"] [data-events-count]').exists()).toBe(false);

        expect(wrapper.text()).toContain('4 acontecimentos');
    });

    it('distinguishes a count of acontecimentos from a count of avaliações by icon and by word', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 2, events_count: 3 })],
            assessmentsTotal: 2,
            eventsTotal: 3,
        });

        const badge = wrapper.find('[data-month="2026-10"] [data-events-count]');

        expect(badge.exists()).toBe(true);
        expect(badge.attributes('data-events-count')).toBe('3');
        expect(badge.text()).toContain('3 acontecimentos');
        expect(badge.find('svg').exists()).toBe(true);
    });

    it('never names an acontecimento at this scale, only counts them', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { events_count: 12 })],
            eventsTotal: 12,
        });

        expect(wrapper.text()).toContain('12 acontecimentos');
        expect(wrapper.findAll('[data-event-ulid]')).toHaveLength(0);
        expect(wrapper.findAll('[data-date]')).toHaveLength(0);
    });

    it('never draws a day grid and never itemizes the avaliações', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 12, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 12 })],
            assessmentsTotal: 12,
        });

        // A synopsis: no per-day cells anywhere, and a written count.
        expect(wrapper.findAll('[data-date]')).toHaveLength(0);
        expect(wrapper.text()).toContain('12 avaliações');
    });

    /**
     * OS TRAÇOS FORAM-SE EMBORA. Desenhavam, com um tecto de seis, o mesmo
     * número que já estava escrito a um centímetro deles — não eram uma
     * repartição por período nem por espécie, não eram nada que o número não
     * dissesse melhor.
     */
    it('draws no decorative tick marks beside a count it has already written', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 12, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 12 })],
            assessmentsTotal: 12,
        });

        expect(
            wrapper.findAll('[data-month="2026-10"] span[aria-hidden="true"] span'),
        ).toHaveLength(0);
        expect(wrapper.html()).not.toContain('bg-foreground/50');
        expect(wrapper.text()).toContain('12 avaliações');
    });

    it('links back to the Mês view, which is an address and not a toggle', () => {
        const hrefs = mountPage()
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(hrefs).toContain('/calendar');
    });

    it('still lays out the months for a year with no períodos', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('[data-month]')).toHaveLength(3);
        expect(wrapper.text()).toContain('ainda não tem períodos definidos');
    });

    it('explains itself instead of drawing months when there is no academic year at all', () => {
        const wrapper = mountPage({ academicYear: null, months: [], periods: [] });

        expect(wrapper.text()).toContain('Ainda não há um ano letivo para mostrar');
        expect(wrapper.findAll('[data-month]')).toHaveLength(0);
    });

    it('never renders a form or a mutating control: the page is a reading', () => {
        const wrapper = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
        });

        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.findAll('input')).toHaveLength(0);
    });

    it('shows nothing whatsoever about aulas', () => {
        const text = mountPage({
            months: [month('2026-10', { assessments_count: 2, period_ulids: ['period-1'] })],
            periods: [period({ assessments_count: 2 })],
            assessmentsTotal: 2,
        }).text();

        expect(text).not.toContain('aula');
        expect(text).not.toContain('Aula');
        expect(text).not.toContain('horário');
        expect(text).not.toContain('Horário');
    });
});
