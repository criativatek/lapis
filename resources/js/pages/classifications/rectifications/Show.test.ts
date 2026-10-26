import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { RectificationState } from '@/lib/rectification';
import Show from './Show.vue';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: mocks.post },
}));

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

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
    mocks.post.mockReset();
});

function state(overrides: Partial<RectificationState> = {}): RectificationState {
    return { allowed: true, needs_confirmation: true, blocked_reason: null, lock_version: 3, count: 0, ...overrides };
}

const schoolClass = {
    ulid: 'cls-1',
    label: '7.º A',
    subject: 'Português',
    academic_year: '2025/2026',
    scale_name: 'Escala 1 a 5',
    decision_label: 'Nível atribuído',
    classifies_by_level: true,
    levels: [
        { id: 4, code: '4', label: 'Bom' },
        { id: 3, code: '3', label: 'Suficiente' },
    ],
    min_value: null,
    max_value: null,
};

const rows = [
    {
        enrollment_ulid: 'enr-1',
        class_number: 1,
        name: 'Carolina Nunes',
        classification: {
            ulid: 'cla-1',
            published_at: '2026-07-10T09:00:00+01:00',
            decision: { code: '4', label: 'Bom', scale_level_id: 4, is_percentage: false, scale_name: null },
            rectification: state({ count: 1 }),
        },
    },
    {
        enrollment_ulid: 'enr-2',
        class_number: 2,
        name: 'David Ramos',
        classification: {
            ulid: 'cla-2',
            published_at: '2026-07-10T09:00:00+01:00',
            decision: { code: '65.000', label: null, scale_level_id: null, is_percentage: true, scale_name: 'Percentagem (0 a 100)' },
            rectification: state(),
        },
    },
];

function mountShow(overrides: Record<string, unknown> = {}): VueWrapper {
    const wrapper = mount(Show, {
        props: {
            schoolClass,
            period: { ulid: 'per-1', label: '1.º Semestre' },
            rectificationReasons: [{ value: 'entry_error', label: 'Erro de lançamento', requires_note: false }],
            rows,
            ...overrides,
        },
        attachTo: document.body,
    });
    wrappers.push(wrapper);

    return wrapper;
}

describe('Retificações — tabela de um período', () => {
    it('diz a turma, a disciplina, o ano e o período, e volta à lista', () => {
        const wrapper = mountShow();

        expect(wrapper.text()).toContain('7.º A — 1.º Semestre');
        expect(wrapper.text()).toContain('Português · 2025/2026');
        expect(wrapper.find('a[href="/retificacoes"]').text()).toContain('Voltar a Retificações');
    });

    it('avisa, com discrição, que mostra só as publicadas e que a consulta fica registada', () => {
        expect(mountShow().get('[data-testid="privacy-notice"]').text()).toBe(
            'Esta página mostra apenas as classificações publicadas. A consulta fica registada.',
        );
    });

    it('mostra número, nome, classificação e data pela ordem recebida', () => {
        const rendered = mountShow().findAll('[data-testid="rectification-row"]');

        expect(rendered).toHaveLength(2);
        expect(rendered[0].text()).toContain('Carolina Nunes');
        expect(rendered[0].text()).toContain('10/07/2026');
        expect(rendered[1].text()).toContain('David Ramos');
        // Um valor decidido numa percentagem lê-se «65%», com o aviso da escala no título.
        expect(rendered[1].text()).toContain('65%');
        expect(rendered[1].find('span[title]').attributes('title')).toContain('Percentagem (0 a 100)');
    });

    it('o marcador de histórico só aparece com retificações', () => {
        const rendered = mountShow().findAll('[data-testid="rectification-row"]');

        expect(rendered[0].find('[data-testid="rectified-marker"]').exists()).toBe(true);
        expect(rendered[1].find('[data-testid="rectified-marker"]').exists()).toBe(false);
    });

    it('lê o histórico da classificação certa, na rota desta turma', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ history: [], notice: { generic: '', export: null } }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const first = mountShow().findAll('[data-testid="rectification-row"]')[0];

        await first.get('[data-testid="rectified-marker"]').trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls[0][0]).toBe('/classes/cls-1/classifications/cla-1/rectification');
    });

    it('retifica na rota do período desta página, no âmbito do período', async () => {
        const wrapper = mountShow();
        const second = wrapper.findAll('[data-testid="rectification-row"]')[1];

        await second.get('[data-testid="rectify-button"]').trigger('click');
        await flushPromises();
        await wrapper.get('[data-testid="rectify-level"]').setValue(3);
        await wrapper.get('[data-testid="rectify-reason"]').setValue('entry_error');
        await wrapper.get('[data-testid="rectify-confirm-box"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        const [url, body] = mocks.post.mock.calls[0];
        expect(url).toBe('/classes/cls-1/classifications/per-1/enr-2/rectify');
        expect(body.scope).toBe('period');
        expect(body.lock_version).toBe(3);
    });

    it('o botão «Retificar» tem alvo táctil de 44px em cada linha', () => {
        const triggers = mountShow()
            .findAll('button')
            .filter((button) => button.text().includes('Retificar'));

        expect(triggers).toHaveLength(2);
        triggers.forEach((trigger) => expect(trigger.classes()).toEqual(expect.arrayContaining(['min-h-11', 'md:min-h-9'])));
    });

    it('não desenha médias, propostas, autoavaliação, observações ou fotografias', () => {
        const wrapper = mountShow();
        const text = wrapper.text().toLowerCase();

        for (const forbidden of ['média', 'proposta', 'autoavaliação', 'observação', 'assiduidade', 'pontuação']) {
            expect(text).not.toContain(forbidden);
        }

        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('sem classificações publicadas diz-o', () => {
        expect(mountShow({ rows: [] }).text()).toContain('Sem classificações publicadas neste período.');
    });
});
