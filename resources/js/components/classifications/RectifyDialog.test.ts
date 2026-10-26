import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { RectificationDetails, RectificationState } from '@/lib/rectification';
import RectifyDialog from './RectifyDialog.vue';

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

const reasons = [
    { value: 'entry_error', label: 'Erro de lançamento', requires_note: false },
    { value: 'other', label: 'Outro', requires_note: true },
];

const levels = [
    { id: 4, code: '4', label: 'Bom' },
    { id: 3, code: '3', label: 'Suficiente' },
];

const GENERIC = 'Esta classificação já foi publicada. Se a comunicou fora do Lapispro (Inovar, pauta impressa, PDF), terá de atualizar esses registos.';

function state(overrides: Partial<RectificationState> = {}): RectificationState {
    return { allowed: true, needs_confirmation: false, blocked_reason: null, lock_version: 7, count: 0, ...overrides };
}

function details(exported: RectificationDetails['notice']['export'] = null): RectificationDetails {
    return { history: [], notice: { generic: GENERIC, export: exported } };
}

function jsonResponse(body: unknown, status = 200): Response {
    return { ok: status < 400, status, json: async () => body } as Response;
}

async function mountDialog(
    rectification: RectificationState,
    options: { byLevel?: boolean; notice?: RectificationDetails; compact?: boolean } = {},
): Promise<VueWrapper> {
    vi.mocked(fetch).mockResolvedValue(jsonResponse(options.notice ?? details()));

    const byLevel = options.byLevel ?? true;
    const wrapper = mount(RectifyDialog, {
        props: {
            rectification,
            scale: { label: 'Nível atribuído', classifies_by_level: byLevel, levels: byLevel ? levels : [], min_value: '0', max_value: '20' },
            reasons,
            current: byLevel ? { text: '4 — Bom', levelId: 4, value: null } : { text: '16', levelId: null, value: '16.000' },
            studentName: 'Carolina Nunes',
            submitUrl: '/classes/c1/classifications/p1/e1/rectify',
            detailsUrl: '/classes/c1/classifications/cla-1/rectification',
            compact: options.compact ?? false,
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);

    return wrapper;
}

async function open(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-testid="rectify-button"]').trigger('click');
    await flushPromises();
}

const submit = (wrapper: VueWrapper) => wrapper.get('[data-testid="rectify-submit"]');

beforeEach(() => {
    mocks.post.mockReset();
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('RectifyDialog — o botão', () => {
    it('aparece quando o servidor permite', async () => {
        const wrapper = await mountDialog(state());

        const button = wrapper.get('[data-testid="rectify-button"]');
        expect(button.text()).toContain('Retificar');
        expect(button.attributes('disabled')).toBeUndefined();
    });

    it('fica desativado, com o motivo do servidor à vista, quando não é permitido', async () => {
        const reason = 'Período encerrado. Só o responsável da instituição pode retificar.';
        const wrapper = await mountDialog(state({ allowed: false, blocked_reason: reason }));

        const button = wrapper.get('[data-testid="rectify-button"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBe(reason);
        expect(wrapper.get('[data-testid="rectify-blocked-reason"]').text()).toBe(reason);
    });

    it('cumpre o alvo táctil do DESIGN.md (44px em mobile) em todas as formas', async () => {
        const reason = 'Período encerrado. Só o responsável da instituição pode retificar.';
        const forms: Array<[string, RectificationState, boolean]> = [
            ['normal', state(), false],
            ['compacto', state(), true],
            ['normal desativado', state({ allowed: false, blocked_reason: reason }), false],
            ['compacto desativado', state({ allowed: false, blocked_reason: reason }), true],
        ];

        for (const [label, rectification, compact] of forms) {
            const wrapper = await mountDialog(rectification, { compact });
            const classes = wrapper.get('[data-testid="rectify-button"]').classes();

            expect(classes, label).toContain('min-h-11');
            expect(classes, label).toContain('md:min-h-9');
            expect(classes, label).toContain('whitespace-nowrap');

            if (compact) {
                expect(classes, label).toContain('min-w-11');
                expect(classes, label).toContain('md:min-w-9');
            }
        }
    });

    it('o motivo do bloqueio tem largura própria em mobile, para não ficar espremido sob o botão', async () => {
        const wrapper = await mountDialog(state({ allowed: false, blocked_reason: 'Período encerrado.' }));

        expect(wrapper.get('[data-testid="rectify-blocked-reason"]').classes()).toEqual(expect.arrayContaining(['w-44', 'md:w-auto', 'md:max-w-56']));
    });

    it('os botões do diálogo também cumprem o alvo táctil', async () => {
        const wrapper = await mountDialog(state());
        await open(wrapper);

        expect(wrapper.get('[data-testid="rectify-submit"]').classes()).toContain('min-h-11');
        expect(wrapper.get('[data-testid="rectify-cancel"]').classes()).toContain('min-h-11');
    });

    it('não desenha nada quando não é permitido e não há motivo', async () => {
        const wrapper = await mountDialog(state({ allowed: false, blocked_reason: null }));

        expect(wrapper.find('[data-testid="rectify-button"]').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('Retificar');
    });
});

describe('RectifyDialog — o diálogo', () => {
    it('pede o endpoint ao abrir e mostra o valor atual e o aviso genérico', async () => {
        const wrapper = await mountDialog(state());
        expect(fetch).not.toHaveBeenCalled();

        await open(wrapper);

        expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/classes/c1/classifications/cla-1/rectification');
        expect(wrapper.get('[data-testid="rectify-current"]').text()).toBe('4 — Bom');
        expect(wrapper.get('[data-testid="rectify-notice-generic"]').text()).toBe(GENERIC);
        expect(wrapper.find('[data-testid="rectify-notice-export"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="rectify-accumulated"]').text()).toBe('A classificação acumulada não é alterada automaticamente.');
    });

    it('só mostra o aviso de exportação quando existe', async () => {
        const notice = details({ at: '2026-10-20T10:00:00+01:00', adapter: 'inovar', moment_label: '1.º período' });
        const wrapper = await mountDialog(state(), { notice });
        await open(wrapper);

        expect(wrapper.get('[data-testid="rectify-notice-export"]').text()).toBe(
            'Exportada para «Inovar» em 20/10/2026 (1.º período). Atualize esse registo.',
        );
    });

    it('a caixa de confirmação só existe com needs_confirmation e bloqueia o botão enquanto desmarcada', async () => {
        const plain = await mountDialog(state());
        await open(plain);
        expect(plain.find('[data-testid="rectify-confirmation"]').exists()).toBe(false);

        const closed = await mountDialog(state({ needs_confirmation: true }));
        await open(closed);
        expect(closed.get('[data-testid="rectify-confirmation"]').text()).toBe(
            'O período está encerrado. Confirmo que quero alterar uma classificação já publicada.',
        );

        await closed.get('[data-testid="rectify-level"]').setValue(3);
        await closed.get('[data-testid="rectify-reason"]').setValue('entry_error');
        expect(submit(closed).attributes('disabled')).toBeDefined();

        await closed.get('[data-testid="rectify-confirm-box"]').setValue(true);
        expect(submit(closed).attributes('disabled')).toBeUndefined();
    });

    it('exige nota quando o motivo o pede, e só então', async () => {
        const wrapper = await mountDialog(state());
        await open(wrapper);

        await wrapper.get('[data-testid="rectify-level"]').setValue(3);
        await wrapper.get('[data-testid="rectify-reason"]').setValue('entry_error');
        expect(wrapper.get('[data-testid="rectify-note"]').attributes('required')).toBeUndefined();
        expect(submit(wrapper).attributes('disabled')).toBeUndefined();

        await wrapper.get('[data-testid="rectify-reason"]').setValue('other');
        expect(wrapper.get('[data-testid="rectify-note"]').attributes('required')).toBeDefined();
        expect(submit(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="rectify-note"]').setValue('   ');
        expect(submit(wrapper).attributes('disabled')).toBeDefined();

        await wrapper.get('[data-testid="rectify-note"]').setValue('Conselho de turma.');
        expect(submit(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('não deixa submeter com o mesmo valor do atual, num nível nem num número', async () => {
        const level = await mountDialog(state());
        await open(level);
        await level.get('[data-testid="rectify-reason"]').setValue('entry_error');
        expect(submit(level).attributes('disabled')).toBeDefined();

        const number = await mountDialog(state(), { byLevel: false });
        await open(number);
        await number.get('[data-testid="rectify-reason"]').setValue('entry_error');
        expect(submit(number).attributes('disabled')).toBeDefined();

        await number.get('[data-testid="rectify-number"]').setValue('16.0');
        expect(submit(number).attributes('disabled')).toBeDefined();

        await number.get('[data-testid="rectify-number"]').setValue('14');
        expect(submit(number).attributes('disabled')).toBeUndefined();
    });

    it('submete o corpo certo num nível, com lock_version e closed_confirmed', async () => {
        const wrapper = await mountDialog(state({ needs_confirmation: true }));
        await open(wrapper);

        await wrapper.get('[data-testid="rectify-level"]').setValue(3);
        await wrapper.get('[data-testid="rectify-reason"]').setValue('other');
        await wrapper.get('[data-testid="rectify-note"]').setValue(' Conselho de turma. ');
        await wrapper.get('[data-testid="rectify-confirm-box"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        const [url, body] = mocks.post.mock.calls[0];
        expect(url).toBe('/classes/c1/classifications/p1/e1/rectify');
        expect(body).toEqual({
            reason: 'other',
            note: 'Conselho de turma.',
            final_scale_level_id: 3,
            final_value: null,
            lock_version: 7,
            closed_confirmed: true,
            scope: 'period',
        });
    });

    it('numa escala de intervalo envia só o número; sem encerramento, closed_confirmed é falso', async () => {
        const wrapper = await mountDialog(state(), { byLevel: false });
        await open(wrapper);

        await wrapper.get('[data-testid="rectify-number"]').setValue('14.5');
        await wrapper.get('[data-testid="rectify-reason"]').setValue('entry_error');
        await wrapper.get('form').trigger('submit');

        expect(mocks.post.mock.calls[0][1]).toEqual({
            reason: 'entry_error',
            note: null,
            final_scale_level_id: null,
            final_value: '14.5',
            lock_version: 7,
            closed_confirmed: false,
            scope: 'period',
        });
    });

    it('mostra a recusa do servidor e fecha ao sucesso', async () => {
        const wrapper = await mountDialog(state());
        await open(wrapper);
        await wrapper.get('[data-testid="rectify-level"]').setValue(3);
        await wrapper.get('[data-testid="rectify-reason"]').setValue('entry_error');
        await wrapper.get('form').trigger('submit');

        const options = mocks.post.mock.calls[0][2];
        options.onError({ rectify: 'Esta classificação já foi retificada. Recarregue a página para ver o valor atual.' });
        await flushPromises();
        expect(wrapper.text()).toContain('Esta classificação já foi retificada.');
        expect(wrapper.find('[data-dialog]').exists()).toBe(true);

        options.onSuccess();
        await flushPromises();
        expect(wrapper.find('[data-dialog]').exists()).toBe(false);
    });

    it('numa conta pessoal nunca fala em «responsável»', async () => {
        const wrapper = await mountDialog(state({ needs_confirmation: true }));
        await open(wrapper);

        expect(wrapper.html()).not.toContain('esponsável');
    });
});
