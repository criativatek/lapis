import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import {
    availability,
    COMPARE_ROWS,
    FOUNDER,
    FOUNDER_PRICE,
    PLAN_COPY,
    PRO_PRICE,
} from './commercial';
import LandingCompare from './LandingCompare.vue';
import LandingPricing from './LandingPricing.vue';
import LandingVoucher from './LandingVoucher.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: [String, Object], default: '' } },
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'a',
                        {
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : ((props.href as { url?: string }).url ??
                                      ''),
                        },
                        slots.default?.(),
                    ),
        }),
    };
});

/**
 * jsdom implements no `matchMedia`, and every one of these sections is wrapped
 * in RevealOnScroll, which asks it about `prefers-reduced-motion` on mount.
 * Answering «no preference» with no IntersectionObserver in sight is the
 * branch that shows the content immediately — which is what a test that reads
 * rendered text needs.
 */
beforeAll(() => {
    Object.defineProperty(window, 'matchMedia', {
        configurable: true,
        value: (query: string) => ({
            matches: false,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        }),
    });
});

/**
 * The commercial guarantees, as tests.
 *
 * Every assertion here maps to a line of the commercial brief that would cost
 * real money or real trust if it silently drifted: three plans and no fourth,
 * annual billing only, Fundador as a condition on Pro, no invented counters,
 * and a comparison table derived from the entitlement tables rather than
 * typed in beside them.
 */

/** The plan shapes exactly as HomeController sends them, keys from the seeder. */
const BASE_MODULES = [
    'assessment_profiles',
    'classes',
    'students',
    'instruments',
    'assessments',
    'results',
    'self_assessments',
    'records',
    'interventions',
    'student_progress',
    'reports',
];

const PRO_MODULES = [
    ...BASE_MODULES,
    'calendar',
    'lessons',
    'ai_assistance',
    'advanced_analytics',
    'template_sharing',
    'self_assessment_links',
    'correction_grid_import',
    'inovar_export',
    'report_pedagogical_analysis',
];

const INSTITUTIONAL_MODULES = [
    ...PRO_MODULES,
    'institution_admin',
    'institution_library',
    'institution_reports',
    'audit_log',
];

function plans() {
    return [
        { key: 'base', name: 'Lapispro Base', moduleKeys: [...BASE_MODULES] },
        { key: 'pro', name: 'Lapispro Pro', moduleKeys: [...PRO_MODULES] },
        {
            key: 'institutional',
            name: 'Lapispro Institucional',
            moduleKeys: [...INSTITUTIONAL_MODULES],
        },
    ];
}

function pricing(contactEmail: string | null = null) {
    return mount(LandingPricing, {
        props: { plans: plans(), authenticated: false, contactEmail },
    });
}

describe('the commercial offer', () => {
    it('describes three plans and no fourth', () => {
        expect(Object.keys(PLAN_COPY)).toEqual([
            'base',
            'pro',
            'institutional',
        ]);

        // «Fundador» is a condition on Pro, never a plan of its own.
        expect(Object.keys(PLAN_COPY)).not.toContain('founder');
        expect(Object.keys(PLAN_COPY)).not.toContain('fundador');
    });

    it('never offers a monthly purchase', () => {
        const text = pricing().text();

        expect(text).toContain('44,90 €');
        expect(text).toContain('/ ano');
        expect(text).toContain('Não existe pagamento mensal');

        // Every monthly figure on the page is introduced as an equivalence,
        // and there is no Mensal/Anual toggle offering one as a purchase.
        expect(text).toContain('equivalente a menos de 3,75 €/mês');

        const withoutEquivalences = text.replace(
            /equivalente a menos de \d,\d{2} €\/mês/g,
            '',
        );

        expect(withoutEquivalences).not.toContain('/mês');
        expect(withoutEquivalences).not.toMatch(/\bMensal\b/);
    });

    it('presents Fundador as the same Pro at a launch price', () => {
        const text = pricing().text();

        expect(text).toContain(FOUNDER.title);
        expect(text).toContain(FOUNDER_PRICE);
        expect(text).toContain(PRO_PRICE);
        expect(text).toContain(FOUNDER.clarification);
        expect(text).toContain('31 de dezembro de 2026');
        expect(text).toContain('250');
    });

    it('invents no scarcity counter', () => {
        const text = pricing().text();

        // Nothing claims a number of places taken or left, because nothing
        // records one. «primeiros 250» is the cap, not a countdown.
        expect(text).not.toMatch(/restam/i);
        expect(text).not.toMatch(/lugares? (disponíve|restante)/i);
        expect(text).not.toMatch(/\d+\s+de\s+250/);
    });

    it('drops «Falar connosco» when no address is configured, and mails it when one is', () => {
        expect(pricing().find('a[href^="mailto:"]').exists()).toBe(false);

        const withAddress = pricing('geral@exemplo.pt');

        expect(
            withAddress.find('a[href^="mailto:"]').attributes('href'),
        ).toContain('mailto:geral@exemplo.pt');
    });

    it('never uses the language the brief rules out', () => {
        const text = [pricing().text(), voucher().text()].join(' ');

        for (const banned of [
            'beta tester',
            'versão beta',
            'produto experimental',
            'software em testes',
        ]) {
            expect(text.toLowerCase()).not.toContain(banned);
        }
    });
});

describe('the comparison table', () => {
    it('derives every mark from the entitlement keys, not from copy', () => {
        const [base, pro, institutional] = plans();
        const analytics = COMPARE_ROWS.find(
            (row) => row.label === 'Estado 360º',
        )!;

        expect(availability(analytics, base.key, base.moduleKeys)).toBe(
            'absent',
        );
        expect(availability(analytics, pro.key, pro.moduleKeys)).toBe(
            'included',
        );

        // Move it into Base — as the seeder legitimately could — and the table
        // follows without anybody editing a row.
        expect(
            availability(analytics, base.key, [
                ...base.moduleKeys,
                'advanced_analytics',
            ]),
        ).toBe('included');

        expect(
            availability(
                analytics,
                institutional.key,
                institutional.moduleKeys,
            ),
        ).toBe('included');
    });

    /**
     * Base SHOWS positive facts about a student; Pro NAMES them without being
     * asked. One row that read «Pontos fortes e potencialidades → Pro» hid a
     * Base capability (`BuildStudentStrengths`) behind a paywall it does not
     * actually sit behind.
     */
    it('keeps factual positives in Base and only their reading in Pro', () => {
        const [base, pro] = plans();

        const factual = COMPARE_ROWS.find(
            (row) => row.label === 'Registos positivos e evidência factual',
        )!;
        const interpreted = COMPARE_ROWS.find((row) =>
            row.label.startsWith('Identificação automática de pontos fortes'),
        )!;

        expect(availability(factual, base.key, base.moduleKeys)).toBe(
            'included',
        );
        expect(availability(factual, pro.key, pro.moduleKeys)).toBe('included');

        expect(availability(interpreted, base.key, base.moduleKeys)).toBe(
            'absent',
        );
        expect(availability(interpreted, pro.key, pro.moduleKeys)).toBe(
            'included',
        );
    });

    /**
     * The code has two AI features — strategy suggestions and the report
     * writing assistant — and no third one that touches a classification. A
     * row named for one would be a capability invented on the page.
     */
    it('names only the AI the product actually has', () => {
        const aiRows = COMPARE_ROWS.filter((row) =>
            row.label.startsWith('IA '),
        ).map((row) => row.label);

        expect(aiRows).toEqual(['IA pedagógica', 'IA aplicada a relatórios']);
        expect(aiRows).not.toContain('IA aplicada à avaliação');

        // And the page says out loud where the AI stops.
        const text = mount(LandingCompare, {
            props: { plans: plans() },
        }).text();

        expect(text).toContain('Não atribui nem decide classificações.');
    });

    it('marks what the offer defines and the product has not built as «em preparação»', () => {
        const licences = COMPARE_ROWS.find(
            (row) => row.label === 'Gestão de licenças',
        )!;
        const [base, , institutional] = plans();

        expect(
            availability(licences, institutional.key, institutional.moduleKeys),
        ).toBe('planned');
        expect(availability(licences, base.key, base.moduleKeys)).toBe(
            'absent',
        );
    });

    it('renders both a table and a per-plan list, from the same rows', () => {
        const wrapper = mount(LandingCompare, { props: { plans: plans() } });

        expect(wrapper.findAll('tbody tr')).toHaveLength(COMPARE_ROWS.length);
        expect(wrapper.findAll('details')).toHaveLength(plans().length);

        // The three sentences carry the section, so they must be in the DOM.
        expect(wrapper.text()).toContain('Base regista e mostra.');
        expect(wrapper.text()).toContain(
            'Pro cruza, interpreta e ajuda a agir.',
        );
        expect(wrapper.text()).toContain(
            'Institucional coordena, partilha e agrega.',
        );
    });
});

function voucher() {
    return mount(LandingVoucher);
}

describe('the voucher field', () => {
    it('never claims a code was accepted', async () => {
        const wrapper = voucher();

        await wrapper.find('input').setValue('LAPISPRO-1234-5678');
        await wrapper.find('form').trigger('submit');

        const status = wrapper.find('#voucher-status').text();

        expect(status).not.toMatch(/aplicad|válid|ativad/i);
        expect(status).toContain('esta página não valida códigos');
    });

    it('asks for a code before doing anything else', async () => {
        const wrapper = voucher();

        await wrapper.find('form').trigger('submit');

        expect(wrapper.find('#voucher-status').text()).toContain(
            'Introduza o código do voucher.',
        );
    });
});
