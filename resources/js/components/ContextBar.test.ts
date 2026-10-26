import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { ScopeClassOption, SelectableAcademicYear, WorkScope } from '@/types';
import ContextBar from './ContextBar.vue';

/**
 * A barra de contexto nos três tipos de página: o painel (só o ano letivo),
 * uma página de turma (ano letivo + chip da turma com a lista para trocar) e
 * uma página sem turma. E, acima de tudo: NENHUM botão desligado — os chips
 * «Disciplina», «Ano», «Turma —» e «Período» foram-se.
 */
const mocks = vi.hoisted(() => ({
    props: {} as { workContext: WorkScope; selectableAcademicYears: SelectableAcademicYear[] },
    post: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { post: mocks.post },
    usePage: () => ({ props: mocks.props }),
}));

// Os menus do Reka precisam de uma raiz em contexto e só abrem ao clicar; aqui
// interessa o que existe e para onde aponta, não a mecânica do menu.
vi.mock('@/components/ui/dropdown-menu', () => {
    // Os atributos passam (classe, clique): é por eles que o teste encontra e
    // aciona cada entrada.
    const passthrough = (tag: string) =>
        defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h(tag, attrs, slots.default?.()) });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuItem: passthrough('div'),
        DropdownMenuSeparator: passthrough('hr'),
        DropdownMenuTrigger: passthrough('div'),
    };
});

const years: SelectableAcademicYear[] = [
    { ulid: 'Y27', label: '2026/27', is_current: true },
    { ulid: 'Y26', label: '2025/26', is_current: false },
];

function option(ulid: string, label: string, href: string, current = false): ScopeClassOption {
    return { ulid, label, subject: 'Português', gradeLevel: '7.º', href, is_current: current };
}

function bar(scope: WorkScope, selectableAcademicYears: SelectableAcademicYear[] = years) {
    mocks.props = { workContext: scope, selectableAcademicYears };

    return mount(ContextBar);
}

afterEach(() => {
    mocks.post.mockReset();
});

describe('ContextBar', () => {
    it('no painel mostra só o ano letivo em vigor, sem nenhum chip desligado', () => {
        const wrapper = bar({ academicYear: '2026/27', class: null, classes: [] });

        expect(wrapper.find('[data-testid="academic-year-chip"]').text()).toContain('2026/27');
        expect(wrapper.find('[data-testid="class-chip"]').exists()).toBe(false);
        expect(wrapper.findAll('button[disabled]')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Disciplina');
        expect(wrapper.text()).not.toContain('Período');
        expect(wrapper.text()).toContain('Gerir anos letivos');
    });

    it('numa página de turma acrescenta o chip da turma, com a lista para trocar e «Todas as turmas»', () => {
        const wrapper = bar({
            academicYear: '2026/27',
            class: { ulid: 'A', label: '7.º A', subject: 'Português', gradeLevel: '7.º' },
            classes: [option('A', '7.º A', '/classes/A/records', true), option('B', '7.º B', '/classes/B/records')],
        });

        const chip = wrapper.find('[data-testid="class-chip"]');

        expect(chip.text()).toContain('7.º A');
        expect(chip.text()).toContain('Português');

        const options = wrapper.findAll('[data-testid="class-option"]');

        expect(options).toHaveLength(2);
        expect(options[0].attributes('href')).toBe('/classes/A/records');
        expect(options[0].attributes('aria-current')).toBe('true');
        expect(options[1].attributes('href')).toBe('/classes/B/records');
        expect(options[1].attributes('aria-current')).toBeUndefined();
        expect(wrapper.find('a[href="/classes"]').text()).toContain('Todas as turmas');
    });

    it('numa página sem turma em vista fica só o ano letivo', () => {
        const wrapper = bar({ academicYear: '2026/27', class: null, classes: [] });

        expect(wrapper.findAll('[data-context-bar] > *')).toHaveLength(1);
    });

    it('sem anos letivos, o chip leva a configurar um', () => {
        const wrapper = bar({ academicYear: null, class: null, classes: [] }, []);

        const chip = wrapper.find('[data-testid="academic-year-chip"]');

        expect(chip.element.tagName).toBe('A');
        expect(chip.attributes('href')).toBe('/academic-years');
        expect(chip.text()).toContain('Sem anos letivos configurados');
    });

    it('escolher outro ano letivo pede-o ao servidor; o atual não', async () => {
        const wrapper = bar({ academicYear: '2026/27', class: null, classes: [] });
        const items = wrapper.findAll('[data-context-bar] div.cursor-pointer');

        await items[0].trigger('click');
        expect(mocks.post).not.toHaveBeenCalled();

        await items[1].trigger('click');
        expect(mocks.post).toHaveBeenCalledWith('/academic-years/Y26/select', {}, { preserveScroll: true });
    });
});
