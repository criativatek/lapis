import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import HomeworkGrid from './HomeworkGrid.vue';

type VisitOptions = {
    onFlash?: (flash: Record<string, unknown>) => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const { formPut, putCalls } = vi.hoisted(() => {
    const putCalls: { url: string; options: VisitOptions; rows: unknown }[] = [];

    return { putCalls, formPut: vi.fn() };
});

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), delete: vi.fn(), on: vi.fn(() => () => {}), visit: vi.fn() },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            put: (url: string, options: VisitOptions) => {
                putCalls.push({ url, options, rows: JSON.parse(JSON.stringify((form as unknown as { rows: unknown }).rows)) });
                formPut(url, options);
            },
        });

        return form;
    },
}));

type Row = {
    id: number;
    ulid?: string;
    number: number | null;
    name: string;
    homework_status: 'done' | 'partially_done' | 'not_done' | null;
    description?: string;
    record_count?: number;
    version?: string;
    editable?: boolean;
    locked_reason?: 'duplicate' | 'undetermined' | null;
    enrollment_status_label?: string;
    left_on?: string | null;
};

function serverRow(row: Row) {
    return {
        ulid: `E${row.id}`,
        description: '',
        record_count: row.homework_status === null ? 0 : 1,
        version: row.homework_status === null ? 'none' : `r:${row.id}`,
        editable: true,
        locked_reason: null,
        left_on: null,
        ...row,
    };
}

function respond(rows: Row[], extra: Record<string, unknown> = {}) {
    return new Response(JSON.stringify({ enrollments: rows.map(serverRow), excluded_without_left_on: 0, ...extra }), { status: 200 });
}

const ROSTER: Row[] = [
    { id: 1, number: 1, name: 'Ana Silva', homework_status: null },
    { id: 2, number: 2, name: 'Bruno Lopes', homework_status: 'not_done', description: 'Não trouxe.' },
    { id: 3, number: 3, name: 'Carla Dias', homework_status: null },
];

const wrappers: VueWrapper[] = [];

async function mountGrid(rows: Row[] = ROSTER, props: Record<string, unknown> = {}) {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(respond(rows)));
    const wrapper = mount(HomeworkGrid, { props: { classUlid: 'class-a', occurredAt: '2026-10-13', ...props }, attachTo: document.body });
    wrappers.push(wrapper);
    await vi.waitFor(() => expect(wrapper.findAll('[data-test^="homework-row-"]').length).toBe(rows.length));

    return { wrapper, fetchSpy };
}

function row(wrapper: VueWrapper, id: number) {
    return wrapper.find(`[data-test="homework-row-${id}"]`);
}

async function choose(wrapper: VueWrapper, id: number, label: 'Realizou' | 'Realizou parcialmente' | 'Não realizou') {
    const button = row(wrapper, id)
        .findAll('[role="radio"]')
        .find((candidate) => candidate.text().replace(/[✓◐✕]/g, '').trim() === label);
    expect(button, `${label} em ${id}`).toBeDefined();
    await button!.trigger('click');
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    putCalls.length = 0;
    formPut.mockReset();
    vi.restoreAllMocks();
});

describe('records/HomeworkGrid.vue — a grelha', () => {
    it('loads the day of the class once mounted, and again when the day changes', async () => {
        const { wrapper, fetchSpy } = await mountGrid();

        expect(fetchSpy).toHaveBeenCalledWith('/classes/class-a/records/homework-batch?occurred_at=2026-10-13', { headers: { Accept: 'application/json' } });

        await wrapper.setProps({ occurredAt: '2026-10-14' });
        await vi.waitFor(() => expect(fetchSpy).toHaveBeenLastCalledWith('/classes/class-a/records/homework-batch?occurred_at=2026-10-14', { headers: { Accept: 'application/json' } }));
    });

    it('shows every student with number and name, and a student never marked as «Não verificado» — never presumed', async () => {
        const { wrapper } = await mountGrid();

        expect(row(wrapper, 1).text()).toContain('n.º 1 · Ana Silva');
        expect(row(wrapper, 1).text()).toContain('Não verificado');
        expect(row(wrapper, 1).attributes('data-status')).toBe('unverified');
        expect(row(wrapper, 1).findAll('[aria-checked="true"]')).toHaveLength(0);
        expect(row(wrapper, 2).attributes('data-status')).toBe('not_done');

        const summary = wrapper.find('[data-test="homework-summary"]').text().replace(/\s+/g, ' ');
        expect(summary).toContain('Não realizou 1');
        expect(summary).toContain('Por verificar 2');
    });

    it('marks each of the three states with its own colour, text and selected indicator', async () => {
        const { wrapper } = await mountGrid();

        await choose(wrapper, 1, 'Realizou');
        await choose(wrapper, 3, 'Realizou parcialmente');

        const done = row(wrapper, 1).find('[aria-checked="true"]');
        expect(done.text()).toContain('Realizou');
        expect(done.classes()).toContain('bg-emerald-700');
        expect(row(wrapper, 1).classes().join(' ')).toContain('bg-emerald-50');

        const partial = row(wrapper, 3).find('[aria-checked="true"]');
        expect(partial.text()).toContain('Realizou parcialmente');
        expect(partial.classes()).toContain('bg-blue-700');

        const notDone = row(wrapper, 2).find('[aria-checked="true"]');
        expect(notDone.text()).toContain('Não realizou');
        expect(notDone.classes()).toContain('bg-orange-700');
        expect(row(wrapper, 2).classes().join(' ')).toContain('bg-orange-50');
    });

    it('«Marcar todos como realizou» touches only the students still unverified, and only after the click', async () => {
        const { wrapper } = await mountGrid();

        expect(wrapper.findAll('[aria-checked="true"]')).toHaveLength(1);
        expect(wrapper.find('[data-test="mark-all-done"]').text()).toContain('2 por verificar');

        await wrapper.find('[data-test="mark-all-done"]').trigger('click');

        expect(row(wrapper, 1).attributes('data-status')).toBe('done');
        expect(row(wrapper, 3).attributes('data-status')).toBe('done');
        // A choice already made is never overwritten by the collective action.
        expect(row(wrapper, 2).attributes('data-status')).toBe('not_done');
        expect(wrapper.find('[data-test="dirty-count"]').text()).toBe('2 alterações por guardar');

        // The exceptions are adjusted before saving.
        await choose(wrapper, 3, 'Não realizou');
        expect(row(wrapper, 3).attributes('data-status')).toBe('not_done');
    });

    it('tells what is «por guardar» from what is saved, and sends only the rows that changed', async () => {
        const { wrapper } = await mountGrid();

        expect(wrapper.find('[data-test="save-homework"]').attributes('disabled')).toBeDefined();

        await choose(wrapper, 1, 'Realizou');

        expect(row(wrapper, 1).find('[data-test="row-unsaved"]').exists()).toBe(true);
        expect(row(wrapper, 2).find('[data-test="row-unsaved"]').exists()).toBe(false);

        await wrapper.find('[data-test="save-homework"]').trigger('click');

        expect(putCalls).toHaveLength(1);
        expect(putCalls[0].url).toBe('/classes/class-a/records/homework-batch');
        expect(putCalls[0].rows).toEqual([{ enrollment_id: 1, homework_status: 'done', description: '', expected: 'none' }]);
    });

    it('keeps every choice when the save fails, and lets it be tried again', async () => {
        const { wrapper } = await mountGrid();
        await choose(wrapper, 1, 'Realizou');
        await wrapper.find('[data-test="save-homework"]').trigger('click');

        putCalls[0].options.onError?.({ rows: 'Um aluno que não integrava a turma nesta data não pode receber uma marcação nova.' });
        putCalls[0].options.onFinish?.();
        await nextTick();

        expect(wrapper.find('[data-test="save-error"]').text()).toContain('não integrava a turma');
        expect(row(wrapper, 1).attributes('data-status')).toBe('done');
        expect(row(wrapper, 1).find('[data-test="row-unsaved"]').exists()).toBe(true);

        await wrapper.find('[data-test="save-homework"]').trigger('click');
        expect(putCalls).toHaveLength(2);
    });

    it('a change made while the save is running is never lost', async () => {
        const { wrapper, fetchSpy } = await mountGrid();

        await choose(wrapper, 1, 'Realizou');
        await wrapper.find('[data-test="save-homework"]').trigger('click');

        // While the save is in flight, the teacher marks Carla.
        await choose(wrapper, 3, 'Não realizou');

        // The server now holds Ana's marking only.
        fetchSpy.mockImplementation(() => Promise.resolve(respond(ROSTER.map((candidate) => (candidate.id === 1 ? { ...candidate, homework_status: 'done' as const } : candidate)))));
        putCalls[0].options.onSuccess?.();
        putCalls[0].options.onFinish?.();
        await vi.waitFor(() => expect(wrapper.find('[data-test="dirty-count"]').text()).toBe('1 alteração por guardar'));

        expect(row(wrapper, 1).attributes('data-status')).toBe('done');
        expect(row(wrapper, 1).find('[data-test="row-unsaved"]').exists()).toBe(false);
        expect(row(wrapper, 3).attributes('data-status')).toBe('not_done');
        expect(row(wrapper, 3).find('[data-test="row-unsaved"]').exists()).toBe(true);
    });

    it('puts a marking back to «Não verificado» only after saying its saved observation goes with it', async () => {
        const { wrapper } = await mountGrid();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        await row(wrapper, 2).find('[data-test="reset-row"]').trigger('click');
        expect(row(wrapper, 2).attributes('data-status')).toBe('unverified');

        await wrapper.find('[data-test="save-homework"]').trigger('click');

        expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining('elimina a observação guardada'));
        expect(putCalls).toHaveLength(0);

        confirmSpy.mockReturnValue(true);
        await wrapper.find('[data-test="save-homework"]').trigger('click');
        expect(putCalls[0].rows).toEqual([{ enrollment_id: 2, homework_status: null, description: '', expected: 'r:2' }]);
    });

    it('never merges two homework records of the same day: the row is read-only and says why', async () => {
        const { wrapper } = await mountGrid([
            { id: 1, number: 1, name: 'Ana Silva', homework_status: null, record_count: 2, editable: false, locked_reason: 'duplicate' },
            { id: 2, number: 2, name: 'Bruno Lopes', homework_status: null, editable: false, locked_reason: 'undetermined', enrollment_status_label: 'Transferido' },
        ]);

        expect(row(wrapper, 1).findAll('[role="radio"]')).toHaveLength(0);
        expect(row(wrapper, 1).find('[data-test="row-locked"]').text()).toContain('Tem 2 registos de TPC nesta data');
        expect(row(wrapper, 1).find('[data-test="row-locked"]').text()).toContain('nem se escolhe um');
        expect(row(wrapper, 1).find('a').attributes('href')).toContain('tab=search');
        // An enrollment the data cannot place on the day: the concrete reason, never presumed eligible.
        const undetermined = row(wrapper, 2).find('[data-test="row-locked"]').text();
        expect(undetermined).toContain('Inscrição «Transferido» sem data de saída');
        expect(undetermined).toContain('não se sabe se pertencia à turma nesta data');
        expect(row(wrapper, 2).find('a').attributes('href')).toBe('/classes/class-a');
        expect(wrapper.find('[data-test="mark-all-done"]').attributes('disabled')).toBeDefined();
    });

    it('shows a student who left later, and lets the day they belonged be marked', async () => {
        const { wrapper } = await mountGrid([{ id: 4, number: 4, name: 'Duarte Sá', homework_status: null, left_on: '2026-10-20' }]);

        expect(row(wrapper, 4).text()).toContain('saiu a 20/10/2026');
        await choose(wrapper, 4, 'Realizou');
        expect(row(wrapper, 4).attributes('data-status')).toBe('done');
    });

    it('a student changed in another session is not overwritten: the choice stays, the current state is shown, the rest is saved', async () => {
        const { wrapper, fetchSpy } = await mountGrid();

        await choose(wrapper, 1, 'Realizou');
        await choose(wrapper, 2, 'Realizou parcialmente');
        await wrapper.find('[data-test="save-homework"]').trigger('click');
        expect(putCalls[0].rows).toEqual([
            { enrollment_id: 1, homework_status: 'done', description: '', expected: 'none' },
            { enrollment_id: 2, homework_status: 'partially_done', description: 'Não trouxe.', expected: 'r:2' },
        ]);

        // Bruno was changed meanwhile; Ana was saved.
        fetchSpy.mockImplementation(() => Promise.resolve(respond([
            { ...ROSTER[0], homework_status: 'done' },
            { ...ROSTER[1], homework_status: 'done', description: '', version: 'r:2b' },
            ROSTER[2],
        ])));
        putCalls[0].options.onFlash?.({
            homework_save: {
                saved: [1],
                unchanged: [],
                conflicts: [{ enrollment_id: 2, homework_status: 'done', description: '', version: 'r:2b', change: 'changed', at: '2026-10-13T10:42:00+01:00' }],
            },
        });
        putCalls[0].options.onSuccess?.();
        putCalls[0].options.onFinish?.();
        await vi.waitFor(() => expect(row(wrapper, 2).find('[data-test="row-conflict"]').exists()).toBe(true));

        expect(row(wrapper, 2).find('[data-test="row-conflict"]').text()).toContain('passou a «Realizou»');
        expect(row(wrapper, 2).find('[data-test="row-conflict"]').text()).toContain('A sua escolha («Realizou parcialmente») não foi gravada');
        expect(row(wrapper, 2).attributes('data-status')).toBe('partially_done');
        expect(row(wrapper, 1).find('[data-test="row-unsaved"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="partial-save"]').text()).toContain('Gravação parcial: 1 linha gravada');
        expect(wrapper.find('[data-test="saved-at"]').exists()).toBe(false);
        // A row in conflict is not sent again before it is reviewed.
        expect(wrapper.find('[data-test="save-homework"]').attributes('disabled')).toBeDefined();

        // Keeping the choice is deliberate: sent with the version now seen.
        await row(wrapper, 2).find('[data-test="keep-mine"]').trigger('click');
        await wrapper.find('[data-test="save-homework"]').trigger('click');
        expect(putCalls[1].rows).toEqual([{ enrollment_id: 2, homework_status: 'partially_done', description: 'Não trouxe.', expected: 'r:2b' }]);
    });

    it('«Usar o estado atual» drops the choice and shows what is saved now', async () => {
        const { wrapper, fetchSpy } = await mountGrid();

        await choose(wrapper, 2, 'Realizou');
        await wrapper.find('[data-test="save-homework"]').trigger('click');
        fetchSpy.mockImplementation(() => Promise.resolve(respond([ROSTER[0], { ...ROSTER[1], homework_status: null, description: '', version: 'none' }, ROSTER[2]])));
        putCalls[0].options.onFlash?.({
            homework_save: { saved: [], unchanged: [], conflicts: [{ enrollment_id: 2, homework_status: null, description: '', version: 'none', change: 'removed', at: null }] },
        });
        putCalls[0].options.onSuccess?.();
        putCalls[0].options.onFinish?.();
        await vi.waitFor(() => expect(row(wrapper, 2).find('[data-test="row-conflict"]').exists()).toBe(true));
        expect(row(wrapper, 2).find('[data-test="row-conflict"]').text()).toContain('foi reposta em «Não verificado»');

        await row(wrapper, 2).find('[data-test="use-current"]').trigger('click');

        expect(row(wrapper, 2).attributes('data-status')).toBe('unverified');
        expect(row(wrapper, 2).find('[data-test="row-conflict"]').exists()).toBe(false);
        expect(row(wrapper, 2).find('[data-test="row-unsaved"]').exists()).toBe(false);
    });

    it('after a lost connection the same request goes again, with the same versions', async () => {
        const { wrapper } = await mountGrid();
        await choose(wrapper, 1, 'Não realizou');
        await wrapper.find('[data-test="save-homework"]').trigger('click');

        // No answer at all: no success, no validation error.
        putCalls[0].options.onFinish?.();
        await nextTick();
        expect(wrapper.find('[data-test="save-error"]').text()).toContain('As escolhas mantêm-se');

        await wrapper.find('[data-test="save-homework"]').trigger('click');
        expect(putCalls[1].rows).toEqual(putCalls[0].rows);
    });

    it('works from the keyboard: 1/2/3 choose, 0 resets, ↓ goes to the next student', async () => {
        const { wrapper } = await mountGrid();
        const first = row(wrapper, 1).find('[role="radio"]');

        await first.trigger('keydown', { key: '3' });
        expect(row(wrapper, 1).attributes('data-status')).toBe('not_done');

        await first.trigger('keydown', { key: '0' });
        expect(row(wrapper, 1).attributes('data-status')).toBe('unverified');

        await first.trigger('keydown', { key: 'ArrowDown' });
        await nextTick();
        expect((document.activeElement as HTMLElement | null)?.dataset.row).toBe('1');
    });

    it('in read-only mode shows the markings and offers no change', async () => {
        const { wrapper } = await mountGrid(ROSTER, { readOnly: true });

        expect(wrapper.findAll('[role="radio"]')).toHaveLength(0);
        expect(wrapper.find('[data-test="save-bar"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('só para consulta');
    });
});
