import { mount } from '@vue/test-utils';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import Welcome from './Welcome.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
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

function welcome() {
    return mount(Welcome, {
        global: {
            stubs: {
                MarketingShell: {
                    template: '<div><slot :authenticated="false" /></div>',
                },
                ColorBand: {
                    template: '<section><slot /></section>',
                },
                RevealOnScroll: {
                    template: '<div><slot /></div>',
                },
                HeroCard: true,
                LandingAi: true,
                LandingFinalCta: true,
                LandingHowItWorks: true,
                PageHero: true,
                PhotoBand: true,
                ScreenFrame: true,
                TileArt: true,
            },
        },
        props: { contactEmail: null },
    });
}

describe('the landing «Pro em destaque»', () => {
    it('shows the short version, with the way to /planos, and does not repeat the whole section', () => {
        const wrapper = welcome();
        const text = wrapper.text();

        expect(text).toContain(
            'No Pro, vê a turma ao longo do ano — não só num momento.',
        );
        expect(text).toContain('Compare dois momentos');
        expect(text).toContain('Pontos fortes e resultados a observar');
        expect(text).toContain('Tendências e resultados persistentes');
        // Os outros dois blocos vivem só em /planos.
        expect(text).not.toContain('Cada aluno, ao longo do ano');
        expect(text).not.toContain('Leve a análise para o papel');

        const link = wrapper
            .findAll('a')
            .find((a) => a.attributes('href') === '/planos#pro-em-destaque');

        expect(link).toBeDefined();
        expect(text).not.toContain('44,90');
    });
});

describe('the public landing plan invitation', () => {
    it('leads with the free Base offer and keeps plan discovery available', () => {
        const wrapper = welcome();
        const links = wrapper.findAll('a');

        expect(wrapper.text()).toContain(
            'Comece gratuitamente no ano letivo 2026/27.',
        );
        expect(wrapper.text()).toContain(
            'Organize turmas, avaliações, aulas e informação pedagógica sem compromisso.',
        );
        expect(
            links.some((link) => link.text().includes('Criar conta gratuita')),
        ).toBe(true);

        const plansLink = links.find((link) =>
            link.text().includes('Conhecer os planos'),
        );

        expect(plansLink?.attributes('href')).toBe('/planos');
        expect(wrapper.text()).not.toContain('44,90');
    });
});
