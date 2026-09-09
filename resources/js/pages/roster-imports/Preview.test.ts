import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Preview from './Preview.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string> };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    // «Escolher outro ficheiro» é um <Link method="delete">. O duplo põe o
    // destino e o método em atributos `data-*` para que um teste possa
    // afirmar PARA ONDE vai e COM QUE MÉTODO — que é a diferença entre uma
    // saída e uma confirmação.
    Link: defineComponent({
        props: {
            href: { type: String, default: '' },
            method: { type: String, default: 'get' },
        },
        setup: (props, { slots }) => () =>
            h(
                'a',
                { 'data-href': props.href, 'data-method': props.method },
                slots.default?.(),
            ),
    }),
    router: {
        post: vi.fn(),
    },
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            post: vi.fn(),
        }) as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function mountPage() {
    const wrapper = mount(Preview, {
        props: {
            schoolClassUlid: 'class-1',
            token: 'token-1',
            rows: [],
            photos: [],
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** `Preview.vue` creates exactly one `useForm` — the `{ rows }` confirmation form. */
function confirmForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

/**
 * `confirm()` (`RosterImportController::confirm`, reached from `submit()`
 * here) can reactivate an existing enrolment via
 * `StudentEnrollmentService::fillFromRoster()`, which is blocked over the
 * organization's `active_students` quota the same way a brand-new enrolment
 * is — `ValidationException::withMessages(['limit' => ...])`, surfaced by
 * Inertia as `form.errors.limit`. Before this fix, nothing on this page read
 * that key: the existing `photosError` `InputError` is for a wholly
 * different, unrelated form (`photos`, a plain ref — see the "Adicionar
 * fotos" section).
 */
describe('roster-imports/Preview — reactivation quota (limit) error', () => {
    it('shows nothing about a quota when there is no limit error', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).not.toContain('Atingiu o limite');
    });

    it('renders the backend quota message once form.errors.limit is set', async () => {
        const wrapper = mountPage();

        confirmForm().errors.limit =
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain(
            'Atingiu o limite de 300 alunos ativos do seu plano. Os dados existentes são mantidos — para inscrever outro, reduza primeiro o número de alunos ativos.',
        );
    });
});

/**
 * A SAÍDA DA PRÉ-VISUALIZAÇÃO.
 *
 * Este ecrã só sabia confirmar: quem trouxesse o ficheiro errado saía pelo
 * botão «anterior» do browser, porque nada nesta página o levava de volta. A
 * pré-visualização do horário já tinha «Escolher outro ficheiro»; esta passa a
 * ter o mesmo, e a apagar mesmo o que ficou por confirmar.
 */
describe('roster-imports/Preview — a saída dentro da aplicação', () => {
    it('offers a way out that is not the browser back button', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Escolher outro ficheiro');
    });

    it('points that way out at this import, as a delete', () => {
        const exit = mountPage()
            .findAll('a')
            .find((link) => link.text().includes('Escolher outro ficheiro'));

        expect(exit).toBeDefined();
        // Sem o token não se saberia o que apagar; sem o DELETE seria uma
        // navegação que deixava as fotografias no disco. E `flow` diz a que
        // passo voltar: importar a lista e corrigir fotos são dois pontos de
        // partida, e desistir de um não pode cair no diálogo do outro.
        expect(exit!.attributes('data-href')).toBe(
            '/classes/class-1/roster-imports/token-1?flow=roster',
        );
        expect(exit!.attributes('data-method')).toBe('delete');
    });

    it('never confirms the import on the way out', () => {
        const wrapper = mountPage();

        wrapper
            .findAll('a')
            .find((link) => link.text().includes('Escolher outro ficheiro'))!
            .trigger('click');

        // Sair não é confirmar: o POST de confirmação não é feito.
        expect(confirmForm().post).not.toHaveBeenCalled();
    });

    it('leaves the confirmation button working', () => {
        const wrapper = mountPage();

        const confirm = wrapper
            .findAll('button')
            .find((button) => button.text().includes('Confirmar importação'));

        expect(confirm).toBeDefined();

        confirm!.trigger('click');

        expect(confirmForm().post).toHaveBeenCalledWith(
            '/classes/class-1/roster-imports/token-1/confirm',
        );
    });
});
