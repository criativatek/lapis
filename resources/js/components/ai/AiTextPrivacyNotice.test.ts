import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import type { PersonalDataFinding } from '@/lib/personalData';
import AiTextPrivacyNotice from './AiTextPrivacyNotice.vue';

/**
 * The two states, and what each of them may say.
 */

type Props = InstanceType<typeof AiTextPrivacyNotice>['$props'];

const wrappers: VueWrapper[] = [];

function mountNotice(props: Partial<Props> = {}) {
    const wrapper = mount(AiTextPrivacyNotice, {
        props: {
            notice: 'Não introduza nomes, contactos ou outros dados pessoais dos alunos.',
            ...props,
        } as Props,
    });

    wrappers.push(wrapper);

    return wrapper;
}

const findings: PersonalDataFinding[] = [
    { rule: 'emails', label: 'um endereço de correio eletrónico' },
    { rule: 'phone_numbers', label: 'um contacto telefónico' },
];

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('AiTextPrivacyNotice — the standing notice', () => {
    it('shows the rule before anybody has typed anything', () => {
        const wrapper = mountNotice();

        expect(wrapper.text()).toContain('Não introduza nomes, contactos ou outros dados pessoais dos alunos.');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('AiTextPrivacyNotice — the confirmation', () => {
    it('says what to do, and that nothing has happened yet', () => {
        const wrapper = mountNotice({ findings });

        expect(wrapper.text()).toContain('Este texto pode conter dados pessoais. Reveja-o antes de continuar.');
        expect(wrapper.text()).toContain('O texto não foi alterado nem enviado.');
    });

    it('names the kind of thing found, never the value', () => {
        const wrapper = mountNotice({ findings });

        expect(wrapper.text()).toContain('um endereço de correio eletrónico');
        expect(wrapper.text()).toContain('um contacto telefónico');
        expect(wrapper.text()).not.toContain('@');
    });

    it('offers both ways out, and continuing is a deliberate second act', async () => {
        const wrapper = mountNotice({ findings, actionLabel: 'Perguntar mesmo assim' });

        const buttons = wrapper.findAll('button');
        const back = buttons.find((button) => button.text().includes('Voltar e editar'));
        const on = buttons.find((button) => button.text().includes('Perguntar mesmo assim'));

        expect(back).toBeDefined();
        expect(on).toBeDefined();

        await back!.trigger('click');
        await on!.trigger('click');

        expect(wrapper.emitted('edit')).toHaveLength(1);
        expect(wrapper.emitted('proceed')).toHaveLength(1);
    });

    /**
     * `alert`, not `status`: this interrupts a submit the teacher just asked
     * for, and a screen reader has to say so now rather than when it next gets
     * a turn.
     */
    it('announces itself as an alert', () => {
        const wrapper = mountNotice({ findings });

        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });

    /**
     * The component has no way to reach the field it sits under: no `v-model`,
     * no input, and no emit that carries text. It cannot rewrite anything.
     */
    it('carries no control that could change the text', () => {
        const wrapper = mountNotice({ findings });

        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);

        for (const events of Object.values(wrapper.emitted())) {
            for (const payload of events as unknown[][]) {
                expect(payload).toHaveLength(0);
            }
        }
    });
});
