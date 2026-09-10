import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Show from './Show.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({
        inheritAttrs: false,
        setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
    }),
    router: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
    },
    // Lido por `canFollowUp`, que só é avaliado quando a pauta tem alguém —
    // daí só fazer falta agora que há testes com alunos na turma.
    usePage: () => ({ props: { modules: [], readOnlyModules: [] } }),
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            recentlySuccessful: false,
            post: vi.fn(),
            put: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
            transform: vi.fn(function (this: MockForm) {
                return this;
            }),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function baseProps() {
    return {
        schoolClass: {
            id: 1,
            ulid: 'class-1',
            label: '7.º A',
            subject: 'Matemática',
            academic_year: '2026/2027',
            grade_level: '7.º',
            status: 'active',
            status_label: 'Ativa',
            // Set so the page renders the plain "Avaliada por" line rather
            // than the profile-assignment form — irrelevant to this fix and
            // kept out of the way.
            profile_name: 'Perfil Teste',
            archived: false,
            archived_at: null,
            eligible_for_deletion_at: null,
            is_eligible_for_deletion: false,
        },
        students: [],
        former_students: [],
        availableProfiles: [],
        recurringLessonSlots: null,
        // `null` a par de `recurringLessonSlots`, e pela mesma razão: sem o
        // módulo das aulas não há tempos onde usar um grupo, e a secção
        // «Grupos» não existe de todo. Estes casos são sobre a remoção de um
        // aluno e não passam sequer por ali.
        classGroups: null,
        classGroupsDefaultDate: null,
    };
}

function mountPage() {
    const wrapper = mount(Show, { props: baseProps() });

    wrappers.push(wrapper);

    return wrapper;
}

/**
 * `Show.vue` creates its forms in a fixed order: n.º de processo, perfil,
 * THE ENROLLMENT FORM (index 2 — the only one that can ever carry
 * `errors.limit`), edição, foto do aluno, importação de pauta, fotos em lote.
 */
function enrollmentForm(): MockForm {
    return mocks.forms[2];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * `StudentEnrollmentService::enrollNew()` blocks a new enrolment over the
 * organization's `active_students` quota via
 * `ValidationException::withMessages(['limit' => ...])`, which Inertia
 * surfaces as `form.errors.limit` on THIS form (`classes.students.store`) —
 * never on `editForm`, `processNumberForm`, `profileForm`, `importForm` or
 * `photoForm`, since none of those can grow active_students. Before this
 * fix, nothing on this page read that key.
 */
describe('classes/Show — enrollment quota (limit) error', () => {
    it('shows nothing about a quota when there is no limit error', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Atingiu o limite');
    });

    it('renders the backend quota message once the enrollment form.errors.limit is set', async () => {
        const wrapper = mountPage();

        enrollmentForm().errors.limit =
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.',
        );
    });

    it('does not leak the enrollment form limit error onto an unrelated form', async () => {
        const wrapper = mountPage();

        // A stray `limit` key on a DIFFERENT form's errors must never surface
        // through the enrollment section — proves the InputError added for
        // this fix is bound to the enrollment `form`, not to any other one.
        mocks.forms[0].errors.limit = 'Erro estranho de outro formulário.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Erro estranho de outro formulário.');
    });
});

function student(overrides: Record<string, unknown> = {}) {
    return {
        ulid: 'enrollment-1',
        id: 1,
        class_group_id: null,
        class_group_since: null,
        name: 'Maria Teste',
        has_identity: true,
        pseudonym: 'ALU-AAAA',
        class_number: 1,
        process_number: null,
        enrolled_on: '2026-09-14',
        is_late_entry: false,
        status_label: 'Inscrito',
        photo_url: null,
        can_be_removed: true,
        ...overrides,
    };
}

function removeButton(wrapper: VueWrapper, name: string) {
    return wrapper
        .findAll('button')
        .find((button) =>
            (button.attributes('aria-label') ?? '').startsWith(`Remover ${name}`) ||
            (button.attributes('aria-label') ?? '').startsWith(`${name} não pode`),
        );
}

/**
 * REMOVER UM ALUNO, DITO ANTES DA TENTATIVA.
 *
 * O servidor continua a ser quem recusa (`EnrollmentController::destroy`).
 * Isto é o que a página consegue explicar de antemão, para que ninguém carregue
 * três vezes no mesmo botão sem perceber porquê — que foi o relato de produção.
 */
describe('classes/Show — remover um aluno com história', () => {
    it('keeps the remove button usable for a student with nothing attached', () => {
        const wrapper = mount(Show, {
            props: { ...baseProps(), students: [student()] },
        });
        wrappers.push(wrapper);

        const button = removeButton(wrapper, 'Maria Teste');

        expect(button).toBeDefined();
        expect(button!.attributes('disabled')).toBeUndefined();
    });

    it('disables it — without hiding it — once there is history', () => {
        const wrapper = mount(Show, {
            props: {
                ...baseProps(),
                students: [student({ can_be_removed: false })],
            },
        });
        wrappers.push(wrapper);

        const button = removeButton(wrapper, 'Maria Teste');

        // ESCONDER SERIA PIOR: o professor ficava à procura de um botão que
        // não estava lá. Fica visível, desativado, e diz porquê.
        expect(button).toBeDefined();
        expect(button!.attributes('disabled')).toBeDefined();
        expect(button!.attributes('title')).toContain('registos pedagógicos');
        expect(button!.attributes('aria-label')).toContain('não pode ser removido');
    });
});

/**
 * «Escolher outro ficheiro», visto deste lado: quem desiste da
 * pré-visualização volta ao passo de carregamento, e não apenas à turma.
 */
describe('classes/Show — o regresso da pré-visualização da importação', () => {
    afterEach(() => {
        window.history.replaceState({}, '', '/');
    });

    // O diálogo é teleportado para o <body>, fora da árvore do componente —
    // daí a leitura ser feita ao documento e não ao wrapper.
    const dialogText = () => document.body.textContent ?? '';

    it('leaves the import dialog closed on a normal visit', async () => {
        window.history.replaceState({}, '', '/classes/class-1');

        const wrapper = mountPage();
        await wrapper.vm.$nextTick();

        expect(dialogText()).not.toContain('Importar lista de turma');
    });

    it('reopens the upload dialog when coming back from a discarded import', async () => {
        window.history.replaceState({}, '', '/classes/class-1?importar=1');

        const wrapper = mountPage();
        await wrapper.vm.$nextTick();

        expect(dialogText()).toContain('Importar lista de turma');
        // E o marcador sai do URL, para que uma atualização da página não
        // reabra um diálogo que o professor entretanto fechou.
        expect(window.location.search).toBe('');
    });
});
