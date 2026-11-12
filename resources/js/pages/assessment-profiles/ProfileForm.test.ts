import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import ProfileForm from './ProfileForm.vue';

type MockForm = Record<string, unknown> & {
    errors: Record<string, string>;
    grade_levels: string[];
    levels?: { label: string; from_percent: number | string; inovar_code: string }[];
    first_positive_level?: number;
    scale_name?: string;
    use_as_organization_default?: boolean;
    profile_name?: string;
    submit: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
};

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            submit: vi.fn(),
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }) as unknown as MockForm;

        mocks.forms.push(form);

        return form;
    },
}));

const wrappers: VueWrapper[] = [];

function mountForm() {
    const wrapper = mount(ProfileForm, {
        props: {
            academicYears: [],
            subjects: [],
            scales: [],
            submitUrl: '/assessment-profiles',
            method: 'post',
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** The first `useForm` call is the profile itself; the second is the scale dialog. */
function profileForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('ProfileForm — Ano(s) de escolaridade', () => {
    it('adds what was typed but not «Adicionado» before submitting', async () => {
        const wrapper = mountForm();

        await wrapper.get('#grade_level_input').setValue('7.º');
        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual(['7.º']);
        expect(profileForm().submit).toHaveBeenCalledTimes(1);
    });

    it('does not duplicate a value that is already in the list', async () => {
        const wrapper = mountForm();

        profileForm().grade_levels.push('7.º');
        await wrapper.get('#grade_level_input').setValue(' 7.º ');
        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual(['7.º']);
    });

    it('leaves the list alone when nothing was typed', async () => {
        const wrapper = mountForm();

        await wrapper.get('form').trigger('submit');

        expect(profileForm().grade_levels).toEqual([]);
        expect(profileForm().submit).toHaveBeenCalledTimes(1);
    });

    it('shows the per-element error the server returns as grade_levels.N', async () => {
        const wrapper = mountForm();

        profileForm().errors['grade_levels.0'] = 'O campo grade_levels.0 não pode ter mais de 16 caracteres.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('não pode ter mais de 16 caracteres');
    });

    it('shows the list error and the element errors together', async () => {
        const wrapper = mountForm();

        profileForm().errors.grade_levels = 'Erro da lista.';
        profileForm().errors['grade_levels.1'] = 'Valor repetido.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Erro da lista.');
        expect(wrapper.text()).toContain('Valor repetido.');
    });
});

describe('a escala por defeito', () => {
    it('abre com a escala por defeito quando é um perfil novo', () => {
        const wrapper = mount(ProfileForm, {
            props: {
                academicYears: [],
                subjects: [],
                scales: [{ id: 7, ulid: 'scale-1-5', label: 'Escala 1 a 5', system: true, kind: 'level', min_value: 1, max_value: 5, levels: [] }],
                submitUrl: '/assessment-profiles',
                method: 'post',
                defaultScaleId: 7,
            },
        });
        wrappers.push(wrapper);

        expect(profileForm().scale_id).toBe(7);
    });

    it('não toca na escala de um perfil existente', () => {
        const wrapper = mount(ProfileForm, {
            props: {
                academicYears: [],
                subjects: [],
                scales: [],
                initial: { name: 'Português', academic_year_id: 1, subject_id: 1, grade_levels: [], description: null, scale_id: 3, domains: [] },
                submitUrl: '/assessment-profiles/x',
                method: 'put',
                defaultScaleId: 7,
            },
        });
        wrappers.push(wrapper);

        expect(profileForm().scale_id).toBe(3);
    });
});

const levelScale = {
    id: 7,
    ulid: 'scale-1-5',
    label: 'Escala 1 a 5',
    system: true,
    kind: 'level',
    min_value: 1,
    max_value: 5,
    levels: [
        { code: '1', label: 'Fraco', from_percent: 0, inovar_code: 'F', is_negative: true },
        { code: '2', label: 'Insuficiente', from_percent: 20, inovar_code: 'I', is_negative: true },
        { code: '3', label: 'Suficiente', from_percent: 50, inovar_code: 'S', is_negative: false },
        { code: '4', label: 'Bom', from_percent: 70, inovar_code: 'B', is_negative: false },
        { code: '5', label: 'Muito Bom', from_percent: 90, inovar_code: 'MB', is_negative: false },
    ],
};
const percentageScale = { id: 8, ulid: 'scale-pct', label: 'Percentagem (0 a 100)', system: true, kind: 'percentage', min_value: 0, max_value: 100, levels: [] };

function mentionsButton(wrapper: VueWrapper) {
    return wrapper.findAll('button').find((candidate) => candidate.text().includes('Personalizar escala'))!;
}

function mentionsFormElement(): HTMLFormElement {
    return document.querySelector('form[data-test="mentions-form"]') as HTMLFormElement;
}

function mountWithScale(scales: unknown[] = [levelScale], extra: Record<string, unknown> = {}) {
    const wrapper = mount(ProfileForm, {
        props: { academicYears: [], subjects: [], scales, submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7, ...extra },
        attachTo: document.body,
    } as never);
    wrappers.push(wrapper);

    return wrapper;
}

async function openDialog(wrapper: VueWrapper) {
    await mentionsButton(wrapper).trigger('click');
    await wrapper.vm.$nextTick();
}

function rows(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>('[data-test="level-row"]'));
}

function labelInput(index: number): HTMLInputElement {
    return document.querySelector<HTMLInputElement>(`#level-label-${index}`)!;
}

function inovarInput(index: number): HTMLInputElement {
    return document.querySelector<HTMLInputElement>(`#level-inovar-${index}`)!;
}

async function typeInto(input: HTMLInputElement, value: string, wrapper: VueWrapper) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await wrapper.vm.$nextTick();
}

async function click(selector: string, wrapper: VueWrapper) {
    document.querySelector<HTMLElement>(selector)!.click();
    await wrapper.vm.$nextTick();
}

/**
 * «Personalizar escala»: os níveis da escola, por perfil. Por defeito nada
 * muda — o botão só existe para quem quiser outra escala, e só numa escala de
 * níveis. Ao guardar, o perfil passa a apontar à escala derivada.
 * ATENÇÃO: os testes dependem da ORDEM dos `useForm` (perfil, escala
 * personalizada, escala da escola — o 3.º).
 */
describe('personalizar escala', () => {
    it('o botão só está ativo numa escala de níveis', async () => {
        const wrapper = mountWithScale([levelScale, percentageScale]);

        expect(mentionsButton(wrapper).attributes('disabled')).toBeUndefined();

        profileForm().scale_id = 8;
        await wrapper.vm.$nextTick();

        expect(mentionsButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('o diálogo abre com os níveis atuais, o nome da escala e a fronteira', async () => {
        const wrapper = mountWithScale();
        profileForm().name = 'Português';
        await openDialog(wrapper);

        const scaleForm = mocks.forms[2];
        expect(rows()).toHaveLength(5);
        expect(Array.from({ length: 5 }, (_, index) => labelInput(index).value)).toEqual(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom']);
        expect(Array.from({ length: 5 }, (_, index) => inovarInput(index).value)).toEqual(['F', 'I', 'S', 'B', 'MB']);
        expect(scaleForm.levels?.map((level) => level.from_percent)).toEqual([0, 20, 50, 70, 90]);
        expect(scaleForm.first_positive_level).toBe(3);
        expect(scaleForm.scale_name).toBe('Escala 1 a 5 · Português');
        expect(scaleForm.use_as_organization_default).toBe(false);
        expect(document.querySelector<HTMLInputElement>('#level-cut-0')!.disabled).toBe(true);
        expect(Array.from(document.querySelectorAll('[data-test="level-range"]')).map((node) => node.textContent?.trim())).toEqual([
            '0 – 19 %',
            '20 – 49 %',
            '50 – 69 %',
            '70 – 89 %',
            '90 – 100 %',
        ]);
    });

    it('envia os níveis, a fronteira, o nome da escala e o nome do perfil', async () => {
        const wrapper = mountWithScale();
        profileForm().name = 'Português';
        await openDialog(wrapper);

        const scaleForm = mocks.forms[2];
        mentionsFormElement().dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();

        expect(scaleForm.post).toHaveBeenCalledWith('/scales/scale-1-5/mencoes', expect.objectContaining({ preserveState: true }));
        expect(scaleForm.profile_name).toBe('Português');
        expect(scaleForm.levels).toEqual([
            { label: 'Fraco', from_percent: 0, inovar_code: 'F' },
            { label: 'Insuficiente', from_percent: 20, inovar_code: 'I' },
            { label: 'Suficiente', from_percent: 50, inovar_code: 'S' },
            { label: 'Bom', from_percent: 70, inovar_code: 'B' },
            { label: 'Muito Bom', from_percent: 90, inovar_code: 'MB' },
        ]);
    });

    it('adiciona e remove níveis, com limites em 2 e em 10', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);

        // Cortes mais baixos, para haver sítio: 0, 10, 20, 30, 40.
        for (const [index, cut] of [10, 20, 30, 40].entries()) {
            await typeInto(document.querySelector<HTMLInputElement>(`#level-cut-${index + 1}`)!, String(cut), wrapper);
        }

        // 5 → 10: o corte sugerido é o ponto médio até 100, sempre acima do anterior.
        for (let added = 0; added < 5; added++) {
            await click('[data-test="add-level"]', wrapper);
        }

        const scaleForm = mocks.forms[2];
        expect(rows()).toHaveLength(10);
        expect(document.querySelector<HTMLButtonElement>('[data-test="add-level"]')!.disabled).toBe(true);
        const cuts = scaleForm.levels!.map((level) => Number(level.from_percent));
        expect(cuts.slice(0, 6)).toEqual([0, 10, 20, 30, 40, 70]);
        expect(cuts.every((cut, index) => index === 0 || cut > cuts[index - 1])).toBe(true);

        // 10 → 2.
        for (let removed = 0; removed < 8; removed++) {
            await click('[data-test="remove-level"]', wrapper);
        }

        expect(rows()).toHaveLength(2);
        expect(document.querySelector<HTMLButtonElement>('[data-test="remove-level"]')!.disabled).toBe(true);
        // Removido o nível 1 várias vezes: o primeiro volta a começar em 0.
        expect(scaleForm.levels![0].from_percent).toBe(0);
    });

    it('não deixa acrescentar um nível quando o último já começa em 100 %', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);

        await typeInto(document.querySelector<HTMLInputElement>('#level-cut-4')!, '100', wrapper);

        expect(document.querySelector<HTMLButtonElement>('[data-test="add-level"]')!.disabled).toBe(true);
    });

    it('sugere o código do Inovar quando o nome coincide, e deixa de sugerir depois de escrito à mão', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);
        await click('[data-test="add-level"]', wrapper);

        await typeInto(labelInput(5), 'muito bom', wrapper);
        expect(inovarInput(5).value).toBe('MB');

        await typeInto(inovarInput(5), 'SMB', wrapper);
        await typeInto(labelInput(5), 'Bom', wrapper);
        expect(inovarInput(5).value).toBe('SMB');
    });

    it('um nível sem código e dois níveis com o mesmo código dão avisos que não bloqueiam', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);

        expect(document.querySelector('[data-test="warning-no-code"]')).toBeNull();
        expect(document.querySelector('[data-test="warning-repeated-code"]')).toBeNull();

        await typeInto(inovarInput(1), '', wrapper);
        expect(document.querySelector('[data-test="warning-no-code"]')?.textContent).toContain(
            'Sem código em todos os níveis, esta escala não exporta para o Inovar.',
        );

        await typeInto(inovarInput(1), 'F', wrapper);
        expect(document.querySelector('[data-test="warning-repeated-code"]')?.textContent).toContain(
            'Dois níveis têm o mesmo código: no Inovar ficam indistinguíveis.',
        );

        mentionsFormElement().dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();
        expect(mocks.forms[2].post).toHaveBeenCalled();
    });

    it('a fronteira positiva é de um nível: os cortes não a mudam e ela acompanha o nível', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);
        const scaleForm = mocks.forms[2];

        // «Suficiente» (nível 3) é o primeiro positivo. Uma escola cuja positiva
        // começa nos 45 % baixa-lhe o corte — e ele continua positivo.
        expect(scaleForm.first_positive_level).toBe(3);
        await typeInto(document.querySelector<HTMLInputElement>('#level-cut-2')!, '45', wrapper);
        expect(scaleForm.first_positive_level).toBe(3);
        // Nem um corte ≥ 50 % num nível abaixo a arrasta para lá.
        await typeInto(document.querySelector<HTMLInputElement>('#level-cut-1')!, '40', wrapper);
        expect(scaleForm.first_positive_level).toBe(3);

        // Removido o nível 1, «Suficiente» passa a ser o 2 — e a fronteira vai com ele.
        await click('[data-test="remove-level"]', wrapper);
        expect(scaleForm.levels![1].label).toBe('Suficiente');
        expect(scaleForm.first_positive_level).toBe(2);

        // Acrescentar um nível no fim não lhe mexe.
        await click('[data-test="add-level"]', wrapper);
        expect(scaleForm.first_positive_level).toBe(2);

        // Escolhida à mão, fica onde o professor a pôs.
        const select = document.querySelector<HTMLSelectElement>('#first-positive-level')!;
        select.value = '3';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await wrapper.vm.$nextTick();
        expect(scaleForm.first_positive_level).toBe(3);
    });

    it('«Repor a escala do Lapispro» volta aos cinco níveis de origem', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);
        const scaleForm = mocks.forms[2];

        await click('[data-test="add-level"]', wrapper);
        await typeInto(labelInput(0), 'Outro', wrapper);
        await click('[data-test="reset-levels"]', wrapper);

        expect(rows()).toHaveLength(5);
        expect(scaleForm.levels!.map((level) => level.label)).toEqual(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom']);
        expect(scaleForm.first_positive_level).toBe(3);
    });

    it('o nome da escala e a caixa «escala da organização» seguem para o servidor', async () => {
        const derived = { ...levelScale, id: 9, ulid: 'scale-derived', label: 'Escala da escola', system: false, organization_default: true };
        const wrapper = mountWithScale([levelScale, derived], { defaultScaleId: 9 });
        await openDialog(wrapper);

        const scaleForm = mocks.forms[2];
        expect(scaleForm.scale_name).toBe('Escala da escola');
        expect(scaleForm.use_as_organization_default).toBe(true);

        await typeInto(document.querySelector<HTMLInputElement>('#scale-display-name')!, 'Nova escola', wrapper);
        expect(scaleForm.scale_name).toBe('Nova escola');

        document.querySelector<HTMLInputElement>('[data-test="organization-default"]')!.click();
        await wrapper.vm.$nextTick();
        expect(scaleForm.use_as_organization_default).toBe(false);
    });

    it('o nome pré-preenchido acompanha o número de níveis', async () => {
        const wrapper = mountWithScale();
        profileForm().name = 'Português';
        await openDialog(wrapper);

        await click('[data-test="remove-level"]', wrapper);

        expect(mocks.forms[2].scale_name).toBe('Escala 1 a 4 · Português');
    });

    it('mostra os erros do servidor junto da linha e do nome', async () => {
        const wrapper = mountWithScale();
        await openDialog(wrapper);

        mocks.forms[2].errors['levels.2.from_percent'] = 'Tem de começar acima do nível anterior.';
        mocks.forms[2].errors['levels.1.inovar_code'] = 'O código no Inovar não pode ter espaços.';
        mocks.forms[2].errors.scale_name = 'Já existe uma escala com este nome. Escolha outro.';
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('Tem de começar acima do nível anterior.');
        expect(document.body.textContent).toContain('O código no Inovar não pode ter espaços.');
        expect(document.body.textContent).toContain('Já existe uma escala com este nome.');
    });

    it('a lista de escalas marca a escala da organização', () => {
        const derived = { ...levelScale, id: 9, ulid: 'scale-derived', label: 'Escala da escola', system: false, organization_default: true };
        const other = { ...levelScale, id: 10, ulid: 'scale-other', label: 'Outra escala', system: false, organization_default: false };
        const wrapper = mountWithScale([levelScale, derived, other]);

        const options = wrapper.findAll('#scale_id option').map((option) => option.text());

        expect(options).toContain('Escala da escola (escala da organização)');
        expect(options).toContain('Outra escala');
        expect(options).not.toContain('Outra escala (escala da organização)');
    });

    it('depois de guardar, o perfil passa a apontar à escala derivada', async () => {
        const derived = { ...levelScale, id: 9, ulid: 'scale-derived', label: 'Escala 1 a 5 · Português', system: false };
        const wrapper = mountWithScale();
        await openDialog(wrapper);

        const scaleForm = mocks.forms[2];
        scaleForm.post.mockImplementation(async (_url: string, options: { onSuccess?: () => void }) => {
            await wrapper.setProps({ scales: [levelScale, derived] });
            options.onSuccess?.();
        });

        mentionsFormElement().dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(profileForm().scale_id).toBe(9);
    });
});
