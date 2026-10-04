/**
 * A projeção na sala: só o que é dos alunos, a letra que cresce e encolhe, o
 * ecrã inteiro e o Escape (que sai primeiro do ecrã inteiro). As primitivas
 * reais do reka-ui montam em jsdom, num portal para o <body>: por isso estes
 * testes montam com `attachTo: document.body` e procuram em `document.body`.
 */
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import LessonProjection from '@/components/lessons/week/LessonProjection.vue';
import type { LessonProjection as Projection } from '@/lib/lessonProjection';

const SCALE_KEY = 'lapis.lessons.projectionScale';

function makeProjection(overrides: Partial<Projection> = {}): Projection {
    return {
        lessonUlid: 'lesson-a',
        classLabel: '8.º B',
        subject: 'Físico-Química',
        groupLabel: 'Grupo T1',
        dateLabel: 'Quinta-feira, 1 de outubro de 2026',
        lessonNumber: 8,
        summary:
            'Primeira linha.\nSegunda linha do mesmo parágrafo.\n\nSegundo parágrafo.\n\nTerceiro.',
        unsaved: false,
        ...overrides,
    };
}

let wrapper: VueWrapper | null = null;

async function mountProjection(
    props: Partial<{ open: boolean; projection: Projection | null }> = {},
) {
    wrapper = mount(LessonProjection, {
        props: { open: true, projection: makeProjection(), ...props },
        attachTo: document.body,
    });
    await nextTick();
    await nextTick();

    return wrapper;
}

function find(testid: string): HTMLElement | null {
    return document.body.querySelector<HTMLElement>(
        `[data-testid="${testid}"]`,
    );
}

function get(testid: string): HTMLElement {
    const element = find(testid);

    if (element === null) {
        throw new Error(`Sem [data-testid="${testid}"]`);
    }

    return element;
}

function press(key: string, init: KeyboardEventInit = {}): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        key,
        bubbles: true,
        cancelable: true,
        ...init,
    });
    document.dispatchEvent(event);

    return event;
}

type FullscreenDocument = {
    fullscreenEnabled?: boolean;
    fullscreenElement?: Element | null;
    exitFullscreen?: () => Promise<void>;
};

function simulateFullscreen() {
    const doc = document as unknown as FullscreenDocument;
    const requestFullscreen = vi.fn(function (this: Element) {
        doc.fullscreenElement = this;
        document.dispatchEvent(new Event('fullscreenchange'));

        return Promise.resolve();
    });
    const exitFullscreen = vi.fn(() => {
        doc.fullscreenElement = null;
        document.dispatchEvent(new Event('fullscreenchange'));

        return Promise.resolve();
    });

    doc.fullscreenEnabled = true;
    doc.fullscreenElement = null;
    doc.exitFullscreen = exitFullscreen;
    (
        Element.prototype as unknown as { requestFullscreen: unknown }
    ).requestFullscreen = requestFullscreen;

    return { requestFullscreen, exitFullscreen };
}

function resetFullscreen(): void {
    const doc = document as unknown as Record<string, unknown>;

    delete doc.fullscreenEnabled;
    delete doc.fullscreenElement;
    delete doc.exitFullscreen;
    delete (Element.prototype as unknown as Record<string, unknown>)
        .requestFullscreen;
}

beforeEach(() => {
    window.localStorage.clear();
    resetFullscreen();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetFullscreen();
    vi.useRealTimers();
});

describe('conteúdo', () => {
    it('mostra o contexto, a data, a lição e o sumário completo', async () => {
        await mountProjection();

        expect(get('projection-context').textContent).toContain(
            '8.º B · Físico-Química · Grupo T1',
        );
        expect(get('projection-date').textContent).toBe(
            'Quinta-feira, 1 de outubro de 2026',
        );
        expect(get('projection-number').textContent?.trim()).toBe('Lição 8');
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
    });

    it('preserva os parágrafos e as quebras de linha dentro do parágrafo', async () => {
        await mountProjection();

        const paragraphs = get('projection-summary').querySelectorAll('p');

        expect(paragraphs).toHaveLength(3);
        expect(paragraphs[0].textContent).toBe(
            'Primeira linha.\nSegunda linha do mesmo parágrafo.',
        );
        expect(paragraphs[0].className).toContain('whitespace-pre-line');
        expect(paragraphs[1].textContent).toBe('Segundo parágrafo.');
    });

    it('sem grupo, o contexto é só turma e disciplina', async () => {
        await mountProjection({
            projection: makeProjection({ groupLabel: null }),
        });

        expect(get('projection-context').textContent).toContain(
            '8.º B · Físico-Química',
        );
        expect(get('projection-context').textContent).not.toContain('Grupo');
    });

    it('sem número não aparece «Lição» nem «Sem número»', async () => {
        await mountProjection({
            projection: makeProjection({ lessonNumber: null }),
        });

        expect(find('projection-number')).toBeNull();
        expect(document.body.textContent).not.toContain('Lição');
        expect(document.body.textContent).not.toContain('Sem número');
    });

    it('sem sumário mostra a mensagem e nenhum parágrafo', async () => {
        await mountProjection({
            projection: makeProjection({ summary: null }),
        });

        expect(get('projection-empty').textContent).toContain(
            'Esta aula ainda não tem sumário.',
        );
        expect(find('projection-summary')).toBeNull();
    });

    it('«Por guardar» só aparece com um rascunho por guardar', async () => {
        await mountProjection();
        expect(find('projection-unsaved')).toBeNull();

        await wrapper!.setProps({
            projection: makeProjection({ unsaved: true }),
        });

        expect(get('projection-unsaved').textContent).toContain('Por guardar');
        expect(get('projection-unsaved').textContent).toContain(
            'este texto ainda não foi guardado',
        );
    });

    it('não mostra nada de interno: estado, assiduidade, notas, TPC, revisto', async () => {
        await mountProjection();
        const text = document.body.textContent ?? '';

        for (const internal of [
            'Lecionada',
            'Preparada',
            'falta',
            'Assiduidade',
            'TPC',
            'Notas',
            'Recursos',
            'revisto',
            'Resultado',
        ]) {
            expect(text).not.toContain(internal);
        }
    });

    it('a área de leitura rola e é focável; não corta o texto', async () => {
        await mountProjection();
        const reader = get('projection-reading');

        expect(reader.getAttribute('tabindex')).toBe('0');
        expect(reader.className).toContain('overflow-y-auto');
        expect(reader.innerHTML).not.toContain('line-clamp');
        expect(document.activeElement).toBe(reader);
    });

    it('fechada, não mostra nada', async () => {
        await mountProjection({ open: false });

        expect(find('lesson-projection')).toBeNull();
    });
});

describe('tamanho da letra', () => {
    it('A+ e A− mudam a escala (por omissão 100%) e ela fica guardada', async () => {
        await mountProjection();

        expect(get('projection-scale').textContent).toBe('100%');
        get('projection-larger').click();
        await nextTick();
        expect(get('projection-scale').textContent).toBe('115%');
        expect(
            get('projection-reading').parentElement?.getAttribute('style'),
        ).toContain('--projection-scale: 1.15');
        expect(window.localStorage.getItem(SCALE_KEY)).toBe('1.15');

        get('projection-smaller').click();
        get('projection-smaller').click();
        await nextTick();
        expect(get('projection-scale').textContent).toBe('85%');
    });

    it('os botões desativam nos limites', async () => {
        await mountProjection();

        for (let step = 0; step < 10; step++) {
            get('projection-larger').click();
            await nextTick();
        }

        expect(get('projection-scale').textContent).toBe('200%');
        expect(get('projection-larger').hasAttribute('disabled')).toBe(true);

        for (let step = 0; step < 10; step++) {
            get('projection-smaller').click();
            await nextTick();
        }

        expect(get('projection-scale').textContent).toBe('70%');
        expect(get('projection-smaller').hasAttribute('disabled')).toBe(true);
    });

    it('os atalhos + , = e - fazem o mesmo, e Ctrl+ fica para o zoom do browser', async () => {
        await mountProjection();

        press('+');
        await nextTick();
        expect(get('projection-scale').textContent).toBe('115%');
        press('=');
        await nextTick();
        expect(get('projection-scale').textContent).toBe('130%');
        press('-');
        await nextTick();
        expect(get('projection-scale').textContent).toBe('115%');
        press('+', { ctrlKey: true });
        await nextTick();
        expect(get('projection-scale').textContent).toBe('115%');
    });

    it('lembra a escala escolhida da última vez', async () => {
        window.localStorage.setItem(SCALE_KEY, '1.5');
        await mountProjection();

        expect(get('projection-scale').textContent).toBe('150%');
    });

    it('ignora uma escala guardada que não existe', async () => {
        window.localStorage.setItem(SCALE_KEY, '9');
        await mountProjection();

        expect(get('projection-scale').textContent).toBe('100%');
    });

    it('funciona na mesma quando o armazenamento falha', async () => {
        const getItem = vi
            .spyOn(Storage.prototype, 'getItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });
        const setItem = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new Error('bloqueado');
            });

        await mountProjection();
        get('projection-larger').click();
        await nextTick();

        expect(get('projection-scale').textContent).toBe('115%');
        getItem.mockRestore();
        setItem.mockRestore();
    });
});

describe('ecrã inteiro', () => {
    it('o botão não existe sem suporte do browser', async () => {
        await mountProjection();

        expect(find('projection-fullscreen')).toBeNull();
    });

    it('alterna entre entrar e sair do ecrã inteiro', async () => {
        const { requestFullscreen, exitFullscreen } = simulateFullscreen();
        await mountProjection();

        expect(get('projection-fullscreen').getAttribute('aria-label')).toBe(
            'Ecrã inteiro',
        );
        get('projection-fullscreen').click();
        await nextTick();

        expect(requestFullscreen).toHaveBeenCalledTimes(1);
        expect(get('projection-fullscreen').getAttribute('aria-label')).toBe(
            'Sair do ecrã inteiro',
        );

        get('projection-fullscreen').click();
        await nextTick();

        expect(exitFullscreen).toHaveBeenCalledTimes(1);
        expect(get('projection-fullscreen').getAttribute('aria-label')).toBe(
            'Ecrã inteiro',
        );
    });
});

describe('fechar', () => {
    it('o botão «Fechar» pede para fechar', async () => {
        await mountProjection();

        get('projection-close').click();

        expect(wrapper!.emitted('update:open')).toEqual([[false]]);
    });

    it('Escape fora do ecrã inteiro fecha', async () => {
        await mountProjection();

        press('Escape');

        expect(wrapper!.emitted('update:open')).toEqual([[false]]);
    });

    it('Escape em ecrã inteiro só sai do ecrã inteiro e a projeção continua aberta', async () => {
        const { exitFullscreen } = simulateFullscreen();
        await mountProjection();
        get('projection-fullscreen').click();
        await nextTick();

        press('Escape');
        await nextTick();

        expect(exitFullscreen).toHaveBeenCalledTimes(1);
        expect(wrapper!.emitted('update:open')).toBeUndefined();
        expect(find('lesson-projection')).not.toBeNull();
    });

    it('o Escape que chega logo depois de sair do ecrã inteiro não fecha', async () => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2026-10-01T10:00:00Z'));
        simulateFullscreen();
        await mountProjection();
        get('projection-fullscreen').click();
        await nextTick();
        // O browser sai do ecrã inteiro por si e o Escape ainda chega ao documento.
        (document as unknown as FullscreenDocument).fullscreenElement = null;
        document.dispatchEvent(new Event('fullscreenchange'));
        vi.setSystemTime(new Date('2026-10-01T10:00:00.100Z'));

        press('Escape');
        expect(wrapper!.emitted('update:open')).toBeUndefined();

        vi.setSystemTime(new Date('2026-10-01T10:00:01Z'));
        press('Escape');
        expect(wrapper!.emitted('update:open')).toEqual([[false]]);
    });

    it('«Fechar» em ecrã inteiro sai do ecrã inteiro e fecha', async () => {
        const { exitFullscreen } = simulateFullscreen();
        await mountProjection();
        get('projection-fullscreen').click();
        await nextTick();

        // Só fecha DEPOIS de o ecrã inteiro ter saído: se o elemento em ecrã
        // inteiro desmonta antes, o foco cai no <body> em vez de voltar ao cartão.
        const order: string[] = [];
        exitFullscreen.mockImplementationOnce(async () => {
            order.push('exit');
            (document as unknown as FullscreenDocument).fullscreenElement =
                null;
            document.dispatchEvent(new Event('fullscreenchange'));
        });
        get('projection-close').click();
        expect(wrapper!.emitted('update:open')).toBeUndefined();
        await flushPromises();
        order.push('closed');

        expect(exitFullscreen).toHaveBeenCalled();
        expect(wrapper!.emitted('update:open')).toEqual([[false]]);
        expect(order).toEqual(['exit', 'closed']);
    });

    it('clicar fora não fecha', async () => {
        await mountProjection();

        document.body.dispatchEvent(
            new PointerEvent('pointerdown', { bubbles: true }),
        );
        document.body.click();
        await nextTick();

        expect(wrapper!.emitted('update:open')).toBeUndefined();
    });

    it('devolve o foco ao elemento que tinha o foco ao abrir', async () => {
        const opener = document.createElement('button');
        document.body.append(opener);
        opener.focus();
        const scrollY = window.scrollY;

        await mountProjection({ open: false });
        await wrapper!.setProps({ open: true });
        await nextTick();
        expect(document.activeElement).toBe(get('projection-reading'));

        await wrapper!.setProps({ open: false });
        await nextTick();
        await nextTick();

        expect(document.activeElement).toBe(opener);
        expect(window.scrollY).toBe(scrollY);
    });

    it('remove os ouvintes ao desmontar', async () => {
        const remove = vi.spyOn(document, 'removeEventListener');
        await mountProjection();

        wrapper!.unmount();
        wrapper = null;

        expect(remove.mock.calls.map(([type]) => type)).toEqual(
            expect.arrayContaining(['keydown', 'fullscreenchange']),
        );
        remove.mockRestore();
    });
});
