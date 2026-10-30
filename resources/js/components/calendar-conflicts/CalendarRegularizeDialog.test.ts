import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import CalendarRegularizeDialog from './CalendarRegularizeDialog.vue';
import type { ReviewLesson } from './CalendarRegularizeDialog.vue';

vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', slots.default?.()) : null),
        }),
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

const REVIEW: ReviewLesson = {
    ulid: 'lesson-c',
    date: '2026-12-22',
    time: '09:30',
    class: 'c1',
    class_label: '8.º A',
    group: 'T1',
    reasons: [
        { code: 'taught', label: 'Aula já lecionada.' },
        { code: 'attendance_recorded', label: 'Tem assiduidade registada.' },
    ],
    exception_titles: ['Interrupção de Natal'],
};

const EMPTY_PLAN = {
    plan_token: 'c'.repeat(64),
    scope: 'teacher',
    operation: 'regularize',
    counts: { A: 0, B: 0, C: 1 },
    totals: { retired: 0, shifted: 0, awaiting_teacher: 0, flagged: 1 },
    classes: [
        {
            class: 'c1',
            class_label: '8.º A',
            retired: 0,
            shifted: 0,
            awaiting_teacher: 0,
            flagged: 1,
            renumbered: 0,
            gaps: [],
            lessons: [
                {
                    ulid: 'lesson-c',
                    date: '2026-12-22',
                    time: '09:30',
                    group: 'T1',
                    kind: 'C',
                    retired: false,
                    reasons: REVIEW.reasons,
                    exception_titles: ['Interrupção de Natal'],
                    moves: [],
                    pending: false,
                },
            ],
        },
    ],
    gaps: [],
    freed_days: [],
    existing_on_freed_days: { count: 0 },
    changes_anything: false,
};

function stubPreview(body: unknown) {
    const fetchMock = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) }));

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function mountDialog(props: { canRegularize?: boolean; reviewLessons?: ReviewLesson[]; blockedReason?: 'year_closed' | 'read_only' | 'support_session' | null; reviewOnly?: boolean } = {}) {
    const wrapper = mount(CalendarRegularizeDialog, {
        props: { open: true, canRegularize: true, reviewLessons: [REVIEW], ...props },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('CalendarRegularizeDialog', () => {
    it('lists each review lesson with date, time, class, group, reasons and a link to the lesson', async () => {
        stubPreview(EMPTY_PLAN);

        const wrapper = await mountDialog();
        const item = wrapper.get('[data-testid="regularize-review-item"]');

        expect(item.text()).toContain('8.º A · T1');
        expect(item.text()).toContain('09:30');
        expect(item.text()).toContain('Aula já lecionada.');
        expect(item.text()).toContain('Tem assiduidade registada.');
        expect(item.text()).toContain('Interrupção de Natal');
        expect(item.get('a').attributes('href')).toBe('/lessons/lesson-c');
        expect(item.get('a').attributes('aria-label')).toContain('Abrir a aula');
    });

    it('offers no «Confirmar e corrigir» when the plan changes nothing', async () => {
        stubPreview(EMPTY_PLAN);

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="regularize-nothing"]').text()).toContain('Não há aulas a corrigir automaticamente.');
        // Sem nada a corrigir, não se promete correção: nem botão, e «Fechar» em vez de «Cancelar».
        expect(wrapper.find('[data-testid="regularize-confirm"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="regularize-close"]').text()).toBe('Fechar');
    });

    it('does not request a preview, and cannot apply, when the year is closed', async () => {
        const fetchMock = stubPreview(EMPTY_PLAN);

        const wrapper = await mountDialog({ canRegularize: false });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.find('[data-testid="regularize-confirm"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('O ano letivo está fechado');
        expect(wrapper.text()).toContain('Aulas para rever uma a uma');
    });

    it('says, per class, how many lessons still to be given change number and that taught ones keep theirs', async () => {
        stubPreview({
            ...EMPTY_PLAN,
            counts: { A: 1, B: 1, C: 0 },
            totals: { retired: 1, shifted: 1, awaiting_teacher: 0, flagged: 0 },
            changes_anything: true,
            classes: [
                {
                    class: 'c1',
                    class_label: '8.º A',
                    retired: 1,
                    shifted: 1,
                    awaiting_teacher: 0,
                    flagged: 0,
                    renumbered: 2,
                    gaps: [],
                    lessons: [
                        {
                            ulid: 'b1',
                            date: '2026-12-21',
                            time: '09:30',
                            group: null,
                            kind: 'B',
                            retired: true,
                            reasons: [],
                            exception_titles: [],
                            moves: [{ to_lesson: null, to_date: '2027-01-04', to_time: '09:30', new_lesson: true, fields: ['content'] }],
                            pending: false,
                        },
                    ],
                },
            ],
        });

        const wrapper = await mountDialog({ reviewLessons: [] });

        expect(wrapper.get('[data-testid="regularize-renumbered"]').text()).toBe('2 aulas por dar mudam de número.');
        // Numa secção própria, com o porquê — e não em «Vai ser preservado».
        expect(wrapper.get('[data-testid="regularize-numbering"]').text()).toContain('Atenção à numeração');
        expect(wrapper.get('[data-testid="regularize-numbering"]').text()).toContain('As aulas por dar ocupam o lugar das retiradas; as lecionadas mantêm o número.');
        expect(wrapper.get('[data-testid="regularize-kept"]').text()).not.toContain('número');
        expect(wrapper.text()).toContain('(aula criada a partir do horário)');
    });

    it('says the planning result, one line per retired lesson, and the planning that moves on apart', async () => {
        stubPreview({
            ...EMPTY_PLAN,
            counts: { A: 1, B: 3, C: 0 },
            totals: { retired: 1, shifted: 3, awaiting_teacher: 0, flagged: 0 },
            changes_anything: true,
            classes: [
                {
                    class: 'c1',
                    class_label: '7.º A',
                    retired: 1,
                    shifted: 3,
                    awaiting_teacher: 0,
                    flagged: 0,
                    renumbered: 0,
                    gaps: [],
                    planning: [
                        { origin: 'retired', from_date: '2026-12-21', to_date: '2027-01-04', new_lesson: false, pending: false },
                        { origin: 'retired', from_date: '2026-12-28', to_date: '2027-01-11', new_lesson: false, pending: false },
                        { origin: 'retired', from_date: '2026-12-29', to_date: '2027-01-05', new_lesson: false, pending: false },
                        { origin: 'advanced', from_date: '2027-01-04', to_date: '2027-01-18', new_lesson: false, pending: false },
                        { origin: 'advanced', from_date: '2027-01-05', to_date: '2027-01-12', new_lesson: false, pending: false },
                    ],
                    lessons: [],
                },
            ],
        });

        const wrapper = await mountDialog({ reviewLessons: [] });
        const kept = wrapper.get('[data-testid="regularize-kept"]');
        const lines = wrapper.findAll('[data-testid="regularize-planning-retired"] li').map((item) => item.text());

        expect(kept.text()).toContain('3 aulas com planeamento são retiradas.');
        // Tantas linhas como aulas com planeamento — o resultado, não a cascata.
        expect(lines).toHaveLength(3);
        expect(lines[0]).toMatch(/^O planeamento de .*21\/12 passa para .*04\/01\.$/);
        expect(wrapper.get('[data-testid="regularize-planning-advanced"]').text()).toBe(
            'O planeamento que já estava nas aulas de 04/01 e 05/01 avança para a aula seguinte: 18/01 e 12/01.',
        );
    });

    it('offers «Tentar de novo» when the preview fails, without closing the dialog', async () => {
        let calls = 0;
        vi.stubGlobal(
            'fetch',
            vi.fn(() => {
                calls++;

                return calls === 1
                    ? Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve(null) })
                    : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(EMPTY_PLAN) });
            }),
        );

        const wrapper = await mountDialog();
        await wrapper.get('[data-testid="regularize-retry"]').trigger('click');
        await flushPromises();

        expect(calls).toBe(2);
        expect(wrapper.find('[data-testid="regularize-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="regularize-preview"]').exists()).toBe(true);
    });

    it('closes from a corner button with a 44 px target', async () => {
        stubPreview(EMPTY_PLAN);

        const wrapper = await mountDialog();
        const close = wrapper.get('[data-testid="regularize-x"]');

        expect(close.attributes('aria-label')).toBe('Fechar');
        expect(close.classes()).toContain('size-11');
        await close.trigger('click');
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('lists the review lessons of the preview itself, not the ones the page loaded earlier', async () => {
        stubPreview({
            ...EMPTY_PLAN,
            classes: [
                {
                    ...EMPTY_PLAN.classes[0],
                    lessons: [
                        {
                            ...EMPTY_PLAN.classes[0].lessons[0],
                            ulid: 'lesson-shared',
                            group: 'T2',
                            reasons: [{ code: 'shared_unit', label: 'Os grupos da turma (T1/T2) dão esta lição em aulas separadas, e a do outro grupo continua a existir: esta fica como está, para a lição não se partir.' }],
                        },
                    ],
                },
            ],
        });

        const wrapper = await mountDialog({ reviewLessons: [REVIEW] });
        const items = wrapper.findAll('[data-testid="regularize-review-item"]');

        expect(items).toHaveLength(1);
        expect(items[0].get('a').attributes('href')).toBe('/lessons/lesson-shared');
        expect(items[0].text()).toContain('para a lição não se partir');
        // E nada de «com registos» para uma aula que não os tem.
        expect(wrapper.text()).not.toContain('com registos');
        expect(wrapper.get('[data-testid="regularize-flagged"]').text()).toContain('1 aula não pode ser corrigida automaticamente');
    });

    it('says the lessons over the planning limit wait for the next correction, not for an individual review', async () => {
        stubPreview({
            ...EMPTY_PLAN,
            classes: [
                {
                    ...EMPTY_PLAN.classes[0],
                    lessons: [
                        {
                            ...EMPTY_PLAN.classes[0].lessons[0],
                            ulid: 'lesson-later',
                            group: null,
                            reasons: [{ code: 'batch_limit', label: 'Fica para a correção seguinte: há demasiadas aulas com planeamento para uma só vez.' }],
                        },
                    ],
                },
            ],
        });

        const wrapper = await mountDialog({ reviewLessons: [] });

        expect(wrapper.get('[data-testid="regularize-later"]').text()).toContain('1 aula com planeamento fica para a correção seguinte');
        expect(wrapper.find('[data-testid="regularize-flagged"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="regularize-review-item"]').exists()).toBe(false);
    });

    it('says why a refused preview will not work on a retry', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: false, status: 403, json: () => Promise.resolve({ message: 'Não é possível realizar esta ação durante uma sessão de suporte.' }) })),
        );

        const wrapper = await mountDialog();
        const error = wrapper.get('[data-testid="regularize-error"]').text();

        expect(error).toContain('sessão de suporte');
        expect(error).not.toContain('tenta outra vez');
    });

    it('shows a plain message when the preview request is refused', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve(null) })),
        );

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="regularize-error"]').text()).toContain('Não foi possível ver as aulas. Nada foi alterado');
        expect(wrapper.find('[data-testid="regularize-retry"]').exists()).toBe(true);
    });

    it('in review-only mode lists the lessons without asking for a preview or promising a correction', async () => {
        const fetchMock = stubPreview(EMPTY_PLAN);

        const wrapper = await mountDialog({ reviewOnly: true });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Aulas para rever');
        expect(wrapper.text()).toContain('Já foi corrigido o que podia ser corrigido automaticamente.');
        expect(wrapper.text()).toContain('Esta aula das tuas turmas cai num dia sem aulas e fica como está');
        expect(wrapper.find('[data-testid="regularize-confirm"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="regularize-review-item"]').exists()).toBe(true);
    });
});
