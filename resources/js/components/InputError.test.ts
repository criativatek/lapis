import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InputError from './InputError.vue';

/**
 * Shared by every form in the app — a validation error that only changes the
 * DOM visually, with no `role`/`aria-live`, never reaches a screen reader
 * (the field itself gains no `aria-invalid`/`aria-describedby` either, so the
 * announcement is the only signal an assistive technology user gets).
 */
describe('InputError', () => {
    it('announces the message to assistive technology', () => {
        const wrapper = mount(InputError, { props: { message: 'Campo obrigatório.' } });

        const alert = wrapper.get('[role="alert"]');
        expect(alert.attributes('aria-live')).toBe('polite');
        expect(alert.text()).toBe('Campo obrigatório.');
    });
});
