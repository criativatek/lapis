import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import InstrumentForm from './InstrumentForm.vue';

const inertia = vi.hoisted(() => ({
    submit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({
            ...data,
            errors: {},
            processing: false,
            transform(callback: (value: Record<string, unknown>) => Record<string, unknown>) {
                callback(form);

                return form;
            },
            submit: inertia.submit,
        });

        return form;
    },
}));

function mountForm(extra: Record<string, unknown> = {}) {
    return mount(InstrumentForm, {
        props: {
            schoolClass: { ulid: 'class-a', label: '7.º A' },
            periods: [{ id: 3, label: '1.º período' }],
            types: [{ id: 5, label: 'Teste', default_purpose: 'summative' }],
            domains: [{ id: 7, label: 'Conhecimento' }],
            submitUrl: '/classes/class-a/instruments',
            method: 'post' as const,
            defaultAcademicPeriodId: 3,
            ...extra,
        },
    });
}

function modeButton(wrapper: ReturnType<typeof mountForm>, label: string) {
    return wrapper.findAll('button').find((button) => button.text().includes(label))!;
}

describe('InstrumentForm creation modes', () => {
    beforeEach(() => {
        inertia.submit.mockReset();
    });

    it('returns from simple to advanced and back when the structure remains compatible', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);
        expect(modeButton(wrapper, 'Criação simples').attributes('disabled')).toBeUndefined();

        await modeButton(wrapper, 'Criação simples').trigger('click');

        expect(wrapper.find('#quick-title').exists()).toBe(true);
    });

    it('preserves common data throughout the mode cycle', async () => {
        const wrapper = mountForm();

        await wrapper.get('#quick-title').setValue('Teste de leitura');
        await wrapper.get('#quick-instrument-type').setValue('5');
        await wrapper.get('#quick-applied-on').setValue('2026-09-18');
        await wrapper.get('#quick-period').setValue('3');
        await wrapper.get('#quick-total-points').setValue('80');
        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect((wrapper.get('#title').element as HTMLInputElement).value).toBe('Teste de leitura');
        expect((wrapper.get('#instrument_type_id').element as HTMLSelectElement).value).toBe('5');
        expect((wrapper.get('#applied_on').element as HTMLInputElement).value).toBe('2026-09-18');
        expect((wrapper.get('#academic_period_id').element as HTMLSelectElement).value).toBe('3');
        expect((wrapper.get('#total_points').element as HTMLInputElement).value).toBe('80');

        await wrapper.get('#purpose').setValue('formative');
        await modeButton(wrapper, 'Criação simples').trigger('click');
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect((wrapper.get('#title').element as HTMLInputElement).value).toBe('Teste de leitura');
        expect((wrapper.get('#purpose').element as HTMLSelectElement).value).toBe('formative');
        expect((wrapper.get('#total_points').element as HTMLInputElement).value).toBe('80');
    });

    it('never submits merely by changing creation mode', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await modeButton(wrapper, 'Criação simples').trigger('click');

        expect(inertia.submit).not.toHaveBeenCalled();
    });

    /**
     * The scenario the four tests above all happen to avoid: each of them ticks
     * a domain BEFORE leaving simple mode, which runs the quick-mode watcher
     * that fills `item.domains`. A brand-new instrument starts with no domain
     * ticked and `domains: []`, and nothing gates the mode switch on picking
     * one first — so the way back used to be closed before the teacher had
     * built anything at all.
     */
    it('returns to simple mode when no domain has been picked yet', async () => {
        const wrapper = mountForm();

        await modeButton(wrapper, 'Criação avançada').trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);

        const simpleButton = modeButton(wrapper, 'Criação simples');
        expect(simpleButton.attributes('disabled')).toBeUndefined();

        await simpleButton.trigger('click');

        expect(wrapper.find('#quick-title').exists()).toBe(true);
        expect(inertia.submit).not.toHaveBeenCalled();
    });

    it('offers the way back immediately, before advanced mode is even entered', () => {
        const wrapper = mountForm();

        expect(modeButton(wrapper, 'Criação simples').attributes('disabled')).toBeUndefined();
    });

    it('keeps the common fields through a mode round trip with no domain picked', async () => {
        const wrapper = mountForm();

        await wrapper.get('#quick-title').setValue('Ficha sem domínio');
        await wrapper.get('#quick-total-points').setValue('60');
        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await modeButton(wrapper, 'Criação simples').trigger('click');

        expect((wrapper.get('#quick-title').element as HTMLInputElement).value).toBe('Ficha sem domínio');
        expect((wrapper.get('#quick-total-points').element as HTMLInputElement).value).toBe('60');
    });

    /**
     * Structural incompatibility must still block the return — the fix only
     * stopped an incomplete form from being mistaken for an incompatible one.
     */
    it('still blocks the return when a second group was created in advanced mode', async () => {
        const wrapper = mountForm();

        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await modeButton(wrapper, 'Organizar por grupos/secções').trigger('click');
        await modeButton(wrapper, 'Adicionar grupo/secção').trigger('click');

        const simpleButton = modeButton(wrapper, 'Criação simples');

        expect(simpleButton.attributes('disabled')).toBeDefined();
        expect(simpleButton.attributes('title')).toBe(
            'A configuração avançada já contém dados que ficariam ocultos.',
        );

        await simpleButton.trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);
        expect(inertia.submit).not.toHaveBeenCalled();
    });

    it('still blocks the return when a question code was changed and no domain was picked either', async () => {
        const wrapper = mountForm();

        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await wrapper.get('input[placeholder="Ex.: Q1"]').setValue('Parte A');

        expect(modeButton(wrapper, 'Criação simples').attributes('disabled')).toBeDefined();
    });

    it('keeps an incompatible advanced question intact and prevents returning to simple mode', async () => {
        const wrapper = mountForm();

        await wrapper.get('input[type="checkbox"]').setValue(true);
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        const questionCode = wrapper.get('input[placeholder="Ex.: Q1"]');
        await questionCode.setValue('Parte A');

        const simpleButton = modeButton(wrapper, 'Criação simples');
        expect(simpleButton.attributes('disabled')).toBeDefined();
        expect(simpleButton.attributes('title')).toBe(
            'A configuração avançada já contém dados que ficariam ocultos.',
        );

        await simpleButton.trigger('click');

        expect(wrapper.find('#title').exists()).toBe(true);
        expect((questionCode.element as HTMLInputElement).value).toBe('Parte A');
        expect(inertia.submit).not.toHaveBeenCalled();
    });
});

describe('InstrumentForm diagnostic purpose never counts', () => {
    beforeEach(() => {
        inertia.submit.mockReset();
    });

    it('hides the "Conta para a classificação" checkbox and shows the message for a diagnostic purpose', async () => {
        const wrapper = mountForm();
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        await wrapper.get('#purpose').setValue('diagnostic');

        expect(wrapper.text()).toContain(
            'Os instrumentos de avaliação diagnóstica não contribuem para as médias classificativas.',
        );
        expect(wrapper.text()).not.toContain('Conta para a classificação');
    });

    it('shows the checkbox for a non-diagnostic purpose', async () => {
        const wrapper = mountForm();
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        await wrapper.get('#purpose').setValue('formative');

        expect(wrapper.text()).toContain('Conta para a classificação');
        expect(wrapper.text()).not.toContain(
            'Os instrumentos de avaliação diagnóstica não contribuem para as médias classificativas.',
        );
    });

    function countsCheckbox(wrapper: ReturnType<typeof mountForm>) {
        return wrapper
            .findAll('input[type="checkbox"]')
            .find((input) => input.element.closest('label')?.textContent?.includes('Conta para a classificação'));
    }

    it('unticks counts_toward_classification when purpose becomes diagnostic', async () => {
        const wrapper = mountForm();
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        await wrapper.get('#purpose').setValue('formative');
        await countsCheckbox(wrapper)!.setValue(true);
        expect((countsCheckbox(wrapper)!.element as HTMLInputElement).checked).toBe(true);

        await wrapper.get('#purpose').setValue('diagnostic');

        // The checkbox is no longer rendered for a diagnostic purpose; going
        // back to a countable purpose must show it unticked, never restored.
        await wrapper.get('#purpose').setValue('formative');
        expect((countsCheckbox(wrapper)!.element as HTMLInputElement).checked).toBe(false);
    });

    it('does not restore counts_toward_classification to true when leaving diagnostic', async () => {
        const wrapper = mountForm();
        await modeButton(wrapper, 'Criação avançada').trigger('click');

        await wrapper.get('#purpose').setValue('diagnostic');
        expect(countsCheckbox(wrapper)).toBeUndefined();

        await wrapper.get('#purpose').setValue('summative');

        expect((countsCheckbox(wrapper)!.element as HTMLInputElement).checked).toBe(false);
    });
});

describe('InstrumentForm import in simple mode', () => {
    const domains = [
        { id: 7, label: 'Oralidade' },
        { id: 8, label: 'Leitura' },
    ];

    function source() {
        const item = (n: number, label: string, domainId: number | null = 7) => ({
            group_index: 0,
            code: `Q${n}`,
            label,
            points_possible: 15,
            is_bonus: false,
            domains: domainId === null ? [] : [{ domain_id: domainId, allocation_percent: 100 }],
        });

        return {
            ulid: 'src-1',
            title: 'Apresentações orais - Tema',
            class_label: '7.º A',
            applied_on: '2026-10-01',
            total_points: 45,
            allow_bonus: false,
            items: [
                { ...item(1, 'Gestualidade'), points_possible: 10 },
                item(2, 'Postura'),
                { ...item(3, 'Voz'), points_possible: 20 },
            ],
        };
    }

    function mountWith(instrument: ReturnType<typeof source>) {
        return mountForm({ domains, importableInstruments: [instrument] });
    }

    async function importIt(wrapper: ReturnType<typeof mountForm>) {
        await wrapper.get('[data-testid="import-select"]').setValue('src-1');
        await modeButton(wrapper, 'Importar questões').trigger('click');
    }

    type FormState = { items: { label: string; points_possible: number }[] };

    function formState(wrapper: ReturnType<typeof mountForm>): FormState {
        return (wrapper.vm as unknown as { $: { setupState: { form: FormState } } }).$.setupState.form;
    }

    beforeEach(() => {
        inertia.submit.mockReset();
    });

    it('shows the import box in simple mode', () => {
        const wrapper = mountWith(source());

        expect(wrapper.find('#quick-title').exists()).toBe(true);
        expect(wrapper.find('[data-testid="import-box"]').exists()).toBe(true);
    });

    it('hides the import box when there is nothing to import', () => {
        const wrapper = mountForm({ domains });

        expect(wrapper.find('[data-testid="import-box"]').exists()).toBe(false);
    });

    it('keeps simple mode with the imported points and contents untouched', async () => {
        const wrapper = mountWith(source());
        await importIt(wrapper);

        expect(wrapper.find('#quick-title').exists()).toBe(true);
        expect((wrapper.get('#quick-title').element as HTMLInputElement).value).toBe('Apresentações orais - Tema');
        expect(wrapper.find('[data-testid="import-note"]').exists()).toBe(false);

        const domainBoxes = wrapper.findAll('input[type="checkbox"]');
        expect((domainBoxes[0].element as HTMLInputElement).checked).toBe(true);
        expect((domainBoxes[1].element as HTMLInputElement).checked).toBe(false);

        const contents = wrapper.findAll('input[placeholder="Conteúdo (opcional)"]');
        expect(contents.map((input) => (input.element as HTMLInputElement).value)).toEqual([
            'Gestualidade',
            'Postura',
            'Voz',
        ]);
        expect(contents[0].attributes('aria-label')).toBe('Conteúdo avaliado em Q1');
        expect(formState(wrapper).items.map((item) => Number(item.points_possible))).toEqual([10, 15, 20]);
        expect(wrapper.text()).toContain('45 / 45');
    });

    it('submits the content label of each question from simple mode', async () => {
        const wrapper = mountWith(source());
        await importIt(wrapper);
        await wrapper.get('form').trigger('submit');

        expect(inertia.submit).toHaveBeenCalledTimes(1);
        expect(formState(wrapper).items.map((item) => item.label)).toEqual(['Gestualidade', 'Postura', 'Voz']);
    });

    it('shows the imported question comment read-only and sends it on create, in both modes', async () => {
        const commented = source();
        commented.items[1] = { ...commented.items[1], criterion_note: 'Audibilidade 5 + dicção 10' } as (typeof commented.items)[number];
        const wrapper = mountWith(commented);
        await importIt(wrapper);

        const lines = wrapper.findAll('[data-testid="imported-criterion-note"]');
        expect(lines).toHaveLength(1);
        expect(lines[0].text()).toBe('Comentário: Audibilidade 5 + dicção 10');
        expect(lines[0].element.tagName).toBe('P');

        const items = formState(wrapper).items as unknown as { criterion_note?: string }[];
        expect(items.map((item) => item.criterion_note)).toEqual([undefined, 'Audibilidade 5 + dicção 10', undefined]);

        await modeButton(wrapper, 'Criação avançada').trigger('click');
        expect(wrapper.findAll('[data-testid="imported-criterion-note"]')).toHaveLength(1);
        expect(wrapper.text()).toContain('Comentário: Audibilidade 5 + dicção 10');
    });

    it('moves to advanced mode with a note when the source has a bonus question', async () => {
        const bonus = source();
        bonus.items[2] = { ...bonus.items[2], is_bonus: true };
        const wrapper = mountWith(bonus);
        await importIt(wrapper);

        expect(wrapper.find('#title').exists()).toBe(true);
        expect(wrapper.get('[data-testid="import-note"]').text()).toContain('continua na criação avançada');
    });

    it('moves to advanced mode with a note when a question lost its domain', async () => {
        const orphan = source();
        orphan.items[1] = { ...orphan.items[1], domains: [] };
        const wrapper = mountWith(orphan);
        await importIt(wrapper);

        expect(wrapper.find('#title').exists()).toBe(true);
        expect(wrapper.get('[data-testid="import-note"]').text()).toContain('domínios que esta turma não tem');
    });

    it('syncs the ticked domains when importing in advanced mode', async () => {
        const mixed = source();
        mixed.items[1] = { ...mixed.items[1], domains: [{ domain_id: 8, allocation_percent: 100 }] };
        const wrapper = mountWith(mixed);
        await modeButton(wrapper, 'Criação avançada').trigger('click');
        await importIt(wrapper);

        expect(wrapper.find('#title').exists()).toBe(true);
        const ticked = wrapper
            .findAll('input[type="checkbox"]')
            .filter((input) => (input.element as HTMLInputElement).checked)
            .map((input) => (input.element as HTMLInputElement).value);
        // The «Conta para a classificação» checkbox has the default value 'on'.
        expect(ticked.filter((value) => value !== 'on')).toEqual(['7', '8']);
    });

    it('never sends the comment key when editing an existing instrument', async () => {
        const wrapper = mountForm({
            domains,
            method: 'put',
            submitUrl: '/instruments/x',
            initial: {
                title: 'Existente',
                academic_period_id: 3,
                instrument_type_id: 5,
                applied_on: '2026-10-01',
                purpose: 'summative',
                counts_toward_classification: true,
                total_points: 30,
                allow_bonus: false,
                groups: [{ ulid: 'g1', label: null }],
                items: [
                    {
                        ulid: 'i1', group_index: 0, code: 'Q1', label: 'Voz', points_possible: 30, is_bonus: false, has_scores: false,
                        domains: [{ domain_id: 7, allocation_percent: 100 }],
                    },
                ],
            },
        });

        const items = formState(wrapper).items as unknown as Record<string, unknown>[];
        expect(items).toHaveLength(1);
        expect('criterion_note' in items[0]).toBe(false);
        expect(wrapper.find('[data-testid="imported-criterion-note"]').exists()).toBe(false);
    });

    it('opens an existing instrument with content labels in simple mode and keeps the labels', async () => {
        const wrapper = mountForm({
            domains,
            initial: {
                title: 'Existente',
                academic_period_id: 3,
                instrument_type_id: 5,
                applied_on: '2026-10-01',
                purpose: 'summative',
                counts_toward_classification: true,
                total_points: 30,
                allow_bonus: false,
                groups: [{ ulid: 'g1', label: null }],
                items: [
                    {
                        ulid: 'i1',
                        group_index: 0,
                        code: 'Q1',
                        label: 'Voz',
                        points_possible: 30,
                        is_bonus: false,
                        has_scores: false,
                        domains: [{ domain_id: 7, allocation_percent: 100 }],
                    },
                ],
            },
        });

        const content = wrapper.get('input[placeholder="Conteúdo (opcional)"]');
        expect((content.element as HTMLInputElement).value).toBe('Voz');
        await wrapper.get('form').trigger('submit');

        expect(formState(wrapper).items[0].label).toBe('Voz');
    });
});
