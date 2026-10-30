import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ReconciliationPlan } from '@/lib/calendarReconciliation';
import CalendarImpactDialog from './CalendarImpactDialog.vue';

// O diálogo real teleporta para fora da árvore; aqui renderiza-se inline, e só
// enquanto está aberto.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) });

    return {
        Dialog: defineComponent({
            props: { open: { type: Boolean, default: false } },
            setup: (props, { slots }) => () => (props.open ? h('div', slots.default?.()) : null),
        }),
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const wrappers: VueWrapper[] = [];

function plan(overrides: Partial<ReconciliationPlan> = {}): ReconciliationPlan {
    return {
        plan_token: 'a'.repeat(64),
        scope: 'organization_owner',
        operation: 'create',
        counts: { A: 3, B: 2, C: 1 },
        totals: { retired: 3, shifted: 0, awaiting_teacher: 2, flagged: 1 },
        classes: [
            { class: 'c1', class_label: '8.º A', retired: 2, shifted: 0, awaiting_teacher: 2, flagged: 0, renumbered: 0, gaps: [4, 5] },
            { class: 'c2', class_label: '9.º B', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 1, renumbered: 0, gaps: [] },
        ],
        gaps: [{ class: 'c1', class_label: '8.º A', numbers: [4, 5] }],
        freed_days: [],
        existing_on_freed_days: { count: 0 },
        changes_anything: true,
        ...overrides,
    };
}

function mountDialog(props: Record<string, unknown> = {}) {
    const wrapper = mount(CalendarImpactDialog, {
        props: { open: true, plan: plan(), ...props },
        attachTo: document.body,
    });
    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('CalendarImpactDialog', () => {
    it('says, per class, how many lessons still to be given change number — apart, with why, and only where some do', () => {
        const wrapper = mountDialog({
            plan: plan({
                classes: [
                    { class: 'c1', class_label: '8.º A', retired: 2, shifted: 0, awaiting_teacher: 2, flagged: 0, renumbered: 1, gaps: [] },
                    { class: 'c2', class_label: '9.º B', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 1, renumbered: 4, gaps: [] },
                    { class: 'c3', class_label: '7.º C', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0, renumbered: 0, gaps: [] },
                ],
                gaps: [],
            }),
        });
        const numbering = wrapper.get('[data-testid="impact-numbering"]');
        const lines = numbering.findAll('[data-testid="impact-numbering-class"]').map((item) => item.text());

        expect(numbering.text()).toContain('Atenção à numeração');
        expect(lines).toEqual(['8.º A: 1 aula por dar muda de número.', '9.º B: 4 aulas por dar mudam de número.']);
        expect(numbering.text()).toContain('As aulas por dar ocupam o lugar das retiradas; as lecionadas mantêm o número.');
        // Já não está em «Vai ser preservado».
        expect(wrapper.get('[data-testid="impact-kept"]').text()).not.toContain('número');
    });

    it('shows the three sections, per class, with the numbering reassurance', () => {
        const wrapper = mountDialog();
        const text = wrapper.text();

        expect(wrapper.find('[data-testid="impact-fixed"]').text()).toContain('Vai ser corrigido');
        expect(wrapper.find('[data-testid="impact-fixed"]').text()).toContain('3 aulas vazias são retiradas');
        expect(wrapper.find('[data-testid="impact-fixed"]').text()).toContain('8.º A: 2 aulas');
        expect(wrapper.find('[data-testid="impact-kept"]').text()).toContain('Nenhum planeamento, sumário ou registo é apagado.');
        expect(wrapper.find('[data-testid="impact-planning"]').text()).toContain('2 aulas têm planeamento e ficam como estão. O planeamento não se apaga');
        expect(wrapper.find('[data-testid="impact-planning"]').text()).not.toContain('próxima aula válida');
        expect(wrapper.get('[data-testid="impact-numbering"]').text()).toContain('8.º A: as lições 4 e 5 ficam sem aula.');
        expect(text).toContain('As aulas por dar ocupam o lugar das retiradas; as lecionadas mantêm o número.');
        expect(wrapper.find('[data-testid="impact-review"]').text()).toContain('1 aula nesses dias fica como está: não pode ser corrigida automaticamente');
    });

    it('counts the lessons to review per class and per reason, in plain words', () => {
        const wrapper = mountDialog({
            plan: plan({
                totals: { retired: 3, shifted: 0, awaiting_teacher: 2, flagged: 3 },
                classes: [
                    {
                        class: 'c1',
                        class_label: '7.º A',
                        retired: 2,
                        shifted: 0,
                        awaiting_teacher: 2,
                        flagged: 3,
                        renumbered: 0,
                        gaps: [],
                        review: [
                            { count: 1, label: 'faz parte de uma lição partilhada com o grupo T2, que continua a ter aula' },
                            { count: 2, label: 'já foi lecionada' },
                        ],
                    },
                ],
            }),
        });

        expect(wrapper.findAll('[data-testid="impact-review-line"]').map((line) => line.text())).toEqual([
            '7.º A: 1 aula (faz parte de uma lição partilhada com o grupo T2, que continua a ter aula)',
            '7.º A: 2 aulas (já foi lecionada)',
        ]);
        expect(wrapper.get('[data-testid="impact-review"]').text()).toContain('3 aulas nesses dias ficam como estão');
    });

    it('says one empty lesson in the singular', () => {
        const wrapper = mountDialog({
            plan: plan({
                totals: { retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0 },
                classes: [{ class: 'c1', class_label: '8.º A', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0, renumbered: 0, gaps: [] }],
                gaps: [],
            }),
        });

        expect(wrapper.get('[data-testid="impact-fixed"]').text()).toContain('1 aula vazia é retirada: não tem nada escrito');
        expect(wrapper.get('[data-testid="impact-fixed"]').text()).not.toContain('não têm');
    });

    it('closes from a corner button with a 44 px target', async () => {
        const wrapper = mountDialog();
        const close = wrapper.get('[data-testid="impact-x"]');

        expect(close.attributes('aria-label')).toBe('Fechar');
        expect(close.classes()).toContain('size-11');
        await close.trigger('click');
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('hides the sections that have nothing to say', () => {
        const wrapper = mountDialog({
            plan: plan({
                totals: { retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0 },
                classes: [{ class: 'c1', class_label: '8.º A', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0, renumbered: 0, gaps: [] }],
                gaps: [],
            }),
        });

        expect(wrapper.find('[data-testid="impact-review"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="impact-planning"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="impact-numbering"]').exists()).toBe(false);
    });

    it('says days become teaching days again and that no lesson is deleted', () => {
        const wrapper = mountDialog({
            plan: plan({
                changes_anything: false,
                totals: { retired: 0, shifted: 0, awaiting_teacher: 0, flagged: 0 },
                classes: [],
                gaps: [],
                freed_days: ['2026-12-21', '2026-12-22'],
                existing_on_freed_days: { count: 1 },
            }),
        });

        expect(wrapper.find('[data-testid="impact-freed"]').text()).toContain('2 dias voltam a ser letivos');
        expect(wrapper.text()).toContain('nenhuma aula é apagada');
        expect(wrapper.text()).toContain('1 aula já existe nesses dias e continua como está');
        // Nada a aplicar às aulas: só «Guardar sem alterar as aulas».
        expect(wrapper.find('[data-testid="impact-confirm"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="impact-save-only"]').exists()).toBe(true);
    });

    it('never says «nenhuma aula é apagada» when shortening also retires empty lessons', () => {
        const wrapper = mountDialog({
            plan: plan({
                operation: 'update',
                totals: { retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0 },
                classes: [{ class: 'c1', class_label: '8.º A', retired: 1, shifted: 0, awaiting_teacher: 0, flagged: 0, renumbered: 0, gaps: [] }],
                gaps: [],
                freed_days: ['2027-01-01', '2027-01-02'],
                existing_on_freed_days: { count: 0 },
            }),
        });

        expect(wrapper.get('[data-testid="impact-freed"]').text()).toContain('2 dias voltam a ser letivos');
        expect(wrapper.text()).not.toContain('nenhuma aula é apagada');
        expect(wrapper.get('[data-testid="impact-fixed"]').text()).toContain('1 aula vazia é retirada');
    });

    it('lets one save without touching the lessons when the plan could not be checked again', () => {
        const wrapper = mountDialog({ plan: null, message: 'Não foi possível voltar a verificar as aulas.' });

        expect(wrapper.get('[data-testid="impact-save-only"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.get('[data-testid="impact-confirm"]').attributes('disabled')).toBeDefined();
    });

    it('emits confirm and saveOnly from the right buttons', async () => {
        const wrapper = mountDialog();

        await wrapper.get('[data-testid="impact-confirm"]').trigger('click');
        await wrapper.get('[data-testid="impact-save-only"]').trigger('click');

        expect(wrapper.emitted('confirm')).toHaveLength(1);
        expect(wrapper.emitted('saveOnly')).toHaveLength(1);
        expect(wrapper.get('[data-testid="impact-confirm"]').text()).toBe('Confirmar e aplicar às aulas');
        expect(wrapper.get('[data-testid="impact-save-only"]').text()).toBe('Guardar sem alterar as aulas');
    });

    it('closes through «Cancelar» without emitting a decision', async () => {
        const wrapper = mountDialog();

        await wrapper.get('[data-testid="impact-cancel"]').trigger('click');

        expect(wrapper.emitted('update:open')).toEqual([[false]]);
        expect(wrapper.emitted('confirm')).toBeUndefined();
        expect(wrapper.emitted('saveOnly')).toBeUndefined();
    });

    it('disables the buttons while loading or saving, and shows the refusal message', () => {
        const wrapper = mountDialog({ loading: true, message: 'O calendário ou as aulas mudaram entretanto.' });

        expect(wrapper.get('[data-testid="impact-confirm"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="impact-save-only"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="impact-message"]').text()).toContain('mudaram entretanto');
        // Enquanto recalcula não mostra o plano antigo.
        expect(wrapper.find('[data-testid="impact-preview"]').exists()).toBe(false);
    });

    it('uses plain language: no codes, no internal names', () => {
        const wrapper = mountDialog();

        expect(wrapper.text()).not.toMatch(/plan_token|materializ|ocorrência|\bA\/B\/C\b|reconcile/i);
    });
});
