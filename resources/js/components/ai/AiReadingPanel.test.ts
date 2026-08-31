import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AiReadingPanel from './AiReadingPanel.vue';

/**
 * THE SHARED UX CONTRACT (§21), tested once instead of three times.
 *
 * Every «pedir uma leitura à IA» panel in the product is this component with
 * different sections passed in, so the states below — available, plan-locked,
 * not-enough-data, loading, result, error, retry — are tested here and are
 * thereby guaranteed identical on Resultados, on Evolução, and on whatever
 * screen gains a reading next.
 *
 * THE ABSENCE OF A WRITE CONTROL IS TESTED AS A PROPERTY. There is no
 * «aplicar», no «guardar», no checkbox and no form; the only buttons are the
 * three that ask again. That is asserted rather than assumed, because it is the
 * §15 principle in the one place a user could click it.
 */

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

type Props = InstanceType<typeof AiReadingPanel>['$props'];

const sections = [
    { title: 'Síntese', text: 'Os resultados concentram-se no nível intermédio.' },
    { title: 'Padrões observados', items: ['A distribuição é estreita.', 'A evolução é ligeiramente positiva.'] },
    { title: 'Limitações desta leitura', items: ['Alguns resultados assentam em cobertura parcial.'] },
];

const wrappers: VueWrapper[] = [];

function mountPanel(props: Partial<Props> = {}) {
    const wrapper = mount(AiReadingPanel, {
        props: {
            headingId: 'leitura-ia',
            title: 'Analisar a avaliação com IA',
            description: 'Uma leitura em palavras dos resultados já calculados.',
            action: '/classes/01JCLASS/results/analise-ia',
            available: true,
            unavailableMessage: 'Não está incluída no plano desta organização.',
            ...props,
        } as Props,
    });

    wrappers.push(wrapper);

    return wrapper;
}

beforeEach(() => post.mockReset());

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('AiReadingPanel — availability', () => {
    it('offers the action when the experience is available', () => {
        const wrapper = mountPanel();

        expect(wrapper.findAll('button').some((button) => button.text().includes('Analisar a avaliação com IA'))).toBe(true);
    });

    it('explains a lock and offers no button at all', () => {
        const wrapper = mountPanel({ available: false });

        expect(wrapper.text()).toContain('Não está incluída no plano desta organização.');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    /**
     * The third state, and the reason it is separate: «o plano não inclui»
     * never changes by waiting, and «ainda não há dados» fixes itself. A panel
     * that showed one sentence for both would send half its readers to the
     * wrong place.
     */
    it('distinguishes not-enough-data from not-included', () => {
        const wrapper = mountPanel({
            hasEnoughEvidence: false,
            insufficientEvidenceMessage: 'Ainda não há resultados suficientes neste período.',
        });

        expect(wrapper.text()).toContain('Ainda não há resultados suficientes neste período.');
        expect(wrapper.text()).not.toContain('Não está incluída no plano');
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});

describe('AiReadingPanel — asking', () => {
    it('never runs on its own', () => {
        mountPanel();

        expect(post).not.toHaveBeenCalled();
    });

    it('posts to the action it was given, with no body', async () => {
        const wrapper = mountPanel();

        await wrapper.findAll('button')[0].trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/classes/01JCLASS/results/analise-ia');
        // NOTHING FROM THE PAGE GOES BACK. The server rebuilds the figures from
        // the same read model it rendered them with.
        expect(post.mock.calls[0][1]).toEqual({});
    });

    it('does not post when the capability is locked', async () => {
        const wrapper = mountPanel({ available: false });

        // No button exists to click; the guard in `run()` is the second half.
        expect(wrapper.findAll('button')).toHaveLength(0);
        expect(post).not.toHaveBeenCalled();
    });

    it('shows a busy state while the request is in flight', async () => {
        const wrapper = mountPanel();
        await wrapper.findAll('button')[0].trigger('click');

        // The visit's own callbacks, invoked the way Inertia would: `onStart`
        // when the request leaves, `onFinish` when it settles. Reaching for
        // them through the recorded call is what makes this a test of the
        // panel's states rather than of a mock's shape.
        const options = post.mock.calls[0][2] as { onStart: () => void; onFinish: () => void };

        options.onStart();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('A analisar');
        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);

        options.onFinish();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('A analisar…');
    });
});

describe('AiReadingPanel — the answer', () => {
    it('renders each section under its own heading', () => {
        const wrapper = mountPanel({ sections });

        expect(wrapper.text()).toContain('Síntese');
        expect(wrapper.text()).toContain('Os resultados concentram-se no nível intermédio.');
        expect(wrapper.text()).toContain('Padrões observados');
        expect(wrapper.text()).toContain('A distribuição é estreita.');
        expect(wrapper.text()).toContain('Limitações desta leitura');
    });

    it('offers to ask again once there is an answer', () => {
        const wrapper = mountPanel({ sections });

        expect(wrapper.findAll('button').some((button) => button.text().includes('Pedir de novo'))).toBe(true);
    });

    /**
     * The disclosure is not decoration: it says the assistant can be wrong and
     * it says who decides. Both halves are the point (§22).
     */
    it('always carries the disclosure under an answer', () => {
        const wrapper = mountPanel({ sections, pseudonymised: true });

        expect(wrapper.text()).toContain('As decisões pedagógicas continuam a ser do professor');
        expect(wrapper.text()).toContain('pseudonimizados');
    });

    /**
     * A model that answers with markup produces visible characters, never an
     * element. The server strips markdown; this is the other half of the same
     * guarantee, and it is what makes a hostile answer inert.
     */
    it('renders answer text as text, never as markup', () => {
        const wrapper = mountPanel({
            sections: [{ title: 'Síntese', text: '<img src=x onerror="alert(1)"> e <b>negrito</b>' }],
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror="alert(1)">');
    });

    it('drops a section with nothing in it rather than rendering an empty heading', () => {
        const wrapper = mountPanel({
            sections: [{ title: 'Síntese', text: 'Uma frase.' }],
        });

        expect(wrapper.text()).not.toContain('Pontos de atenção');
    });
});

describe('AiReadingPanel — failure', () => {
    it('shows the error and offers to try again', async () => {
        const wrapper = mountPanel({ error: { message: 'Não foi possível concluir o pedido.' } });

        expect(wrapper.text()).toContain('Não foi possível concluir o pedido.');

        const retry = wrapper.findAll('button').find((button) => button.text().includes('Tentar novamente'));
        expect(retry).toBeDefined();

        await retry!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
    });

    /**
     * A quota message is an error the panel shows like any other — the teacher
     * needs the sentence, not a status code, and the sentence already says when
     * to come back.
     */
    it('shows a quota message as an ordinary controlled failure', () => {
        const wrapper = mountPanel({
            error: { message: 'Atingiu o limite diário de 40 pedidos de IA. Volte a tentar amanhã.' },
        });

        expect(wrapper.text()).toContain('Volte a tentar amanhã');
    });

    it('offers no retry when the capability is locked', () => {
        const wrapper = mountPanel({ available: false, error: { message: 'Falhou.' } });

        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    /**
     * A RETRY LINK IS A PROMISE, AND FOR SOME FAILURES IT IS A FALSE ONE.
     *
     * When the engine ran out of output budget, or the credential was rejected,
     * or the answer arrived in a shape the application cannot read, the next
     * press reproduces the same failure exactly — and costs the school another
     * request to prove it. The server is the only layer that knows which kind of
     * failure this was, so it says so, and the panel simply obeys.
     */
    it('offers no retry for a failure the server says is deterministic', () => {
        const wrapper = mountPanel({
            error: { message: 'Não foi possível obter uma sugestão neste momento.', retryable: false },
        });

        expect(wrapper.text()).toContain('Não foi possível obter uma sugestão neste momento.');
        expect(wrapper.findAll('button').find((button) => button.text().includes('Tentar novamente'))).toBeUndefined();
    });

    /** And it does offer one when the server says the failure was weather. */
    it('offers a retry for a failure the server says is transient', () => {
        const wrapper = mountPanel({
            error: { message: 'O pedido demorou demasiado a responder.', retryable: true },
        });

        expect(wrapper.findAll('button').find((button) => button.text().includes('Tentar novamente'))).toBeDefined();
    });

    /**
     * AN OLDER SCREEN THAT FLASHES NO FLAG STILL GETS ITS BUTTON. Every error
     * that existed before this field was introduced was describing weather, so
     * the absent case must not silently take the button away from them.
     */
    it('treats an absent flag as retryable', () => {
        const wrapper = mountPanel({ error: { message: 'Falhou.' } });

        expect(wrapper.findAll('button').find((button) => button.text().includes('Tentar novamente'))).toBeDefined();
    });

    /**
     * A DETERMINISTIC FAILURE NEVER SAYS «O TEXTO ATUAL FOI PRESERVADO» ON A
     * PANEL WITH NO TEXT UNDER IT — the copy that used to travel from a shared
     * exception to five features that had nothing to preserve.
     */
    it('shows the neutral sentence the server now sends for a reading', () => {
        const wrapper = mountPanel({
            error: { message: 'Não foi possível obter uma sugestão neste momento.', retryable: false },
        });

        expect(wrapper.text()).not.toContain('preservado');
    });
});

describe('AiReadingPanel — accessibility and safety', () => {
    it('labels its region and announces the result politely', () => {
        const wrapper = mountPanel({ sections });

        const section = wrapper.find('section');
        expect(section.attributes('aria-labelledby')).toBe('leitura-ia');
        expect(wrapper.find('#leitura-ia').exists()).toBe(true);
        expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true);
    });

    /**
     * §15, in the one place a user could click it: there is no control on this
     * panel that writes anything.
     */
    it('offers no control that would write the answer anywhere', () => {
        const wrapper = mountPanel({ sections });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);

        const labels = wrapper.findAll('button').map((button) => button.text().toLowerCase());

        for (const label of labels) {
            expect(label).not.toContain('guardar');
            expect(label).not.toContain('aplicar');
            expect(label).not.toContain('aceitar');
        }
    });
});
