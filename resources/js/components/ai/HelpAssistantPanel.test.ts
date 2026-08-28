import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HelpAssistantPanel from './HelpAssistantPanel.vue';

/**
 * The panel's states, and the two things it must never do: post anything but
 * the question, and render an answer as raw HTML.
 */

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

type Props = InstanceType<typeof HelpAssistantPanel>['$props'];

const wrappers: VueWrapper[] = [];

function mountPanel(props: Partial<Props> = {}) {
    const wrapper = mount(HelpAssistantPanel, {
        props: { ai: { available: true, reason: null }, ...props } as Props,
    });

    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => post.mockReset());

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('HelpAssistantPanel — availability', () => {
    it('offers the form when the experience is available', () => {
        const wrapper = mountPanel();

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.text()).toContain('Assistente Lapispro');
    });

    it('explains a plan lock without offering a button that could only fail', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'plan' } });

        expect(wrapper.text()).toContain('não está incluído no plano');
        expect(wrapper.find('textarea').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('distinguishes a switched-off installation from a plan lock', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'off' } });

        expect(wrapper.text()).toContain('não está ativado nesta instalação');
        expect(wrapper.text()).not.toContain('plano');
    });

    it('reports a half-configured engine without naming the missing setting', () => {
        // The gateway returns six distinct provider slugs; a teacher gets one
        // sentence, because none of the six is something they can act on.
        for (const reason of ['credential_missing', 'model_missing', 'endpoint_missing', 'unknown_driver', 'fake_in_production']) {
            const wrapper = mountPanel({ ai: { available: false, reason } });

            expect(wrapper.text()).toContain('não está configurado nesta instalação');
            expect(wrapper.text()).not.toContain(reason);
        }
    });

    it('falls back to the configuration sentence for a slug it has never seen', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'something_the_core_added_later' } });

        expect(wrapper.text()).toContain('não está configurado nesta instalação');
    });

    it('reassures that the articles still work when the assistant does not', () => {
        const wrapper = mountPanel({ ai: { available: false, reason: 'provider' } });

        expect(wrapper.text()).toContain('artigos do Centro de Ajuda continuam disponíveis');
    });
});

describe('HelpAssistantPanel — asking', () => {
    it('will not submit an empty question', async () => {
        const wrapper = mountPanel();

        await wrapper.find('form').trigger('submit');

        expect(post).not.toHaveBeenCalled();
    });

    it('posts the question and nothing else', async () => {
        const wrapper = mountPanel();

        await wrapper.find('textarea').setValue('  Como crio uma turma?  ');
        await wrapper.find('form').trigger('submit');

        expect(post).toHaveBeenCalledTimes(1);

        const [, payload] = post.mock.calls[0];

        // The exact payload — a regression here is a privacy regression, not
        // a cosmetic one.
        expect(payload).toEqual({ question: 'Como crio uma turma?' });
    });

    it('carries a question typed in the search box without the teacher retyping it', () => {
        const wrapper = mountPanel({ initialQuestion: 'como registo resultados' });

        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('como registo resultados');
    });
});

describe('HelpAssistantPanel — answers', () => {
    const answer = {
        question: 'Como crio uma turma?',
        text: 'Vá a Turmas.\n\nDepois carregue em «Nova turma».',
        references: [{ id: 'classes.create', title: 'Criar uma turma', url: '/help/classes.create' }],
        sufficient: true,
    };

    it('renders the answer as paragraphs and links the articles it used', () => {
        const wrapper = mountPanel({ answer });

        expect(wrapper.findAll('p').some((p) => p.text() === 'Vá a Turmas.')).toBe(true);
        expect(wrapper.text()).toContain('Artigos em que esta resposta se baseia');

        const link = wrapper.find('a[href="/help/classes.create"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Criar uma turma');
    });

    it('never renders the answer as HTML', () => {
        const wrapper = mountPanel({
            answer: { ...answer, text: '<img src=x onerror="alert(1)">' },
        });

        // Escaped text still CONTAINS the substring «onerror=» — asserting on
        // the markup string would pass on a genuinely vulnerable component
        // that merely escaped the quotes. What proves it is that no element
        // was created: the answer became text, not DOM.
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x');
    });

    it('shows the disclosure with every answer', () => {
        const wrapper = mountPanel({ answer });

        expect(wrapper.text()).toContain('A IA apoia a análise e pode cometer erros');
        expect(wrapper.text()).toContain('decisões pedagógicas continuam a ser do professor');
    });

    it('presents an undocumented question calmly, with no references and no error styling', () => {
        const wrapper = mountPanel({
            answer: {
                question: 'xyzzy',
                text: 'A documentação do Centro de Ajuda não cobre esta pergunta.',
                references: [],
                sufficient: false,
            },
        });

        expect(wrapper.text()).toContain('não cobre esta pergunta');
        expect(wrapper.text()).not.toContain('Artigos em que esta resposta se baseia');
        expect(wrapper.text()).not.toContain('Tentar novamente');
    });
});

describe('HelpAssistantPanel — failure', () => {
    it('shows a controlled message and lets the teacher retry without reloading', async () => {
        const wrapper = mountPanel({ error: { message: 'Não foi possível obter uma resposta neste momento.' } });

        expect(wrapper.text()).toContain('Não foi possível obter uma resposta');

        // The form is still standing, so the retry is one click.
        expect(wrapper.find('textarea').exists()).toBe(true);

        await wrapper.find('textarea').setValue('Como crio uma turma?');
        const retry = wrapper.findAll('button').find((button) => button.text().includes('Tentar novamente'));

        expect(retry).toBeDefined();
        await retry!.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('HelpAssistantPanel — accessibility', () => {
    it('labels its own region and its input, and announces answers politely', () => {
        const wrapper = mountPanel();

        const section = wrapper.find('section');
        expect(section.attributes('aria-labelledby')).toBe('assistente-lapispro');
        expect(wrapper.find('#assistente-lapispro').exists()).toBe(true);

        expect(wrapper.find('label[for="assistente-pergunta"]').exists()).toBe(true);
        expect(wrapper.find('textarea').attributes('id')).toBe('assistente-pergunta');

        expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    });

    it('marks the live region busy only while a question is in flight', async () => {
        const wrapper = mountPanel();

        expect(wrapper.find('[aria-live="polite"]').attributes('aria-busy')).toBe('false');

        await wrapper.find('textarea').setValue('Como crio uma turma?');
        await wrapper.find('form').trigger('submit');

        // onStart is the caller's callback; drive it the way Inertia would.
        post.mock.calls[0][2].onStart();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[aria-live="polite"]').attributes('aria-busy')).toBe('true');
    });

    it('hides decorative icons from assistive technology', () => {
        const wrapper = mountPanel();

        wrapper.findAll('svg').forEach((icon) => {
            expect(icon.attributes('aria-hidden')).toBe('true');
        });
    });
});
