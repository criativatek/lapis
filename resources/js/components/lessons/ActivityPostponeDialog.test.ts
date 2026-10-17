import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import ActivityPostponeDialog from './ActivityPostponeDialog.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router: { post: mocks.post } }));

// O diálogo real teleporta para fora da árvore; aqui renderiza-se inline, e só
// enquanto está aberto.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', { 'data-dialog': '' }, slots.default?.()) : null),
        }),
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

const event = { ulid: 'event-1', title: 'Visita de estudo', all_day: true, starts_at: null, ends_at: null };

function move(day: string, label: string | null, excerpt: string | null, extra: Record<string, unknown> = {}) {
    return {
        from: { ulid: 'a', starts_at: '2026-10-08T09:30:00+01:00' },
        to: {
            ulid: 'b',
            starts_at: `2026-10-${day}T09:30:00+01:00`,
            ends_at: `2026-10-${day}T10:20:00+01:00`,
            lesson_number: label === null ? null : Number(label),
            lesson_number_end: null,
            lesson_number_label: label,
        },
        created_from_timetable: false,
        excerpt,
        fields: ['content'],
        ...extra,
    };
}

function previewBody(overrides: Record<string, unknown> = {}) {
    return {
        lesson: { ulid: 'lesson-a', starts_at: '2026-10-08T09:30:00+01:00' },
        event: { ulid: 'event-1', title: 'Visita de estudo' },
        outcome_label: 'Turma em outras atividades letivas',
        note: 'Visita de estudo',
        moves: [move('15', '4', 'Plano 2')],
        pending: null,
        nothing_to_move: false,
        discarded_attendance_drafts: 0,
        renumbered: [],
        displaced_preparations: 1,
        requires_cascade_confirmation: false,
        plan_token: 'token-1',
        ...overrides,
    };
}

function jsonResponse(body: unknown, status = 200): Response {
    return { ok: status < 400, status, json: async () => body } as Response;
}

async function mountDialog(
    props: { hasUnsavedChanges?: boolean; saveFirst?: () => Promise<boolean>; event?: typeof event & { notes?: string | null } } = {},
): Promise<VueWrapper> {
    const wrapper = mount(ActivityPostponeDialog, {
        props: { lessonUlid: 'lesson-a', event, open: true, hasUnsavedChanges: false, ...props },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    mocks.post.mockReset();
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('ActivityPostponeDialog', () => {
    it('pede a pré-visualização e mostra o que fica nesta aula e para onde vai a preparação', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await mountDialog();

        const [url, init] = vi.mocked(fetch).mock.calls[0];
        expect(url).toBe('/lessons/lesson-a/activity-postponement/preview');
        expect(JSON.parse((init as RequestInit).body as string)).toEqual({ calendar_event: 'event-1' });
        expect((init as RequestInit).headers).toHaveProperty('X-XSRF-TOKEN');

        expect(wrapper.get('[data-testid="postpone-stays"]').text()).toContain('Turma em outras atividades letivas — Visita de estudo');
        const moves = wrapper.findAll('[data-testid="postpone-move"]');
        expect(moves).toHaveLength(1);
        expect(moves[0].text()).toContain('A preparação desta aula passa para');
        expect(moves[0].text()).toContain('Lição 4');
        expect(moves[0].text()).toContain('«Plano 2»');
        expect(wrapper.text()).toContain('Nenhuma preparação, sumário ou registo é substituído: cada preparação avança uma aula, pela mesma ordem.');
        expect(wrapper.find('[data-testid="postpone-cascade"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeUndefined();
    });

    it('diz quando a aula do destino é criada a partir do horário', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody({ moves: [move('15', null, 'Plano 2', { created_from_timetable: true })] })));

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="postpone-move"]').text()).toContain('aula criada a partir do horário');
    });

    it('sem destino explica que fica como «Planeamento pendente» nesta aula, sem apagar texto', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    moves: [],
                    pending: { on: { ulid: 'lesson-a', starts_at: '2026-10-08T09:30:00+01:00' }, on_this_lesson: true, excerpt: 'Plano 1', fields: ['content'] },
                }),
            ),
        );

        const wrapper = await mountDialog();
        const pending = wrapper.get('[data-testid="postpone-pending"]');

        expect(pending.text()).toContain('Não há mais aulas no horário até ao fim do ano letivo');
        expect(pending.text()).toContain('«Planeamento pendente»');
        expect(pending.text()).toContain('nesta aula');
        expect(pending.text()).toContain('sem apagar texto');
    });

    it('sem preparação diz que nada passa', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody({ moves: [], nothing_to_move: true })));

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="postpone-nothing"]').text()).toContain('nada passa para outra aula');
    });

    it('mostra que as notas ficam no calendário, as faltas descartadas e os números que mudam', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    discarded_attendance_drafts: 2,
                    renumbered: [{ ulid: 'x', starts_at: '2026-10-22T09:30:00+01:00', from: 6, to: 5 }],
                }),
            ),
        );

        const wrapper = await mountDialog({ event: { ...event, notes: 'Autorizações\nassinadas' } });

        expect(wrapper.get('[data-testid="postpone-notes-stay"]').text()).toContain('continuam completas no teu calendário');
        expect(wrapper.text()).toContain('O texto da preparação segue inteiro, sem cortes.');
        expect(wrapper.get('[data-testid="postpone-drafts"]').text()).toContain('2 faltas em rascunho são descartadas');
        expect(wrapper.get('[data-testid="postpone-renumbered"]').text()).toContain('Lição 6 → 5');
    });

    it('diz o que fica registado na aula ao reabrir, sem falar de notas quando o acontecimento não as tem', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await mountDialog();

        expect(wrapper.text()).toContain('Fica registado nesta aula');
        expect(wrapper.text()).toContain('É o que vês ao reabrir esta aula e o que aparece no registo da turma. A preparação é adiada');
        expect(wrapper.find('[data-testid="postpone-notes-stay"]').exists()).toBe(false);
    });

    it('uma linha ambígua no sumário: mostra a recusa do servidor e não deixa confirmar', async () => {
        const message =
            'O sumário desta aula tem a linha «Visita de estudo», igual à que «Incluir no sumário» acrescenta. Não é possível saber se foi escrita por ti ou acrescentada automaticamente, por isso a preparação não foi adiada e nada foi alterado.';
        vi.mocked(fetch).mockResolvedValue(jsonResponse({ errors: { activity_line: [message] } }, 422));

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="postpone-error"]').text()).toContain(message);
        expect(wrapper.find('[data-testid="postpone-preview"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeDefined();
    });

    it('com mais de uma preparação a caixa de confirmação é obrigatória', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                previewBody({
                    moves: [move('15', '4', 'Plano 2'), move('22', '5', 'Plano 3', { from: { ulid: 'b', starts_at: '2026-10-15T09:30:00+01:00' }, fields: ['content', 'homework'] })],
                    displaced_preparations: 2,
                    requires_cascade_confirmation: true,
                }),
            ),
        );

        const wrapper = await mountDialog();
        const confirm = wrapper.get('[data-testid="postpone-confirm"]');
        const moves = wrapper.findAll('[data-testid="postpone-move"]');

        // Cada preparação diz de onde vem e o que leva além do texto.
        expect(moves[1].text()).toContain('A preparação de');
        expect(moves[1].text()).toContain('15/10');
        expect(moves[1].text()).toContain('Leva também: TPC.');
        expect(wrapper.get('[data-testid="postpone-cascade"]').text()).toContain('Confirmo que estas 2 preparações mudam de aula, como mostrado acima.');
        expect(confirm.attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="postpone-cascade"] input').setValue(true);

        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeUndefined();

        await wrapper.get('[data-testid="postpone-confirm"]').trigger('click');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe('/lessons/lesson-a/activity-postponement');
        expect(mocks.post.mock.calls[0][1]).toEqual({ calendar_event: 'event-1', plan_token: 'token-1', confirm_cascade: true });
    });

    it('com alterações por guardar não pede pré-visualização, pede para guardar e bloqueia a confirmação', async () => {
        const saveFirst = vi.fn().mockResolvedValue(true);
        const wrapper = await mountDialog({ hasUnsavedChanges: true, saveFirst });

        expect(fetch).not.toHaveBeenCalled();
        expect(wrapper.get('[data-testid="postpone-unsaved"]').text()).toContain(
            'Tens alterações por guardar no sumário desta aula. Guarda-as primeiro: a preparação que passa para a aula seguinte é a que está guardada.',
        );
        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="postpone-save-first"]').trigger('click');
        await flushPromises();

        expect(saveFirst).toHaveBeenCalledTimes(1);
        // Gravação aceite: o diálogo continua aberto à espera da pré-visualização.
        expect(wrapper.emitted('update:open')).toBeUndefined();
    });

    it('se a gravação não for aceite o diálogo fecha-se, para a razão ficar à vista na página', async () => {
        const saveFirst = vi.fn().mockResolvedValue(false);
        const wrapper = await mountDialog({ hasUnsavedChanges: true, saveFirst });

        await wrapper.get('[data-testid="postpone-save-first"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('quando as alterações por guardar deixam de existir pede a pré-visualização', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));
        const wrapper = await mountDialog({ hasUnsavedChanges: true });

        await wrapper.setProps({ hasUnsavedChanges: false });
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-testid="postpone-move"]').exists()).toBe(true);
    });

    it('um 422 mostra a mensagem do servidor e não deixa confirmar', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse({ errors: { outcome: ['A aula de 15/10/2026, depois desta, já está fechada.'] } }, 422));

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="postpone-error"]').text()).toContain('já está fechada');
        expect(wrapper.get('[data-testid="postpone-confirm"]').attributes('disabled')).toBeDefined();
    });

    it('uma resposta antiga que chega depois não substitui a mais recente', async () => {
        const pending: Array<(response: Response) => void> = [];
        vi.mocked(fetch).mockImplementation(() => new Promise<Response>((resolve) => pending.push(resolve)));

        const wrapper = await mountDialog({ hasUnsavedChanges: false });
        // Segundo pedido: o estado mudou (guardou-se e voltou a haver alterações).
        await wrapper.setProps({ hasUnsavedChanges: true });
        await wrapper.setProps({ hasUnsavedChanges: false });

        expect(pending).toHaveLength(2);

        pending[1](jsonResponse(previewBody({ plan_token: 'novo' })));
        await flushPromises();
        pending[0](jsonResponse(previewBody({ plan_token: 'velho' })));
        await flushPromises();

        await wrapper.get('[data-testid="postpone-confirm"]').trigger('click');

        expect(mocks.post.mock.calls[0][1].plan_token).toBe('novo');
    });

    it('se o servidor recusar o registo mostra a razão e volta a pedir a pré-visualização', async () => {
        vi.mocked(fetch)
            .mockResolvedValueOnce(jsonResponse(previewBody({ plan_token: 'velho' })))
            .mockResolvedValueOnce(jsonResponse(previewBody({ plan_token: 'novo' })));
        mocks.post.mockImplementation((_url: string, _data: unknown, options: { onError: (errors: Record<string, string>) => void; onFinish: () => void }) => {
            options.onError({ plan_token: 'A aula ou as aulas seguintes mudaram desde a pré-visualização. Revê e confirma de novo.' });
            options.onFinish();
        });

        const wrapper = await mountDialog();
        await wrapper.get('[data-testid="postpone-confirm"]').trigger('click');
        await flushPromises();

        expect(fetch).toHaveBeenCalledTimes(2);
        expect(wrapper.get('[data-testid="postpone-refusal"]').text()).toContain('mudaram desde a pré-visualização');

        await wrapper.get('[data-testid="postpone-confirm"]').trigger('click');
        expect(mocks.post.mock.calls[1][1].plan_token).toBe('novo');
    });

    it('«Cancelar» fecha sem registar nada', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await mountDialog();
        await wrapper.get('[data-testid="postpone-cancel"]').trigger('click');

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('os botões têm pelo menos 44px de altura', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(previewBody()));

        const wrapper = await mountDialog();

        expect(wrapper.get('[data-testid="postpone-confirm"]').classes()).toContain('min-h-11');
        expect(wrapper.get('[data-testid="postpone-cancel"]').classes()).toContain('min-h-11');
    });
});
