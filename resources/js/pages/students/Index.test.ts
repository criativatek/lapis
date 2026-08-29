import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';
import type { DirectoryEnrollment, DirectoryStudent, Paginator, Props } from './Index.vue';

const inertia = vi.hoisted(() => ({
    routerGet: vi.fn(),
    modules: ['students', 'classes', 'student_progress'] as string[],
    readOnlyModules: [] as string[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: { get: inertia.routerGet },
    usePage: () => ({
        props: { modules: inertia.modules, readOnlyModules: inertia.readOnlyModules },
    }),
}));

/**
 * Reka's dropdown only renders its content once a real menu is opened, through
 * a portal. These tests are about WHICH turmas the menu offers and where each
 * entry points — not about menu mechanics — so the primitives are flattened and
 * the entries are simply rendered inline.
 */
vi.mock('@/components/ui/dropdown-menu', () => {
    const passthrough = (tag: string) =>
        defineComponent({ inheritAttrs: false, setup: (_, { slots }) => () => h(tag, slots.default?.()) });

    return {
        DropdownMenu: passthrough('div'),
        DropdownMenuContent: passthrough('div'),
        DropdownMenuItem: passthrough('div'),
        DropdownMenuLabel: passthrough('div'),
        DropdownMenuTrigger: passthrough('div'),
    };
});

function enrollment(overrides: Partial<DirectoryEnrollment> = {}): DirectoryEnrollment {
    return {
        enrollment_ulid: 'enr-1',
        class_ulid: 'cls-1',
        class_label: '7.º A',
        subject: 'Português',
        academic_year: '2026/2027',
        class_number: 3,
        status: 'active',
        status_label: 'Inscrito',
        is_current: true,
        ...overrides,
    };
}

function student(overrides: Partial<DirectoryStudent> = {}): DirectoryStudent {
    return {
        student_ulid: 'stu-1',
        name: 'Ana Silva',
        pseudonym: 'ALU-AB12',
        photo_url: null,
        enrollments: [enrollment()],
        ...overrides,
    };
}

function paginator(data: DirectoryStudent[], overrides: Partial<Paginator> = {}): Paginator {
    return {
        data,
        links: [
            { url: null, label: '&laquo; Anterior', active: false },
            { url: '/students?page=1', label: '1', active: true },
            { url: null, label: 'Seguinte &raquo;', active: false },
        ],
        total: data.length,
        from: data.length === 0 ? null : 1,
        to: data.length === 0 ? null : data.length,
        ...overrides,
    };
}

function mountIndex(overrides: Partial<Props> = {}) {
    const props: Props = {
        students: paginator([student()]),
        filters: { q: '', class: null, year: null, status: null },
        classes: [{ ulid: 'cls-1', label: '7.º A', subject: 'Português', academic_year: '2026/2027' }],
        academicYears: [{ ulid: 'year-1', label: '2026/2027' }],
        statuses: [
            { value: 'active', label: 'Inscrito' },
            { value: 'transferred_out', label: 'Transferido' },
        ],
        ...overrides,
    };

    return mount(Index, { props });
}

beforeEach(() => {
    inertia.routerGet.mockClear();
    inertia.modules = ['students', 'classes', 'student_progress'];
    inertia.readOnlyModules = [];
});

describe('students/Index — o que a página é', () => {
    it('anuncia-se como o diretório das turmas do professor', () => {
        const wrapper = mountIndex();

        expect(wrapper.text()).toContain('Alunos');
        expect(wrapper.text()).toContain('Os alunos das suas turmas.');
    });

    it('mostra o aluno com o pseudónimo e a turma, e nada de pedagógico', () => {
        const wrapper = mountIndex();

        expect(wrapper.text()).toContain('Ana Silva');
        expect(wrapper.text()).toContain('ALU-AB12');
        expect(wrapper.text()).toContain('7.º A');
        expect(wrapper.text()).toContain('Português');
        // The directory finds and navigates; it never interprets.
        expect(wrapper.text()).not.toContain('média');
        expect(wrapper.text()).not.toContain('Atenção');
    });

    it('deriva o estado das inscrições e não de um campo do aluno', () => {
        // Read off the row's own Estado cell, not the page text: «Inscrito» is
        // also one of the filter's options, and matching that would prove
        // nothing about the row.
        const stateCell = (wrapper: ReturnType<typeof mountIndex>) =>
            wrapper.find('tbody tr').findAll('td')[2].text();

        expect(stateCell(mountIndex())).toBe('Inscrito');

        const gone = mountIndex({
            students: paginator([
                student({
                    enrollments: [
                        enrollment({ status: 'transferred_out', status_label: 'Transferido', is_current: false }),
                    ],
                }),
            ]),
        });

        expect(stateCell(gone)).toBe('Transferido');
    });
});

describe('students/Index — acompanhamento', () => {
    it('liga diretamente ao acompanhamento quando há uma só inscrição', () => {
        const wrapper = mountIndex();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Acompanhamento'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/cls-1/evolucao/enr-1');
    });

    it('oferece as turmas em menu quando há várias, sem escolher nenhuma', () => {
        const wrapper = mountIndex({
            students: paginator([
                student({
                    enrollments: [
                        enrollment(),
                        enrollment({ enrollment_ulid: 'enr-2', class_ulid: 'cls-2', class_label: '8.º B' }),
                    ],
                }),
            ]),
        });

        const hrefs = wrapper.findAll('a').map((a) => a.attributes('href'));

        // Both destinations offered, each with ITS OWN turma and inscrição: a
        // reading belongs to the (aluno, turma) pair and picking one for the
        // teacher would be inventing an answer.
        expect(hrefs).toContain('/classes/cls-1/evolucao/enr-1');
        expect(hrefs).toContain('/classes/cls-2/evolucao/enr-2');
    });

    it('aponta para a turma sem recriar as ações de gestão da inscrição', () => {
        const wrapper = mountIndex();

        const link = wrapper.findAll('a').find((a) => a.text().includes('Abrir turma'));

        expect(link).toBeTruthy();
        expect(link!.attributes('href')).toBe('/classes/cls-1');
        // Editing, removing, the N.º de processo, the photos and the import all
        // stay on the class page (§11).
        expect(wrapper.text()).not.toContain('N.º de processo');
        expect(wrapper.text()).not.toContain('Importar');
        expect(wrapper.text()).not.toContain('Remover');
    });

    it('não oferece acompanhamento a quem não tem o módulo', () => {
        inertia.modules = ['students', 'classes'];

        const wrapper = mountIndex();

        expect(wrapper.findAll('a').some((a) => a.text().includes('Acompanhamento'))).toBe(false);
        expect(wrapper.findAll('a').some((a) => a.text().includes('Abrir turma'))).toBe(true);
    });

    it('mantém o acompanhamento numa organização suspensa, que continua a poder consultar', () => {
        inertia.modules = ['students'];
        inertia.readOnlyModules = ['classes', 'student_progress'];

        const wrapper = mountIndex();

        expect(wrapper.findAll('a').some((a) => a.text().includes('Acompanhamento'))).toBe(true);
    });
});

describe('students/Index — filtros', () => {
    it('recarrega no servidor quando a turma muda', async () => {
        const wrapper = mountIndex();

        await wrapper.findAll('select')[0].setValue('cls-1');

        expect(inertia.routerGet).toHaveBeenCalledWith(
            '/students',
            expect.objectContaining({ class: 'cls-1' }),
            expect.anything(),
        );
    });

    it('recarrega no servidor quando o ano letivo ou o estado mudam', async () => {
        const wrapper = mountIndex();

        await wrapper.findAll('select')[1].setValue('year-1');
        expect(inertia.routerGet).toHaveBeenCalledWith(
            '/students',
            expect.objectContaining({ year: 'year-1' }),
            expect.anything(),
        );

        await wrapper.findAll('select')[2].setValue('transferred_out');
        expect(inertia.routerGet).toHaveBeenCalledWith(
            '/students',
            expect.objectContaining({ status: 'transferred_out' }),
            expect.anything(),
        );
    });

    it('a pesquisa só parte quando é submetida, porque só o nome completo é encontrável', async () => {
        const wrapper = mountIndex();

        await wrapper.find('#students-search').setValue('Ana');
        expect(inertia.routerGet).not.toHaveBeenCalled();

        await wrapper.find('form').trigger('submit.prevent');
        expect(inertia.routerGet).toHaveBeenCalledWith(
            '/students',
            expect.objectContaining({ q: 'Ana' }),
            expect.anything(),
        );
    });

    it('diz que a pesquisa do servidor é por nome completo', () => {
        expect(mountIndex().text()).toContain('nome completo');
    });
});

describe('students/Index — filtro desta página', () => {
    const two = [
        student(),
        student({ student_ulid: 'stu-2', name: 'Beatriz Costa', pseudonym: 'ALU-CD34' }),
    ];

    it('filtra por parte do nome sem ir ao servidor', async () => {
        const wrapper = mountIndex({ students: paginator(two) });

        await wrapper.find('input[aria-label="Filtrar os alunos apresentados"]').setValue('bea');

        expect(wrapper.text()).toContain('Beatriz Costa');
        expect(wrapper.text()).not.toContain('Ana Silva');
        expect(inertia.routerGet).not.toHaveBeenCalled();
    });

    it('ignora acentuação e maiúsculas', async () => {
        const wrapper = mountIndex({
            students: paginator([student({ name: 'Inês Gonçalves' })]),
        });

        await wrapper.find('input[aria-label="Filtrar os alunos apresentados"]').setValue('ines');

        expect(wrapper.text()).toContain('Inês Gonçalves');
    });

    it('só se anuncia como filtro DESTA PÁGINA quando existe paginação', async () => {
        const single = mountIndex({ students: paginator(two) });
        expect(single.text()).not.toContain('Filtra os alunos apresentados nesta página.');

        const paged = mountIndex({
            students: paginator(two, {
                total: 240,
                links: [
                    { url: null, label: '&laquo; Anterior', active: false },
                    { url: '/students?page=1', label: '1', active: true },
                    { url: '/students?page=2', label: '2', active: false },
                    { url: '/students?page=2', label: 'Seguinte &raquo;', active: false },
                ],
            }),
        });

        expect(paged.text()).toContain('Filtra os alunos apresentados nesta página.');
    });

    it('distingue «nada nesta página» de «não há alunos»', async () => {
        const wrapper = mountIndex({ students: paginator(two) });

        await wrapper.find('input[aria-label="Filtrar os alunos apresentados"]').setValue('zzz');

        expect(wrapper.text()).toContain('Nenhum aluno desta página corresponde');
        expect(wrapper.text()).not.toContain('As suas turmas ainda não têm alunos.');
    });
});

describe('students/Index — estados vazios', () => {
    it('sem turmas, manda criar uma turma e não fala de alunos que faltam', () => {
        const wrapper = mountIndex({ students: paginator([]), classes: [], academicYears: [] });

        expect(wrapper.text()).toContain('Ainda não tem turmas.');
        expect(wrapper.text()).not.toContain('As suas turmas ainda não têm alunos.');
        expect(wrapper.findAll('a').some((a) => a.attributes('href') === '/classes')).toBe(true);
    });

    it('com turmas vazias, manda abrir as turmas', () => {
        const wrapper = mountIndex({ students: paginator([]) });

        expect(wrapper.text()).toContain('As suas turmas ainda não têm alunos.');
        expect(wrapper.findAll('a').some((a) => a.text().includes('Abrir turmas'))).toBe(true);
    });

    it('sem resultados para os filtros, não sugere que a conta não tem alunos', () => {
        const wrapper = mountIndex({
            students: paginator([]),
            filters: { q: 'Ana Silva', class: null, year: null, status: null },
        });

        expect(wrapper.text()).toContain('Nenhum aluno corresponde a esta pesquisa.');
        expect(wrapper.text()).not.toContain('As suas turmas ainda não têm alunos.');
    });
});

describe('students/Index — paginação', () => {
    it('não mostra paginador quando há uma só página', () => {
        const wrapper = mountIndex();

        expect(wrapper.findAll('a').some((a) => a.attributes('href')?.includes('page=2'))).toBe(false);
    });

    it('mostra os links do paginador quando há mais do que uma página', () => {
        const wrapper = mountIndex({
            students: paginator([student()], {
                total: 240,
                links: [
                    { url: null, label: '&laquo; Anterior', active: false },
                    { url: '/students?page=1', label: '1', active: true },
                    { url: '/students?page=2', label: '2', active: false },
                    { url: '/students?page=2', label: 'Seguinte &raquo;', active: false },
                ],
            }),
        });

        expect(wrapper.findAll('a').some((a) => a.attributes('href') === '/students?page=2')).toBe(true);
        expect(wrapper.text()).toContain('de 240 alunos');
    });
});
