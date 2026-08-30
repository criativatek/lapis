import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PageHero from '@/components/marketing/PageHero.vue';
import Plans from './Plans.vue';

/**
 * THE HERO MAY NOT OUTLIVE THE PROMOTION.
 *
 * The badge, the Fundador band and the FAQ answer are gated on the server's
 * `founder.open`; the hero of this page was not, so once the seats or the
 * deadline ran out the first screen still offered 29,90 € to somebody who
 * could no longer have it. Shallow-mounted: what is asserted is the copy this
 * page hands to the hero, not what the hero draws.
 */
const plans = [
    { key: 'base', name: 'Base', moduleKeys: [] },
    { key: 'pro', name: 'Pro', moduleKeys: [] },
    { key: 'institutional', name: 'Institucional', moduleKeys: [] },
];

function hero(open: boolean) {
    const wrapper = mount(Plans, {
        shallow: true,
        global: {
            // The hero lives in MarketingShell's slot; a shallow stub would
            // never render it, and this test is about what the page hands the
            // hero, not about the shell.
            stubs: {
                MarketingShell: {
                    template: '<div><slot :authenticated="false" /></div>',
                },
            },
        },
        props: {
            plans,
            founder: { open },
            seoTitle: 'Planos e preços para professores | Lapispro',
            contactEmail: null,
        },
    });

    const pageHero = wrapper.findComponent(PageHero);

    return `${pageHero.props('lead')} ${(pageHero.props('chips') as string[]).join(' ')}`;
}

describe('the /planos hero', () => {
    it('names the Membro Fundador condition while it is open', () => {
        expect(hero(true)).toContain('Membro Fundador');
    });

    it('stops naming it — and its price — once it has closed', () => {
        const copy = hero(false);

        expect(copy).not.toContain('Fundador');
        expect(copy).not.toContain('29,90');
        expect(copy).toContain('44,90');
    });
});
