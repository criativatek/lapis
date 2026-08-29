import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
// The seeder's own source, read at transform time by Vite's `?raw`. See the
// note above the plan lists below for why this file reads PHP.
import seeder from '../../../../database/seeders/EntitlementsSeeder.php?raw';
import { featureFor } from '../marketing/features';
import {
    availability,
    COMPARE_ROWS,
    FOUNDER,
    FOUNDER_PRICE,
    PLAN_COPY,
    PRO_PRICE,
} from './commercial';
import LandingCompare from './LandingCompare.vue';
import LandingFaq from './LandingFaq.vue';
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

/**
 * THE PLAN SHAPES ARE READ FROM THE SEEDER, NOT TYPED BESIDE IT.
 *
 * These three lists used to be written out here by hand, and a hand-written
 * copy of the composition is the one fixture that cannot fail: it moves when
 * somebody remembers to move it, which is precisely when the copy it is
 * checking was already updated too. A row that lost its ✓ in production would
 * have kept it here. So the composition comes out of
 * `database/seeders/EntitlementsSeeder.php` — the same file the deploy runs,
 * and the same one HomeController's payload ultimately derives from — and the
 * assertions below compare the PROMISE (the copy in commercial.ts) against it.
 *
 * Reading PHP from a Vitest file is unusual and deliberate. The alternative
 * that keeps everything in TypeScript is a fixture, and a fixture here is the
 * bug. It arrives through Vite's `?raw`, so the path is resolved by the same
 * resolver as every other import in the project and a moved seeder fails
 * loudly at transform time rather than quietly at runtime.
 */

/**
 * The keys listed by one `const NAME = [ … ];` in the seeder.
 *
 * Comments are stripped first, because they quote key names too («…the
 * advanced import stayed Pro, under 'calendar_import'») and a naive scan would
 * read a sentence about a key as the key itself. A `...self::OTHER` spread
 * carries no quotes and is skipped here; the caller composes the lists, the
 * same way the seeder does.
 *
 * In the catalogue const the entries are `'key' => 'Nome'`, and only the key
 * side survives the pattern: every display name has a capital or a space in
 * it, so none of them looks like a key.
 */
function seededList(source: string, name: string): string[] {
    const opening = source.indexOf(`const ${name} = [`);

    if (opening < 0) {
        throw new Error(`${name} not found in EntitlementsSeeder.php`);
    }

    const body = source
        .slice(opening, source.indexOf('\n    ];', opening))
        .split('\n')
        .map((line) => line.split('//')[0])
        .join('\n');

    return [...body.matchAll(/'([a-z0-9_]+)'/g)].map((match) => match[1]);
}

/** Every key the catalogue defines — what a COMPARE_ROW may legitimately name. */
const CATALOGUE = seededList(seeder, 'MODULES');

const BASE_MODULES = seededList(seeder, 'BASE_MODULES');
const PRO_MODULES = [...BASE_MODULES, ...seededList(seeder, 'PRO_MODULES')];
const INSTITUTIONAL_MODULES = [
    ...PRO_MODULES,
    ...seededList(seeder, 'INSTITUTIONAL_MODULES'),
];

function plans() {
    return [
        { key: 'base', name: 'Base', moduleKeys: [...BASE_MODULES] },
        { key: 'pro', name: 'Pro', moduleKeys: [...PRO_MODULES] },
        {
            key: 'institutional',
            name: 'Institucional',
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
        // One <details> per plan; the outer one is the «Ver a tabela
        // completa» toggle that hides the whole block by default (0.92.0).
        expect(
            wrapper.findAll('details:not([data-compare-toggle])'),
        ).toHaveLength(plans().length);

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

/**
 * THE OFFER, AGAINST THE COMPOSITION IT IS SOLD ON.
 *
 * The block above checks that the table DERIVES its marks. This one checks
 * that the sentences around the table agree with what it derives them from —
 * the seeded plan lists read at the top of this file. A page may describe a
 * capability in any words it likes; what it may not do is place it in a plan
 * the server does not place it in, because the visitor pays for the sentence
 * and gets the entitlement.
 *
 * Every assertion here comes from the Base/Pro realignment, which moved
 * `calendar` into Base, split `calendar_import` out of it, and put restoring a
 * backup behind `data_backup_restore` while leaving the export ungated.
 */
/**
 * Rendered text carries the template's own line breaks and indentation, so a
 * sentence written across two lines in a .vue file is not the sentence a
 * reader sees. Every prose assertion below reads the squished form.
 */
const squish = (text: string): string => text.replace(/\s+/g, ' ');

describe('the offer against the composition', () => {
    it('names only keys the catalogue actually defines', () => {
        // A row naming a key that does not exist renders «não incluído» in
        // every column, silently, and looks exactly like a capability the
        // product decided not to sell.
        const named = [...new Set(COMPARE_ROWS.flatMap((row) => row.modules))];

        expect(named.length).toBeGreaterThan(0);

        for (const key of named) {
            expect(CATALOGUE).toContain(key);
        }
    });

    it('sells the calendar as Base and only its import as Pro', () => {
        const [base, pro] = plans();

        const calendar = COMPARE_ROWS.find((row) =>
            row.modules.includes('calendar'),
        )!;
        const importRow = COMPARE_ROWS.find((row) =>
            row.modules.includes('calendar_import'),
        )!;

        // Not «the fixture says so»: BASE_MODULES came out of the seeder.
        expect(BASE_MODULES).toContain('calendar');
        expect(BASE_MODULES).not.toContain('calendar_import');
        expect(PRO_MODULES).toContain('calendar_import');

        expect(availability(calendar, base.key, base.moduleKeys)).toBe(
            'included',
        );
        expect(availability(importRow, base.key, base.moduleKeys)).toBe(
            'absent',
        );
        expect(availability(importRow, pro.key, pro.moduleKeys)).toBe(
            'included',
        );

        // Two rows, so the table can say both things at once. A single row on
        // `calendar` labelled for the import would tick Base and promise the
        // school's .xlsx with the free plan.
        expect(calendar.label).not.toBe(importRow.label);
        expect(importRow.label).toMatch(/importa/i);
    });

    it('never presents the calendar as something the Pro plan adds', () => {
        // Stated as the rule rather than as today's text: while Base carries
        // `calendar`, a Pro bullet about the calendar is only honest if it is
        // about the import.
        expect(BASE_MODULES).toContain('calendar');

        for (const feature of PLAN_COPY.pro.features) {
            if (/agenda|calendári/i.test(feature)) {
                expect(feature).toMatch(/importa/i);
            }
        }

        // And the Base card says it, because the Base plan has it. A
        // capability nobody is told about is sold to nobody.
        expect(
            PLAN_COPY.base.features.some((feature) =>
                /calendári/i.test(feature),
            ),
        ).toBe(true);
    });

    it('does not deny in prose what the table marks in Base', () => {
        const prose = squish(
            [
                // The «Aulas e sumários» page is where the agenda is described
                // since the one-page landing was split (0.94.0).
                featureFor('aulas-e-sumarios')
                    .benefits.map(
                        (benefit) => `${benefit.title} ${benefit.body}`,
                    )
                    .join(' '),
                mount(LandingFaq).text(),
            ].join(' '),
        );

        // The page and the FAQ both name the agenda; neither may hand it to
        // the Pro plan while the comparison table ticks it for Base.
        expect(prose).toMatch(/agenda do ano letivo/i);
        expect(prose).not.toMatch(
            /quatro capacidades fazem parte do\s+plano Pro/i,
        );
        expect(prose).toMatch(/agenda do ano letivo[^.]*em todos os planos/i);
    });

    it('keeps restoring a backup in Pro and exporting in every plan', () => {
        const [base, pro] = plans();

        const restore = COMPARE_ROWS.find((row) =>
            row.modules.includes('data_backup_restore'),
        )!;

        expect(BASE_MODULES).not.toContain('data_backup_restore');
        expect(PRO_MODULES).toContain('data_backup_restore');

        expect(availability(restore, base.key, base.moduleKeys)).toBe('absent');
        expect(availability(restore, pro.key, pro.moduleKeys)).toBe('included');

        // The label may not read as «exporting is paid»: the export has no key
        // behind it and is ticked for every plan.
        expect(restore.label).not.toMatch(/exporta/i);
        expect(
            PLAN_COPY.base.features.some((feature) => /exporta/i.test(feature)),
        ).toBe(true);

        // And the section says so out loud, beside a row that names only the
        // half that is paid.
        expect(
            squish(mount(LandingCompare, { props: { plans: plans() } }).text()),
        ).toMatch(
            /exportação dos seus próprios dados existe em todos os planos/i,
        );
    });

    it('keeps every interpretive reading in Pro', () => {
        const [base, pro] = plans();

        // The realignment's other direction: what Base showed and should not
        // have. Each of these rows is one Matriz line, and all of them hang on
        // the same key the server checks.
        const interpretive = COMPARE_ROWS.filter((row) =>
            row.modules.includes('advanced_analytics'),
        );

        expect(interpretive.length).toBeGreaterThanOrEqual(5);
        expect(BASE_MODULES).not.toContain('advanced_analytics');

        for (const row of interpretive) {
            expect(availability(row, base.key, base.moduleKeys)).toBe('absent');
            expect(availability(row, pro.key, pro.moduleKeys)).toBe('included');
        }
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
