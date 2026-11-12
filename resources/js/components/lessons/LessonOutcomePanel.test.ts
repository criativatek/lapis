import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';
import LessonOutcomePanel from './LessonOutcomePanel.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, { transform: () => ({ post: vi.fn() }), reset: vi.fn() });
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

function mountOpen(extra: Record<string, unknown> = {}) {
    return mount(LessonOutcomePanel, {
        props: {
            ...extra,
            lessonUlid: 'lesson-a',
            outcome: null,
            outcomeLabel: null,
            outcomeReasonLabel: null,
            outcomeNote: null,
            canRecord: true,
            reasons: [
                { value: 'training', label: 'Formação' },
                { value: 'official_duty', label: 'Serviço oficial' },
                { value: 'other', label: 'Outro' },
            ],
            pendingPlan: null,
        },
        global: { stubs: { teleport: true } },
        attachTo: document.body,
    });
}

describe('LessonOutcomePanel', () => {
    it('com texto por guardar pede para guardar primeiro, grava pela página e desativa «Registar»', async () => {
        const saveFirst = vi.fn().mockResolvedValue(true);
        const wrapper = mountOpen({ unsavedChanges: true, saveFirst });
        await wrapper.get('[data-testid="open-outcome-dialog"]').trigger('click');

        expect(document.body.textContent).toContain('Tens alterações por guardar no sumário desta aula.');
        expect(document.body.textContent).toContain('a preparação que passa para a aula seguinte é a que está guardada');

        const register = Array.from(document.querySelectorAll('button')).find((button) => button.textContent?.trim() === 'Registar');
        expect(register?.disabled).toBe(true);

        (document.querySelector('[data-testid="outcome-save-first"]') as HTMLButtonElement).click();
        await wrapper.vm.$nextTick();

        expect(saveFirst).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('sem texto por guardar não mostra a frase e «Registar» está disponível', async () => {
        const wrapper = mountOpen();
        await wrapper.get('[data-testid="open-outcome-dialog"]').trigger('click');

        expect(document.querySelector('[data-testid="outcome-unsaved"]')).toBeNull();
        const register = Array.from(document.querySelectorAll('button')).find((button) => button.textContent?.trim() === 'Registar');
        expect(register?.disabled).toBe(false);
        wrapper.unmount();
    });

    it('offers only categories for a teacher absence, and «Outro» opens no free text', async () => {
        const wrapper = mountOpen();
        await wrapper.get('[data-testid="open-outcome-dialog"]').trigger('click');

        const select = document.querySelector('select[name="reason"]') as HTMLSelectElement;
        expect(Array.from(select.options).map((option) => option.value)).toEqual(['', 'training', 'official_duty', 'other']);

        select.value = 'other';
        select.dispatchEvent(new Event('change'));
        await wrapper.vm.$nextTick();

        expect(document.querySelectorAll('textarea').length).toBe(0);
        expect(document.querySelector('input[name="note"]')).toBeNull();
        expect(document.querySelector('input[name="reason"]')).toBeNull();
        wrapper.unmount();
    });

    it('keeps the optional 160-char activity note with a minimization hint', async () => {
        const wrapper = mountOpen();
        await wrapper.get('[data-testid="open-outcome-dialog"]').trigger('click');

        const external = document.querySelector('input[value="class_external_activity"]') as HTMLInputElement;
        external.checked = true;
        external.dispatchEvent(new Event('change'));
        await wrapper.vm.$nextTick();

        const note = document.querySelector('input[name="note"]') as HTMLInputElement;
        expect(note.maxLength).toBe(160);
        expect(note.required).toBe(false);
        expect(document.body.textContent).toContain('Evite incluir dados pessoais desnecessários.');
        wrapper.unmount();
    });

    it.each([
        ['teacher_absent', 'amber'],
        ['class_external_activity', 'violet'],
    ] as const)('paints a recorded %s outcome with the house palette (%s), not hand-written colours', (outcome, tone) => {
        const wrapper = mount(LessonOutcomePanel, {
            props: {
                lessonUlid: 'lesson-a',
                outcome,
                outcomeLabel: 'Resultado',
                outcomeReasonLabel: null,
                outcomeNote: null,
                canRecord: false,
                reasons: [],
                pendingPlan: null,
            },
        });

        const panel = wrapper.get('[data-testid="lesson-outcome"]');

        for (const toneClass of qualitativeToneClasses[tone].split(' ')) {
            expect(panel.classes()).toContain(toneClass);
        }

        // As classes antigas, escritas à mão: fundo 50 e borda 300.
        expect(panel.classes().filter((name) => /^(dark:)?(bg|border)-(amber|violet)-(50|300|900)$/.test(name))).toEqual([]);
        wrapper.unmount();
    });

    it('mostra o motivo a quem o registou e só diz que está reservado a quem não o registou', () => {
        const base = {
            lessonUlid: 'lesson-a',
            outcome: 'teacher_absent' as const,
            outcomeLabel: 'Professor ausente',
            outcomeNote: null,
            canRecord: false,
            reasons: [],
            pendingPlan: null,
        };

        const own = mount(LessonOutcomePanel, { props: { ...base, outcomeReasonLabel: 'Formação', outcomeReasonReserved: false } });
        expect(own.text()).toContain('Motivo: Formação');
        expect(own.find('[data-testid="lesson-outcome-reason-reserved"]').exists()).toBe(false);

        const colleague = mount(LessonOutcomePanel, { props: { ...base, outcomeReasonLabel: null, outcomeReasonReserved: true } });
        expect(colleague.text()).not.toContain('Formação');
        expect(colleague.get('[data-testid="lesson-outcome-reason-reserved"]').text()).toContain('reservado');
    });
});

/** Anular um «Lecionada» (0.194.0): só quando o servidor o permite. */
describe('LessonOutcomePanel — anular lecionada', () => {
    const notHeldOptions = { causes: [{ value: 'weather_closure', label: 'Encerramento por mau tempo' }], specifications: [] };

    function mountTaught(extra: Record<string, unknown> = {}) {
        return mount(LessonOutcomePanel, {
            props: {
                lessonUlid: 'lesson-a',
                outcome: 'taught',
                outcomeLabel: 'Lecionada',
                outcomeReasonLabel: null,
                outcomeNote: null,
                canRecord: false,
                reasons: [],
                pendingPlan: null,
                notHeldOptions,
                ...extra,
            },
            global: { stubs: { teleport: true } },
            attachTo: document.body,
        });
    }

    it('mostra «Corrigir: a aula não se realizou» quando a página diz que se pode anular', async () => {
        const wrapper = mountTaught({ annulTaught: { summary_destination: 'next', lesson_number: 34, closed_after: null } });

        expect(wrapper.get('[data-testid="open-annul-dialog"]').text()).toContain('Corrigir: a aula não se realizou');
        await wrapper.get('[data-testid="open-annul-dialog"]').trigger('click');
        expect(document.body.textContent).toContain('perde o n.º 34');
        wrapper.unmount();
    });

    it('não o mostra sem a prop', () => {
        const wrapper = mountTaught();

        expect(wrapper.find('[data-testid="open-annul-dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });
});
