import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as VueModule from 'vue';
import { nextTick } from 'vue';
import type {
    CalendarAssessment,
    CalendarDay,
    CalendarEvent,
    CalendarPeriod,
} from './calendar';
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

    it('names a período where it begins and where it changes, not in all thirty cells', () => {
        const first = period({ ulid: 'p1', label: '1.º Período', ends_on: '2026-10-10' });
        const second = period({ ulid: 'p2', label: '2.º Período', starts_on: '2026-10-11' });

        const wrapper = mountPage({
            periods: [first, second],
            days: octoberDays((date) => ({
                period: date <= '2026-10-10' ? first : second,
            })),
        });

        const named = wrapper
            .findAll('[data-date]')
            .filter((cell) => cell.text().includes('.º Período'))
            .map((cell) => cell.attributes('data-date'));

        // The first cell of the grid, and the day the band changes. Nowhere else.
        expect(named).toEqual(['2026-09-28', '2026-10-11']);
    });

    it('leaves a day in a gap between períodos honestly unbanded', () => {
        const wrapper = mountPage({
            periods: [period({ ends_on: '2026-10-10' })],
            days: octoberDays((date) => ({
                period: date <= '2026-10-10' ? period({ ends_on: '2026-10-10' }) : null,
            })),
        });

        expect(wrapper.find('[data-date="2026-10-20"]').text()).not.toContain('Período');
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
});
