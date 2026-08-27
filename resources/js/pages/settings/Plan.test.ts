import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Plan from './Plan.vue';

type MockForm = Record<string, unknown> & { errors: Record<string, string>; processing: boolean };

const mocks = vi.hoisted(() => ({
    forms: [] as MockForm[],
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
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

type Props = {
    state: 'eligible' | 'trial_active' | 'trial_expired' | 'pro_active' | 'institutional' | 'unavailable';
    proDays: number;
    currentPlanName: string | null;
    trial: { starts_at: string; ends_at: string; days_remaining: number } | null;
    usedTrialBefore: boolean | null;
};

function mountPage(props: Partial<Props> = {}) {
    const wrapper = mount(Plan, {
        props: {
            state: 'eligible',
            proDays: 30,
            currentPlanName: null,
            trial: null,
            usedTrialBefore: null,
            ...props,
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

/** `Plan.vue` creates exactly one form, for activating the trial. */
function trialForm(): MockForm {
    return mocks.forms[0];
}

afterEach(() => {
    mocks.forms.length = 0;
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('settings/Plan — eligible', () => {
    it('renders the offer heading, the interpolated proDays and the activation button', () => {
        // A DIFFERENT number than the commercial default (30), so this proves
        // the page reads the prop rather than a hardcoded literal.
        const wrapper = mountPage({ state: 'eligible', proDays: 14 });

        expect(wrapper.text()).toContain('Experimente o plano Pro');
        expect(wrapper.text()).toContain('14');
        expect(wrapper.text()).not.toContain('30');
        expect(wrapper.find('button').exists()).toBe(true);
        expect(wrapper.text()).toContain('Experimentar Pro por 14 dias');
    });

    it('renders the backend trial error once form.errors.trial is set', async () => {
        const wrapper = mountPage({ state: 'eligible' });

        trialForm().errors.trial = 'Esta conta já utilizou o período experimental Pro.';
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Esta conta já utilizou o período experimental Pro.');
    });

    it('shows nothing about a trial error when there is none', () => {
        const wrapper = mountPage({ state: 'eligible' });

        expect(wrapper.text()).not.toContain('já utilizou');
    });
});

describe('settings/Plan — trial_active', () => {
    it('renders no activation button, and the given start/end dates and days remaining', () => {
        // Noon UTC on both ends, so the asserted calendar date does not
        // depend on the host machine's local timezone.
        const wrapper = mountPage({
            state: 'trial_active',
            trial: { starts_at: '2026-09-01T12:00:00Z', ends_at: '2026-10-01T12:00:00Z', days_remaining: 12 },
        });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Período experimental Pro ativo');
        expect(wrapper.text()).toContain('01/09/2026');
        expect(wrapper.text()).toContain('01/10/2026');
        expect(wrapper.text()).toContain('12');
    });
});

describe('settings/Plan — trial_expired', () => {
    it('renders no activation button and the already-used message', () => {
        const wrapper = mountPage({ state: 'trial_expired', currentPlanName: 'Lapispro Base' });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('já foi utilizado');
        expect(wrapper.text()).toContain('Lapispro Base');
    });
});

describe('settings/Plan — pro_active', () => {
    it('shows the used-before note when usedTrialBefore is true', () => {
        const wrapper = mountPage({ state: 'pro_active', currentPlanName: 'Lapispro Pro', usedTrialBefore: true });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Lapispro Pro');
        expect(wrapper.text()).toContain('anteriormente');
    });

    it('shows no such note when usedTrialBefore is false', () => {
        const wrapper = mountPage({ state: 'pro_active', currentPlanName: 'Lapispro Pro', usedTrialBefore: false });

        expect(wrapper.text()).not.toContain('anteriormente');
    });
});

describe('settings/Plan — institutional', () => {
    it('renders no trial-related text or button anywhere on the page', () => {
        const wrapper = mountPage({ state: 'institutional', currentPlanName: 'Lapispro Institucional' });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Lapispro Institucional');
        expect(wrapper.text().toLowerCase()).not.toContain('experimental');
        expect(wrapper.text().toLowerCase()).not.toContain('trial');
        expect(wrapper.text().toLowerCase()).not.toContain('pro por');
    });

    // This is a frontend test driven directly by the `state` prop, unaffected
    // by the backend change that made 'institutional' key off the in-force
    // PLAN rather than the organization's TYPE (App\Http\Controllers\Settings\PlanController::edit)
    // — confirmed here rather than assumed: any string of 'institutional'
    // renders the same way regardless of which organization shape produced it.
    it('renders the same way for a Personal organization administratively put on the Institutional plan', () => {
        const wrapper = mountPage({ state: 'institutional', currentPlanName: 'Lapispro Institucional' });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Lapispro Institucional');
    });
});

describe('settings/Plan — unavailable', () => {
    it('renders no trial copy and no button, but shows the current plan name when there is one', () => {
        const wrapper = mountPage({ state: 'unavailable', currentPlanName: 'Lapispro Base' });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Lapispro Base');
        expect(wrapper.text().toLowerCase()).not.toContain('experimental');
        expect(wrapper.text().toLowerCase()).not.toContain('trial');
        expect(wrapper.text().toLowerCase()).not.toContain('pro por');
    });

    it('renders with no plan name at all when there is none (e.g. no subscription in force)', () => {
        const wrapper = mountPage({ state: 'unavailable', currentPlanName: null });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Plano atual');
    });
});
