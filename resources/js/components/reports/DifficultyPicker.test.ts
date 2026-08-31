import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import type { ChosenDifficulty } from './DifficultyPicker.vue';
import DifficultyPicker from './DifficultyPicker.vue';

const difficulties = [
    { code: 'organization', label: 'Organização e coerência textual', objective: null },
    { code: 'reading', label: 'Leitura em voz alta', objective: null },
    { code: 'spelling', label: 'Ortografia', objective: null },
];

const strategies = {
    organization: [
        { code: 'outline', label: 'Construir um esquema antes da escrita', objective: 'Organizar as ideias' },
        { code: 'review', label: 'Rever o texto por etapas', objective: null },
    ],
    reading: [{ code: 'paired-reading', label: 'Praticar leitura a pares', objective: 'Melhorar a fluência' }],
    spelling: [],
};

const domains = ['Português', 'Matemática'];
const wrappers: VueWrapper[] = [];

function chosen(
    code: string | null,
    label: string,
    overrides: Partial<ChosenDifficulty> = {},
): ChosenDifficulty {
    return { code, label, domain: null, note: null, strategies: [], ...overrides };
}

function mountPicker(modelValue: ChosenDifficulty[] = []) {
    const wrapper = mount(DifficultyPicker, {
        props: {
            modelValue,
            difficulties,
            strategies,
            domains,
            'onUpdate:modelValue': async (value: ChosenDifficulty[]) => wrapper.setProps({ modelValue: value }),
        },
    });
    wrappers.push(wrapper);

    return wrapper;
}

function cards(wrapper: VueWrapper) {
    return wrapper.findAll('[data-test="difficulty-card"]');
}

function valueOf(wrapper: VueWrapper): ChosenDifficulty[] {
    return (wrapper.vm.$props as { modelValue: ChosenDifficulty[] }).modelValue;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('DifficultyPicker — card hierarchy', () => {
    it('shows the separate add zone when there are no difficulties, without rendering a card', () => {
        const wrapper = mountPicker();

        expect(cards(wrapper)).toHaveLength(0);
        expect(wrapper.find('[data-test="difficulty-add-zone"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Acrescentar outra dificuldade');
    });

    it('renders one numbered card with discreet editable domain metadata and the complementary note last', () => {
        const wrapper = mountPicker([
            chosen('organization', 'Organização e coerência textual', {
                domain: 'Português',
                note: 'Precisa de apoio na planificação.',
            }),
        ]);

        const card = cards(wrapper)[0];

        expect(cards(wrapper)).toHaveLength(1);
        expect(card.text()).toContain('1 · Organização e coerência textual');
        expect(card.find('[data-test="difficulty-domain"]').element).toHaveProperty('value', 'Português');
        expect(card.text()).toContain('Nota complementar');
        expect(card.text()).toContain('Estratégias para esta dificuldade');
        expect(card.text().indexOf('Nota complementar')).toBeGreaterThan(card.text().indexOf('Estratégias para esta dificuldade'));
        expect(card.find('[data-test="difficulty-note"]').element).toHaveProperty(
            'value',
            'Precisa de apoio na planificação.',
        );
        expect(card.find('button').attributes('aria-label')).toBe('Remover esta dificuldade');
    });

    it('numbers three cards sequentially and represents Nenhum, one strategy, multiple strategies, and no note', () => {
        const wrapper = mountPicker([
            chosen('organization', 'Organização e coerência textual'),
            chosen('reading', 'Leitura em voz alta', {
                domain: 'Português',
                strategies: [strategies.reading[0]],
            }),
            chosen('organization', 'Planificação da escrita', {
                strategies: [...strategies.organization],
            }),
        ]);

        const [first, second, third] = cards(wrapper);

        expect(first.text()).toContain('1 · Organização e coerência textual');
        expect(second.text()).toContain('2 · Leitura em voz alta');
        expect(third.text()).toContain('3 · Planificação da escrita');
        expect(first.find('[data-test="difficulty-domain"]').element).toHaveProperty('value', '');
        expect(second.find('[data-test="difficulty-domain"]').element).toHaveProperty('value', 'Português');
        expect(first.findAll('input[type="checkbox"]:checked')).toHaveLength(0);
        expect(second.findAll('input[type="checkbox"]:checked')).toHaveLength(1);
        expect(third.findAll('input[type="checkbox"]:checked')).toHaveLength(2);
        expect(first.find('[data-test="difficulty-note"]').element).toHaveProperty('value', '');
    });
});

describe('DifficultyPicker — independent editing', () => {
    it('toggles a strategy only on the selected card', async () => {
        const wrapper = mountPicker([
            chosen('organization', 'Primeira dificuldade'),
            chosen('organization', 'Segunda dificuldade'),
        ]);

        await cards(wrapper)[1].findAll('input[type="checkbox"]')[0].setValue(true);

        expect(valueOf(wrapper)[0].strategies).toEqual([]);
        expect(valueOf(wrapper)[1].strategies).toEqual([strategies.organization[0]]);
        expect(cards(wrapper)[0].findAll('input[type="checkbox"]:checked')).toHaveLength(0);
        expect(cards(wrapper)[1].findAll('input[type="checkbox"]:checked')).toHaveLength(1);
    });

    it('edits one card domain and note without cross-contaminating another', async () => {
        const wrapper = mountPicker([
            chosen('organization', 'Primeira dificuldade'),
            chosen('reading', 'Segunda dificuldade'),
        ]);
        const second = cards(wrapper)[1];

        await second.find('[data-test="difficulty-domain"]').setValue('Matemática');
        await second.find('[data-test="difficulty-note"]').setValue('Observação exclusiva da segunda.');

        expect(valueOf(wrapper)[0]).toMatchObject({ domain: null, note: null, strategies: [] });
        expect(valueOf(wrapper)[1]).toMatchObject({
            domain: 'Matemática',
            note: 'Observação exclusiva da segunda.',
            strategies: [],
        });
    });

    it('removes one of several cards, preserves the others, and renumbers them', async () => {
        const wrapper = mountPicker([
            chosen('organization', 'Primeira dificuldade', { note: 'Remover' }),
            chosen('reading', 'Segunda dificuldade', { domain: 'Português', strategies: [strategies.reading[0]] }),
            chosen('spelling', 'Terceira dificuldade', { note: 'Manter' }),
        ]);

        await cards(wrapper)[0].find('button').trigger('click');

        expect(cards(wrapper)).toHaveLength(2);
        expect(cards(wrapper)[0].text()).toContain('1 · Segunda dificuldade');
        expect(cards(wrapper)[1].text()).toContain('2 · Terceira dificuldade');
        expect(valueOf(wrapper)).toEqual([
            chosen('reading', 'Segunda dificuldade', { domain: 'Português', strategies: [strategies.reading[0]] }),
            chosen('spelling', 'Terceira dificuldade', { note: 'Manter' }),
        ]);
    });
});

describe('DifficultyPicker — adding difficulties', () => {
    it('adds a library difficulty with its code and strategy checklist', async () => {
        const wrapper = mountPicker();

        await wrapper.find('[data-test="library-difficulty-select"]').setValue('organization');
        await wrapper.find('[data-test="add-library-difficulty"]').trigger('click');

        expect(valueOf(wrapper)).toEqual([chosen('organization', 'Organização e coerência textual')]);
        expect(cards(wrapper)[0].findAll('input[type="checkbox"]')).toHaveLength(2);
        expect(cards(wrapper)[0].text()).not.toContain('Dificuldade escrita por si');
    });

    it('adds a typed difficulty with a null code and the written-by-hand fallback instead of strategies', async () => {
        const wrapper = mountPicker();

        await wrapper.find('[data-test="own-difficulty-input"]').setValue('Dificuldade personalizada');
        await wrapper.find('[data-test="add-own-difficulty"]').trigger('click');

        expect(valueOf(wrapper)).toEqual([chosen(null, 'Dificuldade personalizada')]);
        expect(cards(wrapper)[0].findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(cards(wrapper)[0].text()).toContain(
            'Dificuldade escrita por si — as estratégias podem ser acrescentadas no texto da secção.',
        );
    });
});
