import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import NativeSelect from './NativeSelect.vue';

describe('NativeSelect', () => {
    it('mudar o valor emite update:modelValue', async () => {
        const wrapper = mount(NativeSelect, {
            props: { modelValue: 'a' },
            slots: {
                default: '<option value="a">A</option><option value="b">B</option>',
            },
        });

        await wrapper.get('select').setValue('b');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['b']);
    });

    it('disabled desactiva o select', () => {
        const wrapper = mount(NativeSelect, {
            props: { modelValue: 'a', disabled: true },
            slots: { default: '<option value="a">A</option>' },
        });

        expect(wrapper.get('select').attributes('disabled')).toBeDefined();
    });
});
