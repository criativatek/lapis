import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { computed, defineComponent, h, provide, reactive } from 'vue';
import { notHeldEnabledKey } from '@/lib/lessonOutcomeOptions';
import LessonOutcomePanel from './LessonOutcomePanel.vue';
import LessonQuickActions from './LessonQuickActions.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn() },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, { transform: () => ({ post: vi.fn() }), reset: vi.fn() });
    },
}));

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

// Os menus teleportam; aqui renderizam-se no lugar.
vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = { template: '<div><slot /></div>' };

    return {
        DropdownMenu: passthrough,
        DropdownMenuContent: passthrough,
        DropdownMenuTrigger: passthrough,
        DropdownMenuItem: { emits: ['select'], template: '<button type="button" @click="$emit(\'select\')"><slot /></button>' },
    };
});

const base = {
    lessonUlid: 'lesson-a',
    outcome: 'not_held' as const,
    outcomeLabel: 'Aula não realizada',
    outcomeReasonLabel: null,
    outcomeNote: null,
    canRecord: false,
    reasons: [],
    pendingPlan: null,
};

describe('LessonOutcomePanel — aula não realizada', () => {
    it('ao titular mostra a causa, a sua especificação e a descrição', () => {
        const wrapper = mount(LessonOutcomePanel, {
            props: {
                ...base,
                outcomeCauseLabel: 'Greve',
                outcomeTeacherSituationLabel: 'Aderi à greve',
                outcomeCauseNote: 'Falha de eletricidade',
            },
        });

        expect(wrapper.get('[data-testid="lesson-outcome-cause"]').text()).toBe('Causa: Greve');
        expect(wrapper.get('[data-testid="lesson-outcome-situation"]').text()).toBe('A tua situação: Aderi à greve');
        expect(wrapper.text()).toContain('Falha de eletricidade');
        expect(wrapper.text()).toContain('não é numerada');
    });

    it('a um colega mostra a causa da turma, nunca a especificação, e diz que o resto está reservado', () => {
        const wrapper = mount(LessonOutcomePanel, {
            props: { ...base, outcomeCauseLabel: 'Greve', outcomeReasonReserved: true },
        });

        expect(wrapper.get('[data-testid="lesson-outcome-cause"]').text()).toBe('Causa: Greve');
        expect(wrapper.find('[data-testid="lesson-outcome-situation"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="lesson-outcome-reason-reserved"]').exists()).toBe(true);
    });

    it('uma greve sem especificação não mostra linha de situação', () => {
        const wrapper = mount(LessonOutcomePanel, { props: { ...base, outcomeCauseLabel: 'Greve' } });

        expect(wrapper.find('[data-testid="lesson-outcome-situation"]').exists()).toBe(false);
    });
});

function mountQuick(enabled: boolean, canRecordOutcome = true) {
    const Host = defineComponent({
        setup() {
            provide(notHeldEnabledKey, computed(() => enabled));

            return () => h(LessonQuickActions, { lessonUlid: 'l1', contextLabel: '7.º A', time: '09:30', variant: 'mobile', canRecordOutcome });
        },
    });

    return mount(Host);
}

describe('LessonQuickActions — aula não realizada', () => {
    it('oferece «Aula não realizada» só com a funcionalidade ligada', () => {
        expect(mountQuick(true).find('[data-testid="quick-not-held-mobile"]').exists()).toBe(true);
        expect(mountQuick(false).find('[data-testid="quick-not-held-mobile"]').exists()).toBe(false);
    });

    it('emite o resultado escolhido', async () => {
        const wrapper = mountQuick(true);
        await wrapper.get('[data-testid="quick-not-held-mobile"]').trigger('click');

        expect(wrapper.findComponent(LessonQuickActions).emitted('outcome')).toEqual([['not_held']]);
    });

    it('um observador não vê nenhum resultado para registar, só «Lecionada»', () => {
        const wrapper = mountQuick(true, false);

        expect(wrapper.find('[data-testid="quick-not-held-mobile"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Professor ausente');
        expect(wrapper.text()).toContain('Lecionada');
    });
});
