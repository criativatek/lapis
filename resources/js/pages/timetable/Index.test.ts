import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';
import {
    assignTurmaTones,
    TURMA_BADGE,
    TURMA_BAR,
    TURMA_TONES,
} from './timetable';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
}));

type TimetableSlot = {
    ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string | null;
    ends_on: string | null;
    school_class: { ulid: string; label: string };
    subject: string;
};

type ClassOption = { ulid: string; label: string; subject: string };

function slot(overrides: Partial<TimetableSlot> = {}): TimetableSlot {
    return {
        ulid: 'slot-a',
        day_of_week: 1,
        starts_at: '08:30',
        ends_at: '09:20',
        starts_on: null,
        ends_on: null,
        school_class: { ulid: 'class-a', label: '7.º C' },
        subject: 'Matemática',
        ...overrides,
    };
}

function mountPage(slots: TimetableSlot[], classes: ClassOption[] = []) {
    return mount(Index, { props: { slots, classes } });
}

/**
 * «Horário do Professor» — the teacher's whole week read in one place, plus
 * the two existing, unmodified ways of filling it in. Nothing here writes: the
 * page has no form and no mutating link, and the assertions below are about
 * what it SHOWS — the grouping by weekday, the empty state, and the two entry
 * points pointing at the real routes.
 */
const week = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.find('section[aria-label="Horário semanal"]');

const agenda = (wrapper: ReturnType<typeof mountPage>) =>
    wrapper.find('section[aria-label="Agenda da semana"]');

const labelsOf = (root: ReturnType<typeof week>) =>
    root.findAll('section[aria-label]').map((s) => s.attributes('aria-label'));

// --------------------------------------------------- o tom de cada turma

/** Os blocos desenhados dentro de uma leitura da semana. */
const blocksOf = (root: ReturnType<typeof week>) => root.findAll('li a');

type Block = ReturnType<typeof blocksOf>[number];

/** A barra vive no próprio bloco; a cápsula, no primeiro <span> lá dentro. */
const barOf = (block: Block) => block.classes();
const badgeOf = (block: Block) => block.find('span').classes();

/**
 * Toda e qualquer classe de tom que existe, seja de que turma for — a lista de
 * que uma coisa «sem tom» tem de estar inteiramente livre. Sai dos próprios
 * mapas exportados, e não de hexadecimais copiados para aqui: o que se afirma é
 * que há ou não há tom, nunca qual é o azul.
 */
const everyAccentClass = [
    'border-l-2',
    ...TURMA_TONES.flatMap((tone) =>
        `${TURMA_BAR[tone]} ${TURMA_BADGE[tone]}`.split(' '),
    ),
];

const expectNoAccent = (classes: string[]) => {
    for (const accent of everyAccentClass) {
        expect(classes).not.toContain(accent);
    }
};

/**
 * Uma semana com uma turma por bloco — o mínimo para poder perguntar que tons a
 * página deu a um conjunto de turmas. O rótulo é o próprio `ulid`, para o tom
 * desenhado poder ser lido de volta e atribuído à turma certa.
 */
function weekOfTurmas(ulids: string[]) {
    return mountPage(
        ulids.map((ulid, index) =>
            slot({
                ulid: `slot-${index}`,
                day_of_week: 1,
                school_class: { ulid, label: ulid },
            }),
        ),
    );
}

/** O tom que a página desenhou para cada turma, lido dos próprios blocos. */
function tonesByTurma(
    wrapper: ReturnType<typeof mountPage>,
): Map<string, string> {
    const tones = new Map<string, string>();

    for (const block of blocksOf(week(wrapper))) {
        const badge = block.find('span');

        tones.set(badge.text(), badge.classes().sort().join(' '));
    }

    return tones;
}

const badgeToneOf = (block: Block) => badgeOf(block).sort().join(' ');
const barToneOf = (block: Block) => barOf(block).sort().join(' ');

/**
 * CINCO TURMAS QUE COLIDIAM. Estes cinco `ulid` caem, na dispersão que antes
 * decidia o tom, nas gavetas 1, 2, 3, 1 e 5: a primeira e a quarta turma
 * ficavam com a MESMA cor enquanto duas das seis não eram usadas por ninguém.
 * É a forma exata do que apareceu na revisão manual — o 7.º D e o 7.º E com o
 * mesmo tom — e é por isso que estão aqui escritos e não gerados ao acaso.
 */
const COLLIDING_UNDER_THE_OLD_HASH = [
    '01K3QF8ZP0JBSFK1VZXDCKTMXC',
    '01K3QF8ZP0NND4FRJ7CMXYTVZT',
    '01K3QF8ZP0GQQG8W9EDH2HWGT0',
    '01K3QF8ZP0N5KEM7R6WNRZN9XH',
    '01K3QF8ZP0WPQE5R2PNDWG8XSH',
];

describe('timetable/Index', () => {
    it('groups the week by weekday, in Portuguese, only for the days actually taught', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 3, starts_at: '11:00', ends_at: '11:50' }),
        ]);

        const headings = agenda(wrapper)
            .findAll('h2')
            .map((heading) => heading.text());

        expect(headings).toContain('Segunda-feira');
        expect(headings).toContain('Quarta-feira');
        // In the narrow-screen agenda a day with no aulas is not a line: there
        // is no week to read at a glance on a phone, only a list, and an empty
        // Saturday is not information.
        expect(headings).not.toContain('Terça-feira');
        expect(headings).not.toContain('Sábado');
    });

    // ------------------------------------------- a semana em ecrã largo

    /**
     * A SEXTA-FEIRA NUNCA CAI PARA A LINHA DE BAIXO. Com aulas à segunda,
     * terça, quarta e sexta, um número de colunas que acompanhasse a largura
     * do ecrã punha a sexta sozinha numa segunda linha só porque a quinta
     * estava vazia — e uma semana lida assim deixa de ser uma semana.
     */
    it('holds all five weekdays in place, in order, however wide the ecrã is', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        expect(labelsOf(week(wrapper))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Quinta-feira',
            'Sexta-feira',
        ]);

        // Five columns, fixed — not a responsive count that can wrap.
        const grid = week(wrapper).find('div.grid');

        expect(grid.classes().filter((c) => c.includes('grid-cols'))).toEqual([
            'grid-cols-5',
        ]);
    });

    it('says «Sem aulas» on a free weekday instead of dropping the column', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        const thursday = week(wrapper).find('section[aria-label="Quinta-feira"]');

        expect(thursday.exists()).toBe(true);
        expect(thursday.text()).toContain('Sem aulas');
    });

    /**
     * O DIA VAZIO É SÓ UM RÓTULO. Não há bloco nenhum por trás dele, nem passa
     * a haver por ser mostrado: a página continua a ser uma leitura.
     */
    it('invents no aula behind an empty weekday: the placeholder is a label and nothing else', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        // Four blocks sent, four blocks drawn — the fifth column adds none.
        expect(week(wrapper).findAll('li')).toHaveLength(4);

        const thursday = week(wrapper).find('section[aria-label="Quinta-feira"]');

        expect(thursday.findAll('li')).toHaveLength(0);
        expect(thursday.findAll('a')).toHaveLength(0);
        expect(thursday.findAll('form')).toHaveLength(0);
        expect(thursday.findAll('button')).toHaveLength(0);
    });

    it('keeps a rare sábado visible, in a row of its own, and never a column reserved for it', () => {
        const withSaturday = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 's', day_of_week: 6, starts_at: '09:00', ends_at: '09:50' }),
        ]);

        expect(labelsOf(week(withSaturday))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Quinta-feira',
            'Sexta-feira',
            'Sábado',
        ]);
        expect(
            week(withSaturday).find('section[aria-label="Sábado"]').findAll('li'),
        ).toHaveLength(1);
        // The weekend never shares the guaranteed five-column row.
        expect(week(withSaturday).findAll('div.grid')).toHaveLength(2);

        // With no aulas on it, o sábado simply is not there.
        expect(week(mountPage([slot()])).text()).not.toContain('Sábado');
    });

    // ------------------------------------------ a semana em ecrã estreito

    it('still lists only the days actually taught in ecrã estreito, with no «Sem aulas» filler', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({ ulid: 'c', day_of_week: 3 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        expect(labelsOf(agenda(wrapper))).toEqual([
            'Segunda-feira',
            'Terça-feira',
            'Quarta-feira',
            'Sexta-feira',
        ]);
        expect(agenda(wrapper).text()).not.toContain('Sem aulas');
        expect(agenda(wrapper).text()).not.toContain('Quinta-feira');
    });

    it('keeps every block of a day together, in the order the server sent them', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1, starts_at: '08:30', ends_at: '09:20' }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                ends_at: '11:20',
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
            slot({ ulid: 'c', day_of_week: 5, starts_at: '14:00', ends_at: '14:50' }),
        ]);

        const monday = wrapper.find('section[aria-label="Segunda-feira"]');

        expect(monday.exists()).toBe(true);
        expect(monday.findAll('li')).toHaveLength(2);
        expect(monday.text()).toContain('08:30–09:20');
        expect(monday.text()).toContain('10:30–11:20');
        expect(monday.text()).toContain('7.º C');
        expect(monday.text()).toContain('8.º A');

        expect(wrapper.find('section[aria-label="Sexta-feira"]').findAll('li')).toHaveLength(1);
    });

    it('shows the turma and the disciplina of each block, and never a sala', () => {
        const wrapper = mountPage([slot()]);

        expect(wrapper.text()).toContain('7.º C');
        expect(wrapper.text()).toContain('Matemática');
        // There is no room column anywhere in the schema, so there is nothing
        // honest to show — and nothing invented here either.
        expect(wrapper.text()).not.toContain('Sala');
    });

    // ------------------------------------------------- o tom de cada turma

    /**
     * NUNCA DUAS TURMAS COM A MESMA COR ENQUANTO HOUVER UMA COR POR USAR. O tom
     * saía de uma dispersão do `ulid` sozinho, e com cinco turmas na página duas
     * delas caíam na mesma gaveta — o 7.º D e o 7.º E com a mesma cor — enquanto
     * duas das seis não eram usadas por ninguém. Era estável e determinístico, e
     * não servia para o que o sinal existe: separar turmas.
     */
    it('gives five turmas that used to collide five tones of their own', () => {
        const blocks = blocksOf(week(weekOfTurmas(COLLIDING_UNDER_THE_OLD_HASH)));

        expect(blocks).toHaveLength(5);

        // Cinco turmas, cinco cápsulas diferentes e cinco barras diferentes.
        expect(new Set(blocks.map(badgeToneOf)).size).toBe(5);
        expect(new Set(blocks.map(barToneOf)).size).toBe(5);
    });

    /**
     * E A REGRA VALE PARA QUALQUER NÚMERO ATÉ AO TAMANHO DA PALETA, e não só
     * para o caso que a revisão manual apanhou.
     */
    it('never repeats a tone while the visible turmas still fit the palette', () => {
        for (let count = 1; count <= TURMA_TONES.length; count += 1) {
            const ulids = Array.from(
                { length: count },
                (_, index) => `class-${index}`,
            );
            const blocks = blocksOf(week(weekOfTurmas(ulids)));

            expect(blocks).toHaveLength(count);
            expect(new Set(blocks.map(badgeToneOf)).size).toBe(count);
        }
    });

    /**
     * PASSADAS AS SEIS, REPETIR É INEVITÁVEL — e só então. O que continua a não
     * poder acontecer é uma cor por estrear enquanto duas turmas partilham
     * outra.
     */
    it('repeats a tone only once the palette is spent, never leaving one unused', () => {
        const ulids = Array.from(
            { length: TURMA_TONES.length + 2 },
            (_, index) => `class-${index}`,
        );
        const blocks = blocksOf(week(weekOfTurmas(ulids)));

        expect(blocks).toHaveLength(TURMA_TONES.length + 2);
        // Oito turmas, seis tons: os seis usados, e só dois repetidos.
        expect(new Set(blocks.map(badgeToneOf)).size).toBe(TURMA_TONES.length);
    });

    /**
     * A MESMA TURMA, O MESMO TOM, SEMPRE — para o mesmo conjunto de turmas
     * visíveis. O tom é decidido sobre os `ulid` ORDENADOS, e não sobre a ordem
     * em que os blocos chegam: a mesma semana com as aulas noutra ordem, ou
     * espalhada por outros dias, dá exatamente o mesmo mapa. Se dependesse da
     * ordem da lista, bastava marcar mais uma aula à segunda-feira para as
     * turmas todas trocarem de cor.
     */
    it('decides the same tones for the same turmas, however the blocks arrive', () => {
        const forward = tonesByTurma(weekOfTurmas(COLLIDING_UNDER_THE_OLD_HASH));
        const backward = tonesByTurma(
            weekOfTurmas([...COLLIDING_UNDER_THE_OLD_HASH].reverse()),
        );

        expect(backward).toEqual(forward);
        expect(forward.size).toBe(5);
    });

    it('decides the same tones however the week spreads the turmas over the days', () => {
        const ulids = COLLIDING_UNDER_THE_OLD_HASH;

        const allOnMonday = tonesByTurma(weekOfTurmas(ulids));
        const oneADay = tonesByTurma(
            mountPage(
                // A mesma turma noutro dia, e os dias por ordem inversa.
                ulids.map((ulid, index) =>
                    slot({
                        ulid: `slot-${index}`,
                        day_of_week: ulids.length - index,
                        school_class: { ulid, label: ulid },
                    }),
                ),
            ),
        );

        expect(oneADay).toEqual(allOnMonday);
    });

    /**
     * E DUAS RENDERIZAÇÕES DA MESMA PÁGINA dizem sempre o mesmo: a decisão é uma
     * função pura do conjunto de `ulid`s, sem estado nenhum entre montagens.
     */
    it('paints a turma in the same tone in two renderings of the same week', () => {
        const week1 = mountPage([slot({ ulid: 'a', day_of_week: 1 })]);
        const week2 = mountPage([slot({ ulid: 'a', day_of_week: 1 })]);

        const first = blocksOf(week(week1))[0] as Block;
        const again = blocksOf(week(week2))[0] as Block;

        expect(barOf(first)).toEqual(barOf(again));
        expect(badgeOf(first)).toEqual(badgeOf(again));
    });

    it('tells two turmas apart, giving them accents that are not the same', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                ends_at: '11:20',
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
        ]);

        const [first, second] = blocksOf(week(wrapper)) as [Block, Block];

        expect(badgeOf(first)).not.toEqual(badgeOf(second));
        expect(barOf(first)).not.toEqual(barOf(second));
    });

    /**
     * E NUNCA PELA POSIÇÃO NA LISTA. Se o tom viesse do índice do cartão, a
     * mesma turma mudava de cor por ser a terceira aula do dia em vez da
     * primeira — e o sinal deixava de servir para nada.
     */
    it('accents a turma by who it is, never by where its card happens to fall', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1, starts_at: '08:30' }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
            // A mesma turma do primeiro bloco, em terceiro lugar.
            slot({ ulid: 'c', day_of_week: 1, starts_at: '14:00' }),
        ]);

        const [first, middle, third] = blocksOf(week(wrapper)) as [
            Block,
            Block,
            Block,
        ];

        expect(barOf(third)).toEqual(barOf(first));
        expect(badgeOf(third)).toEqual(badgeOf(first));
        expect(badgeOf(middle)).not.toEqual(badgeOf(first));
    });

    it('reads the same in the agenda de ecrã estreito as in the grelha', () => {
        const wrapper = mountPage([slot({ ulid: 'a', day_of_week: 1 })]);

        const onGrid = blocksOf(week(wrapper))[0] as Block;
        const onPhone = blocksOf(agenda(wrapper))[0] as Block;

        expect(barOf(onPhone)).toEqual(barOf(onGrid));
        expect(badgeOf(onPhone)).toEqual(badgeOf(onGrid));
    });

    /**
     * A COR É REFORÇO, E SÓ. O nome da turma continua escrito, por extenso,
     * dentro da própria cápsula: quem não distingue estes tons não perde
     * informação nenhuma.
     */
    it('keeps the turma\'s name written out inside the accent, never replaced by it', () => {
        const wrapper = mountPage([slot()]);

        const badge = (blocksOf(week(wrapper))[0] as Block).find('span');

        expect(badge.text()).toBe('7.º C');
        expect(badge.classes()).toContain('text-sm');
        expect(badge.classes()).toContain('font-medium');
    });

    /**
     * O TOM PÁRA NA TURMA E NA MARGEM DO BLOCO. A hora, a disciplina e o fundo
     * do cartão ficam tão neutros como sempre foram — o bloco leva a barra e
     * nada mais.
     */
    it('leaves the hora, the disciplina and the block\'s own ground untinted', () => {
        const wrapper = mountPage([slot()]);

        const block = blocksOf(week(wrapper))[0] as Block;

        // O bloco leva a barra, mas nenhum dos fundos/textos da cápsula.
        expect(barOf(block)).toContain('border-l-2');

        const painted = TURMA_TONES.flatMap((tone) =>
            TURMA_BADGE[tone].split(' '),
        );

        for (const fill of painted) {
            expect(barOf(block)).not.toContain(fill);
        }

        expectNoAccent(block.find('time').classes());
        expectNoAccent(
            block.findAll('span').map((s) => s.classes()).slice(1).flat(),
        );
    });

    /**
     * «SEM AULAS» NÃO TEM TURMA, LOGO NÃO TEM TOM. O rótulo do dia vazio
     * continua a ser um rótulo e mais nada.
     */
    it('gives the «Sem aulas» placeholder no turma accent at all', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'e', day_of_week: 5 }),
        ]);

        const thursday = week(wrapper).find('section[aria-label="Quinta-feira"]');
        const placeholder = thursday.find('p');

        expect(placeholder.text()).toBe('Sem aulas');
        expectNoAccent(placeholder.classes());
    });

    it('derives the accent from the ulid, not from the turma\'s editable label', () => {
        // O mesmo `ulid` com dois rótulos diferentes é a mesma turma, e o tom
        // não se move; rótulos iguais com `ulid` diferente são duas turmas.
        const sameTurma = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                school_class: { ulid: 'class-a', label: '7.º C (turno B)' },
            }),
        ]);

        const [first, renamed] = blocksOf(week(sameTurma)) as [Block, Block];

        expect(barOf(renamed)).toEqual(barOf(first));
        expect(badgeOf(renamed)).toEqual(badgeOf(first));

        const twoTurmas = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({
                ulid: 'b',
                day_of_week: 1,
                starts_at: '10:30',
                school_class: { ulid: 'class-b', label: '7.º C' },
            }),
        ]);

        const [one, other] = blocksOf(week(twoTurmas)) as [Block, Block];

        expect(barOf(other)).not.toEqual(barOf(one));
        expect(badgeOf(other)).not.toEqual(badgeOf(one));
    });

    /**
     * A REPARTIÇÃO É SÓ DAS TURMAS QUE ESTÃO MESMO NA PÁGINA. Uma turma sem
     * aula nenhuma não tem bloco nenhum para pintar, e não gasta um tom: as
     * turmas listadas em «Configurar manualmente» não entram na conta.
     */
    it('shares the palette out among the turmas with blocos, and no others', () => {
        const tones = assignTurmaTones(['class-b', 'class-a', 'class-b']);

        // Duas turmas distintas, dois tons, e a repetição não gasta um terceiro.
        expect(tones.size).toBe(2);
        expect(tones.get('class-a')).not.toBe(tones.get('class-b'));

        // E o mesmo conjunto por outra ordem dá exatamente o mesmo mapa.
        expect(assignTurmaTones(['class-a', 'class-b'])).toEqual(tones);

        // Uma página com blocos de uma só turma, mas com outras turmas
        // configuráveis, continua a repartir tons por uma só.
        const wrapper = mountPage(
            [slot()],
            [
                { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
                { ulid: 'class-b', label: '8.º A', subject: 'Matemática' },
            ],
        );

        expect(tonesByTurma(wrapper).size).toBe(1);
    });

    it('shows a block\'s optional validity window only when it has one', () => {
        expect(mountPage([slot()]).text()).not.toContain('Vigência');

        const limited = mountPage([
            slot({ starts_on: '2026-09-14', ends_on: '2026-12-18' }),
        ]);

        expect(limited.text()).toContain('Vigência: 2026-09-14 a 2026-12-18');
    });

    it('summarises the week from the real blocks, not from an invented total', () => {
        const wrapper = mountPage([
            slot({ ulid: 'a', day_of_week: 1 }),
            slot({ ulid: 'b', day_of_week: 2 }),
            slot({
                ulid: 'c',
                day_of_week: 3,
                school_class: { ulid: 'class-b', label: '8.º A' },
            }),
        ]);

        expect(wrapper.text()).toContain('3 aulas por semana · 2 turmas');
    });

    it('explains both ways forward, instead of an empty grid, when there is no horário yet', () => {
        const wrapper = mountPage([], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
        ]);

        expect(wrapper.text()).toContain('Ainda não tens horário configurado');
        expect(wrapper.text()).toContain('importar o PDF');
        expect(wrapper.text()).toContain('definir os blocos à mão');
        // Never a bare, wordless grid.
        expect(wrapper.findAll('section[aria-label="Horário semanal"]')).toHaveLength(0);
    });

    it('offers both configuration paths whether or not a horário already exists', () => {
        for (const wrapper of [mountPage([]), mountPage([slot()])]) {
            expect(wrapper.text()).toContain('Importar horário do professor');
            expect(wrapper.text()).toContain('Configurar manualmente');
        }
    });

    it('the "Importar PDF" link points at the real, unmodified import route', () => {
        const wrapper = mountPage([slot()]);

        const link = wrapper.findAll('a').find((a) => a.text().includes('Importar PDF'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/timetable-imports/create');
    });

    it('the manual path lists the teacher\'s own turmas, each linking to its own horário', () => {
        const wrapper = mountPage([], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
            { ulid: 'class-b', label: '8.º A', subject: 'Matemática' },
        ]);

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        expect(hrefs).toContain('/classes/class-a#horario');
        expect(hrefs).toContain('/classes/class-b#horario');
    });

    it('a block links to its own turma\'s schedule editor, so the week is a way in and not a dead end', () => {
        const wrapper = mountPage([slot()]);

        const monday = wrapper.find('section[aria-label="Segunda-feira"]');

        expect(monday.find('a').attributes('href')).toBe('/classes/class-a#horario');
    });

    it('offers a way to create a turma when there is not even one to configure', () => {
        const wrapper = mountPage([], []);

        expect(wrapper.text()).toContain('Ainda não existem turmas para configurar.');

        const link = wrapper.findAll('a').find((a) => a.text().includes('Nova turma'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/create');
    });

    it('never renders a form or a mutating control: the page is a reading', () => {
        const wrapper = mountPage([slot()], [
            { ulid: 'class-a', label: '7.º C', subject: 'Matemática' },
        ]);

        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.findAll('input')).toHaveLength(0);
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});
