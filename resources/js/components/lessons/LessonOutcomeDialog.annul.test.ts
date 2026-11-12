import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { AnnulTaught, NotHeldOptions } from '@/lib/lessonOutcomeOptions';
import LessonOutcomeDialog from './LessonOutcomeDialog.vue';

const posted = vi.hoisted(() => [] as { url: string; data: Record<string, unknown>; method?: string }[]);

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, {
            transform: (fn: (current: Record<string, unknown>) => Record<string, unknown>) => ({
                post: (url: string) => posted.push({ url, data: fn({ ...form }) }),
                patch: (url: string) => posted.push({ url, method: 'patch', data: fn({ ...form }) }),
            }),
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

const options: NotHeldOptions = {
    causes: [
        { value: 'strike', label: 'Greve' },
        { value: 'weather_closure', label: 'Encerramento por mau tempo' },
        { value: 'other', label: 'Outra causa' },
    ],
    specifications: [{ value: 'school_closed', label: 'Greve — escola encerrada' }],
};

function mountAnnul(annul: AnnulTaught) {
    return mount(LessonOutcomeDialog, {
        props: {
            open: true,
            lessonUlid: 'lesson-1',
            reasons: [{ value: 'training', label: 'Formação' }],
            notHeldOptions: options,
            annul,
        },
        global: { stubs: { teleport: true } },
    });
}

/**
 * ANULAR UM «LECIONADA» (0.194.0). O diálogo não oferece escolha de resultado —
 * é sempre «Aula não realizada» —, pede a causa e o motivo, diz ao professor o
 * que vai acontecer ao número, às faltas e ao sumário, e envia para a rota de
 * anulação, nunca para a do registo.
 */
describe('LessonOutcomeDialog — anular «Lecionada»', () => {
    beforeEach(() => {
        posted.length = 0;
    });

    it('abre só com «Aula não realizada», pede causa e motivo, e avisa do que vai acontecer', () => {
        const wrapper = mountAnnul({ summary_destination: 'history', lesson_number: 34, closed_after: '13/11/2026' });

        expect(wrapper.find('input[name="outcome"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Corrigir: a aula não se realizou');

        const warning = wrapper.get('[data-testid="annul-warning"]').text().replace(/\s+/g, ' ');
        expect(warning).toContain('perde o n.º 34');
        expect(warning).toContain('faltas registadas nesta aula são apagadas');
        expect(warning).toContain('13/11/2026');
        expect(warning).toContain('fica no histórico');

        expect(wrapper.find('#outcome-cause').exists()).toBe(true);
        expect(wrapper.find('#outcome-rectification-note').exists()).toBe(true);
        expect(wrapper.get('button[type="submit"]').text()).toContain('Anular lecionada');
    });

    it('sem aulas fechadas a seguir diz que o sumário passa para a aula seguinte', () => {
        const wrapper = mountAnnul({ summary_destination: 'next', lesson_number: 12, closed_after: null });

        expect(wrapper.get('[data-testid="annul-warning"]').text()).toContain('passam para a aula seguinte');
    });

    it('para a direção, sem destino conhecido, explica as duas possibilidades', () => {
        const wrapper = mountAnnul({ summary_destination: 'history', lesson_number: null, closed_after: null });

        const warning = wrapper.get('[data-testid="annul-warning"]').text().replace(/\s+/g, ' ');
        expect(warning).toContain('passa para a aula seguinte ou');
        expect(warning).not.toContain('perde o n.º');
    });

    it('envia PATCH para a rota de anulação com a causa e o motivo, e nada mais', async () => {
        const wrapper = mountAnnul({ summary_destination: 'next', lesson_number: 12, closed_after: null });

        await wrapper.get('#outcome-cause').setValue('weather_closure');
        await wrapper.get('#outcome-rectification-note').setValue('Escola encerrada por mau tempo.');
        await wrapper.get('form').trigger('submit');

        expect(posted).toHaveLength(1);
        expect(posted[0].url).toBe('/lessons/lesson-1/outcome/annul');
        expect(posted[0].method).toBe('patch');
        expect(posted[0].data).toEqual({ cause: 'weather_closure', rectification_note: 'Escola encerrada por mau tempo.' });
    });

    it('não envia sem motivo', async () => {
        const wrapper = mountAnnul({ summary_destination: 'next', lesson_number: 12, closed_after: null });

        await wrapper.get('#outcome-cause').setValue('weather_closure');
        await wrapper.get('form').trigger('submit');

        expect(posted).toHaveLength(0);
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    });
});
