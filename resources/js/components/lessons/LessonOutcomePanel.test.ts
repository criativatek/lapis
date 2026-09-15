import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
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

function mountOpen() {
    return mount(LessonOutcomePanel, {
        props: {
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
});
