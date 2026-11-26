import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Edit from '@/pages/instruments/Edit.vue';
import DeleteInstrumentButton from './DeleteInstrumentButton.vue';

const inertia = vi.hoisted(() => ({ delete: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => h('div') }),
    router: { delete: inertia.delete },
}));

vi.mock('@/pages/instruments/InstrumentForm.vue', () => ({
    default: defineComponent({ setup: () => () => h('div', { 'data-testid': 'form' }) }),
}));

afterEach(() => {
    vi.restoreAllMocks();
    inertia.delete.mockReset();
});

describe('DeleteInstrumentButton', () => {
    it('confirma com o título e só então pede o DELETE', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mount(DeleteInstrumentButton, { props: { ulid: 'abc', title: 'Teste 1' } });

        await wrapper.get('[data-testid="delete-instrument"]').trigger('click');

        expect(confirm).toHaveBeenCalledWith(
            'Eliminar "Teste 1"? Este elemento de avaliação ainda não tem resultados registados. Esta ação não pode ser desfeita a partir do Lapispro.',
        );
        expect(inertia.delete).toHaveBeenCalledWith('/instruments/abc');
    });

    it('sem confirmação não pede nada', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mount(DeleteInstrumentButton, { props: { ulid: 'abc', title: 'Teste 1' } });

        await wrapper.get('[data-testid="delete-instrument"]').trigger('click');

        expect(inertia.delete).not.toHaveBeenCalled();
    });
});

describe('Edit.vue', () => {
    const instrument = {
        ulid: 'abc', title: 'Teste 1', academic_period_id: 1, instrument_type_id: 1, applied_on: '2026-10-15',
        status: 'draft', purpose: 'summative', counts_toward_classification: true, total_points: 100,
        allow_bonus: false, groups: [], items: [],
    };
    const base = { instrument, schoolClass: { ulid: 'c', label: '7A' }, periods: [], types: [], domains: [] };

    it('mostra o botão só com can_delete', () => {
        expect(mount(Edit, { props: { ...base, can_delete: true }, global: { stubs: { Heading: true } } }).find('[data-testid="delete-instrument"]').exists()).toBe(true);
        expect(mount(Edit, { props: { ...base, can_delete: false }, global: { stubs: { Heading: true } } }).find('[data-testid="delete-instrument"]').exists()).toBe(false);
    });
});
