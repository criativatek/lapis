import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Lessons from './Lessons.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, {
            transform: () => ({ post: vi.fn(), patch: vi.fn() }),
            clearErrors: vi.fn(),
            reset: vi.fn(),
        });
    },
}));

// O diálogo real teleporta para fora da árvore; aqui renderiza-se inline.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = { template: '<div><slot /></div>' };

    return {
        Dialog: passthrough,
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const notHeldOptions = {
    causes: [{ value: 'weather_closure', label: 'Encerramento por mau tempo' }],
    specifications: [],
};

const lessons = [
    { ulid: 'l-1', date: '11/11/2026', time: '10:30', context_label: '7.º C', outcome: 'taught', outcome_label: 'Lecionada', lesson_number: 34, closed_by: 'Nelson Matias', can_annul: true },
    { ulid: 'l-2', date: '09/11/2026', time: '08:30', context_label: '7.º C', outcome: 'not_held', outcome_label: 'Aula não realizada', lesson_number: null, closed_by: 'Nelson Matias', can_annul: false },
];

function mountPage(extra: Record<string, unknown> = {}) {
    return mount(Lessons, {
        props: {
            schoolClass: { ulid: 'cls-1', label: '7.º C', subject: 'Português', academic_year: '2026/2027' },
            lessons,
            annulments: [],
            notHeldOptions,
            ...extra,
        },
        global: { stubs: { teleport: true } },
    });
}

/**
 * «Aulas fechadas» da direção: lista só o que a semana mostra a qualquer colega,
 * oferece «Anular lecionada» apenas nas lecionadas, e o diálogo avisa do que vai
 * acontecer sem conhecer o destino do sumário.
 */
describe('Retificações — aulas fechadas', () => {
    it('lista as aulas fechadas sem sumário nem faltas e só oferece anular nas lecionadas', () => {
        const wrapper = mountPage();

        const rows = wrapper.findAll('[data-testid="closed-lesson"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].text()).toContain('Lecionada');
        expect(rows[0].text()).toContain('34');
        expect(rows[0].text()).toContain('Nelson Matias');
        expect(rows[0].find('[data-testid="annul-lesson"]').exists()).toBe(true);
        expect(rows[1].find('[data-testid="annul-lesson"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Sumário');
        expect(wrapper.text()).not.toContain('Faltas');
    });

    it('«Anular lecionada» abre o diálogo com o aviso das duas possibilidades e o número da aula', async () => {
        const wrapper = mountPage();

        await wrapper.get('[data-testid="annul-lesson"]').trigger('click');

        const warning = wrapper.get('[data-testid="annul-warning"]').text().replace(/\s+/g, ' ');
        expect(warning).toContain('perde o n.º 34');
        expect(warning).toContain('passa para a aula seguinte ou');
        expect(wrapper.text()).toContain('7.º C · 11/11/2026 10:30');
    });

    it('lista as anulações feitas, sem texto de sumário', () => {
        const wrapper = mountPage({
            annulments: [{ ulid: 'r-1', at: '2026-11-12T11:00:00+00:00', by: 'Direção Escola', lesson_date: '11/11/2026', lesson_number: 34, motive: 'Mau tempo.' }],
        });

        const entry = wrapper.get('[data-testid="annulment-entry"]').text().replace(/\s+/g, ' ');
        expect(entry).toContain('Direção Escola');
        expect(entry).toContain('aula de 11/11/2026');
        expect(entry).toContain('tinha o n.º 34');
        expect(entry).toContain('Motivo: Mau tempo.');
    });

    it('com a funcionalidade desligada diz-o e não oferece anular', () => {
        const wrapper = mountPage({ notHeldOptions: null });

        expect(wrapper.find('[data-testid="annulment-feature-off"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="annul-lesson"]').exists()).toBe(false);
    });
});
