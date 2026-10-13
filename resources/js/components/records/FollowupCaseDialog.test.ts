import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { CaseDetail } from '@/lib/recordFollowup';
import FollowupCaseDialog from './FollowupCaseDialog.vue';

const { routerPost, routerDelete, pageProps } = vi.hoisted(() => ({
    routerPost: vi.fn(),
    routerDelete: vi.fn(),
    pageProps: { errors: {} as Record<string, string> },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: routerPost, delete: routerDelete, get: vi.fn() },
    usePage: () => ({ props: pageProps, url: '/records' }),
}));

// The reka dialog teleports; a pass-through keeps the content in the wrapper.
vi.mock('@/components/ui/dialog', () => {
    const passThrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: passThrough,
        DialogScrollContent: passThrough,
        DialogHeader: passThrough,
        DialogTitle: passThrough,
        DialogDescription: passThrough,
    };
});

const wrappers: VueWrapper[] = [];

function detail(overrides: Partial<CaseDetail> = {}): CaseDetail {
    return {
        class: { ulid: 'C1', label: '7.º A', subject: 'Matemática' },
        student: { ulid: 'S1', number: 5, name: 'Ana Sofia Costa', short_name: 'Ana Costa' },
        category: 'lateness',
        category_label: 'Atrasos',
        window: { is_period: true, label: '1.º Período (14/09/2026 a 16/12/2026)', period_ulid: 'P1' },
        occurrences: [
            { ulid: 'R1', date: '2026-10-03', detail_label: null, description: 'Chegou às 8h20.' },
            { ulid: 'R2', date: '2026-10-10', detail_label: null, description: '' },
            { ulid: 'R3', date: '2026-10-10', detail_label: null, description: '' },
        ],
        count: 3,
        status: 'pending',
        origin: 'alert',
        alerted_at: '2026-10-10T10:00:00+01:00',
        alerted_count: 3,
        message: { text: 'O aluno n.º 5, Ana Costa, acumulou três faltas por atraso…', missing: [] },
        confirmation: null,
        history: [],
        can_confirm: true,
        ...overrides,
    };
}

function mountDialog(overrides: Partial<CaseDetail> = {}) {
    const wrapper = mount(FollowupCaseDialog, {
        attachTo: document.body,
        props: { detail: detail(overrides), currentUrl: '/records?view=lateness&case_class=C1' },
    });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    routerPost.mockReset();
    routerDelete.mockReset();
    pageProps.errors = {};
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('FollowupCaseDialog', () => {
    it('lists every occurrence, two on the same day included, each linking back to its record', () => {
        const wrapper = mountDialog();

        expect(wrapper.findAll('ol li')).toHaveLength(3);
        expect(wrapper.text()).toContain('03/10/2026');
        expect(wrapper.find('ol').text().match(/10\/10\/2026/g)).toHaveLength(2);

        const href = wrapper.find('ol li a').attributes('href') ?? '';
        expect(href).toContain('/classes/C1/records?registo=R1');
        expect(decodeURIComponent(href)).toContain('voltar=/records?view=lateness&case_class=C1');
    });

    it('copies the edited text, says so, and never marks the case', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        const wrapper = mountDialog();

        await wrapper.find('textarea').setValue('Texto revisto pelo professor.');
        await wrapper.find('[data-test="copy-message"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('Texto revisto pelo professor.');
        expect(wrapper.find('[data-test="copy-feedback"]').text()).toContain('Mensagem copiada');
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('selects the text and explains how to copy by hand when the browser refuses', async () => {
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
        const wrapper = mountDialog();
        const textarea = wrapper.find('textarea').element as HTMLTextAreaElement;
        const select = vi.spyOn(textarea, 'select');

        await wrapper.find('[data-test="copy-message"]').trigger('click');
        await flushPromises();

        expect(select).toHaveBeenCalled();
        expect(wrapper.find('[data-test="copy-feedback"]').text()).toContain('O texto ficou selecionado');
    });

    it('asks to review the identification when a piece of it is missing', () => {
        const wrapper = mountDialog({ message: { text: 'O aluno Ana Costa acumulou…', missing: ['number'] } });

        expect(wrapper.text()).toContain('Falta o número do aluno na turma — reveja a identificação antes de copiar.');
    });

    it('marks as registered only after an explicit confirmation, sending exactly the occurrences shown', async () => {
        const wrapper = mountDialog();

        await wrapper.find('[data-test="mark-registered"]').trigger('click');
        expect(routerPost).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('a aplicação não verifica o Inovar');

        await wrapper.find('[data-test="confirm-registered"]').trigger('click');

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/records/acompanhamento/confirmacoes');
        expect(routerPost.mock.calls[0][1]).toEqual({
            class: 'C1',
            student: 'S1',
            category: 'lateness',
            period: 'P1',
            occurrences: [{ ulid: 'R1', date: '2026-10-03' }, { ulid: 'R2', date: '2026-10-10' }, { ulid: 'R3', date: '2026-10-10' }],
        });
    });

    it('offers no declaration for a custom date range nor during a support session', () => {
        expect(mountDialog({ window: { is_period: false, label: '01/10/2026 a 31/10/2026', period_ulid: null }, status: null }).text())
            .toContain('consulte por período de avaliação');
        expect(mountDialog({ can_confirm: false }).text()).toContain('sessão de apoio técnico');
        expect(mountDialog({ can_confirm: false }).find('[data-test="mark-registered"]').exists()).toBe(false);
    });

    it('shows who declared it and what changed since, without claiming the Inovar was corrected', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mountDialog({
            status: 'registered',
            confirmation: {
                ulid: 'K1',
                confirmed_by: 'Professora Ana',
                confirmed_at: '2026-10-11T09:00:00+01:00',
                count: 3,
                dates: ['2026-10-03', '2026-10-10', '2026-10-10'],
                changes: { added: ['2026-10-17'], removed: ['2026-10-03'] },
            },
        });

        expect(wrapper.find('[data-test="case-status"]').text()).toBe('Registado no Inovar');
        expect(wrapper.text()).toContain('Professora Ana');
        const changes = wrapper.find('[data-test="confirmation-changes"]').text();
        expect(changes).toContain('03/10/2026');
        expect(changes).toContain('17/10/2026');
        expect(changes).toContain('A aplicação não altera nem verifica o registo feito no Inovar.');

        await wrapper.findAll('button').find((button) => button.text() === 'Desfazer marcação')?.trigger('click');
        expect(routerDelete).toHaveBeenCalledWith('/records/acompanhamento/confirmacoes/K1', expect.any(Object));
    });

    it('never offers a declaration below the third occurrence', () => {
        const wrapper = mountDialog({ count: 2, status: 'none', occurrences: detail().occurrences.slice(0, 2) });

        expect(wrapper.find('[data-test="mark-registered"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('a partir da 3.ª ocorrência');
    });

    it('says the occurrences are gone instead of preparing a message for zero', () => {
        const wrapper = mountDialog({ count: 0, status: 'none', occurrences: [], message: { text: '', missing: [] } });

        expect(wrapper.find('[data-test="no-occurrences"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="copy-message"]').exists()).toBe(false);
    });

    it('shows the server refusal when the records moved under the teacher', () => {
        pageProps.errors = { occurrences: 'Os registos deste aluno mudaram desde que abriu o detalhe.' };

        expect(mountDialog().find('[role="alert"]').text()).toContain('mudaram desde que abriu o detalhe');
    });
});
