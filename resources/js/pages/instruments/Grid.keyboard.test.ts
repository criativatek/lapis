import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import Grid from './Grid.vue';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { post: inertia.post },
    useForm: () => ({ reason: '', errors: {}, processing: false, reset: vi.fn(), clearErrors: vi.fn(), post: vi.fn() }),
    usePage: () => ({ props: { auth: { user: { id: 17 }, organization: { ulid: 'organization-keyboard' } } } }),
}));

// Três perguntas em dois grupos (por isso há uma coluna de subtotal entre a primeira e a segunda) e três alunos.
const items = [
    { id: 201, group_index: 0, code: 'P1', label: null, points_possible: 10, is_bonus: false, domains: [] },
    { id: 202, group_index: 1, code: 'P2', label: null, points_possible: 10, is_bonus: false, domains: [] },
    { id: 203, group_index: 1, code: 'P3', label: null, points_possible: 10, is_bonus: false, domains: [] },
];
const groups = [{ ulid: 'g0', label: 'Grupo I' }, { ulid: 'g1', label: 'Grupo II' }];
const student = (id: number, name: string) => ({ enrollment_id: id, name, photo_url: null, class_number: id, enrolled_on: '2026-01-01', is_late_entry: false, joined_after_instrument: false });
const score = (enrollment: number, itemId: number, state: string, points: number | null) => ({
    enrollment_id: enrollment,
    instrument_item_id: itemId,
    result_state: state,
    points_earned: points,
    state_reason: null,
    lock_version: 1,
});

let mounted: VueWrapper[] = [];

function build(overrides: { completed?: boolean; scores?: ReturnType<typeof score>[] } = {}) {
    const wrapper = mount(Grid, {
        attachTo: document.body,
        props: {
            instrument: {
                ulid: 'kb', title: 'Ficha', applied_on: '2026-10-20', status_label: 'Em correção', is_completed: overrides.completed ?? false, can_complete: false,
                pending_count: 0, applicable_count: 3, completed_count: 0, completed_at: null, pending_students: [], status: 'applied',
                cancellation_reason: null, total_points: 30, class_label: '7.º A', class_ulid: 'c', period: '1.º Semestre',
            },
            groups,
            items,
            students: [student(1, 'Aluno Um'), student(2, 'Aluno Dois'), student(3, 'Aluno Três')],
            scores: overrides.scores ?? [],
            states: [
                { value: 'pending', label: 'Por avaliar', carries_value: false, resolves: false },
                { value: 'assessed', label: 'Classificado', carries_value: true, resolves: true },
                { value: 'absent', label: 'Faltou', carries_value: false, resolves: true },
            ],
            scaleBands: [],
            official: { status: 'provisional', label: 'Classificação provisória', threshold: null, domains: [], students: {} },
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    mounted.forEach((wrapper) => wrapper.unmount());
    mounted = [];
    document.body.innerHTML = '';
    inertia.post.mockClear();
});

const box = (wrapper: VueWrapper, row: number, column: number) => wrapper.get(`input[data-cell="${row}-${column}"]`);
const value = (wrapper: VueWrapper, row: number, column: number) => (box(wrapper, row, column).element as HTMLInputElement).value;
const saveButton = (wrapper: VueWrapper) => wrapper.findAll('button').find((button) => button.text().includes('Guardar'))!;

async function type(wrapper: VueWrapper, row: number, column: number, text: string) {
    await box(wrapper, row, column).setValue(text);
}

function press(wrapper: VueWrapper, row: number, column: number, key: string, cursor?: 'start' | 'end' | 'middle') {
    const input = box(wrapper, row, column).element as HTMLInputElement;

    input.focus();

    if (cursor === 'start') {
        input.setSelectionRange(0, 0);
    } else if (cursor === 'end') {
        input.setSelectionRange(input.value.length, input.value.length);
    } else if (cursor === 'middle') {
        input.setSelectionRange(1, 1);
    }

    return box(wrapper, row, column).trigger('keydown', { key });
}

const focused = () => (document.activeElement as HTMLElement | null)?.getAttribute('data-cell');

describe('instruments/Grid — vírgula e ponto decimais', () => {
    it('aceita «3,5» e «3.5» como 3,5 e envia números no pedido', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '3,5');
        await type(wrapper, 1, 0, '3.5');
        await type(wrapper, 2, 0, ' 4 ');
        await saveButton(wrapper).trigger('click');

        const payload = inertia.post.mock.calls[0][1] as { cells: Array<{ points_earned: number | null }> };
        expect(payload.cells.map((entry) => entry.points_earned)).toEqual([3.5, 3.5, 4]);
    });

    it('não troca o separador a meio da escrita e mostra a vírgula depois de sair da célula', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '3.5');
        expect(value(wrapper, 0, 0)).toBe('3.5');

        await box(wrapper, 0, 0).trigger('blur');
        expect(value(wrapper, 0, 0)).toBe('3,5');
    });

    it('preserva a precisão: 3.333 não é arredondado', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '3.333');
        await box(wrapper, 0, 0).trigger('blur');

        expect(value(wrapper, 0, 0)).toBe('3,333');
    });

    it('mostra as classificações vindas do servidor com vírgula e sem zeros inúteis', () => {
        const wrapper = build({ scores: [score(1, 201, 'assessed', 3.5), score(1, 202, 'assessed', 4)] });

        expect(value(wrapper, 0, 0)).toBe('3,5');
        expect(value(wrapper, 0, 1)).toBe('4');
        expect(value(wrapper, 0, 2)).toBe('');
    });

    it('um texto inválido não é guardado, marca a célula e desativa o Guardar com a dica', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '2');
        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();

        await type(wrapper, 1, 0, '3,5,1');

        expect(box(wrapper, 1, 0).attributes('aria-invalid')).toBe('true');
        expect(box(wrapper, 1, 0).classes()).toContain('border-destructive');
        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
        expect(saveButton(wrapper).attributes('title')).toBe('Há classificações inválidas.');
        expect(wrapper.text()).toContain('Há classificações inválidas');

        // Corrigir liberta o Guardar.
        await type(wrapper, 1, 0, '3,5');
        expect(box(wrapper, 1, 0).attributes('aria-invalid')).toBeUndefined();
        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('um texto inválido nunca conta como alteração', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, 'abc');

        expect(wrapper.text()).toContain('Há classificações inválidas');
        expect(wrapper.text()).not.toContain('alteraç');
        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('«3,» a meio da escrita é inválido; «,5» vale 0,5; vazio é por avaliar', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '3,');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBe('true');

        await type(wrapper, 0, 0, ',5');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBeUndefined();
        await box(wrapper, 0, 0).trigger('blur');
        expect(value(wrapper, 0, 0)).toBe('0,5');

        await type(wrapper, 0, 0, '');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBeUndefined();
        expect(value(wrapper, 0, 0)).toBe('');
    });

    it('a cotação máxima continua a ser validada sobre o número, também com vírgula', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, '10,5');

        expect(wrapper.text()).toContain('excede 10 pts');
        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
    });
});

describe('instruments/Grid — setas entre células', () => {
    it('→ salta a coluna de subtotal e vai à pergunta seguinte da mesma linha; ← volta', async () => {
        const wrapper = build();

        // data-cell usa o índice da pergunta: P1 = 0, P2 = 1, P3 = 2; o subtotal do grupo I fica entre P1 e P2.
        await press(wrapper, 0, 0, 'ArrowRight');
        expect(focused()).toBe('0-1');

        await press(wrapper, 0, 1, 'ArrowRight');
        expect(focused()).toBe('0-2');

        await press(wrapper, 0, 2, 'ArrowLeft');
        expect(focused()).toBe('0-1');
    });

    it('↓/↑ andam na mesma pergunta; Enter confirma e desce', async () => {
        const wrapper = build();

        await type(wrapper, 0, 1, '4.5');
        await press(wrapper, 0, 1, 'Enter');

        expect(focused()).toBe('1-1');
        expect(value(wrapper, 0, 1)).toBe('4,5');

        await press(wrapper, 1, 1, 'ArrowDown');
        expect(focused()).toBe('2-1');

        await press(wrapper, 2, 1, 'ArrowUp');
        expect(focused()).toBe('1-1');
    });

    it('nas extremidades a tecla não faz nada de especial (sem salto de linha ou coluna)', async () => {
        const wrapper = build();

        await press(wrapper, 0, 0, 'ArrowLeft');
        expect(focused()).toBe('0-0');
        await press(wrapper, 0, 0, 'ArrowUp');
        expect(focused()).toBe('0-0');
        await press(wrapper, 2, 2, 'ArrowRight');
        expect(focused()).toBe('2-2');
        await press(wrapper, 2, 2, 'ArrowDown');
        expect(focused()).toBe('2-2');
    });

    it('com a seta a mudar de célula há preventDefault; Tab fica com o navegador', () => {
        const wrapper = build();

        const arrow = new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true });
        box(wrapper, 0, 0).element.dispatchEvent(arrow);
        expect(arrow.defaultPrevented).toBe(true);

        const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        box(wrapper, 0, 0).element.dispatchEvent(tab);
        expect(tab.defaultPrevented).toBe(false);
    });

    it('a célula de destino fica com o texto selecionado e é trazida à vista', async () => {
        const wrapper = build({ scores: [score(2, 201, 'assessed', 7.5)] });
        const scroll = vi.fn();
        (box(wrapper, 1, 0).element as HTMLInputElement).scrollIntoView = scroll;

        await press(wrapper, 0, 0, 'ArrowDown');

        const target = box(wrapper, 1, 0).element as HTMLInputElement;
        expect(document.activeElement).toBe(target);
        expect([target.selectionStart, target.selectionEnd]).toEqual([0, 3]);
        expect(scroll).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' });
    });

    it('← e → só mudam de célula no início/fim do texto; a meio movem o cursor', async () => {
        const wrapper = build({ scores: [score(1, 202, 'assessed', 12.5)] });

        await press(wrapper, 0, 1, 'ArrowLeft', 'middle');
        expect(focused()).toBe('0-1');
        await press(wrapper, 0, 1, 'ArrowRight', 'middle');
        expect(focused()).toBe('0-1');
        await press(wrapper, 0, 1, 'ArrowRight', 'start');
        expect(focused()).toBe('0-1');
        await press(wrapper, 0, 1, 'ArrowLeft', 'end');
        expect(focused()).toBe('0-1');

        await press(wrapper, 0, 1, 'ArrowRight', 'end');
        expect(focused()).toBe('0-2');
        await press(wrapper, 0, 2, 'ArrowLeft', 'start');
        expect(focused()).toBe('0-1');
    });

    it('salta as células desativadas (estado «faltou»)', async () => {
        const wrapper = build({ scores: [score(1, 202, 'absent', null)] });

        expect(box(wrapper, 0, 1).attributes('disabled')).toBeDefined();
        await press(wrapper, 0, 0, 'ArrowRight');

        expect(focused()).toBe('0-2');
    });

    it('Esc repõe o último valor confirmado e mantém o foco', async () => {
        const wrapper = build({ scores: [score(1, 201, 'assessed', 7.5)] });

        await type(wrapper, 0, 0, '9,,');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBe('true');

        await press(wrapper, 0, 0, 'Escape');

        expect(focused()).toBe('0-0');
        expect(value(wrapper, 0, 0)).toBe('7,5');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBeUndefined();
        expect(saveButton(wrapper).attributes('title')).not.toBe('Há classificações inválidas.');
    });

    it('Esc desfaz tudo o que se escreveu desde que se entrou na célula, não só o último carácter', async () => {
        const wrapper = build({ scores: [score(1, 201, 'assessed', 7.5)] });

        // «3,5» é válido e entra em `cells`; «,1» a seguir torna o texto inválido. Esc tem de voltar ao
        // 7,5 que lá estava ao entrar — e, como é o valor guardado, deixa de haver alterações por guardar.
        // Foco a sério (não um `trigger('focus')`): é ao entrar na célula que a origem fica registada, e o
        // `press` abaixo não volta a disparar `focus` num elemento que já o tem — como num browser.
        (box(wrapper, 0, 0).element as HTMLInputElement).focus();
        await type(wrapper, 0, 0, '3,5');
        expect(wrapper.text()).toContain('1 alteração por guardar');
        await type(wrapper, 0, 0, '3,5,1');
        await press(wrapper, 0, 0, 'Escape');

        expect(value(wrapper, 0, 0)).toBe('7,5');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBeUndefined();
        expect(wrapper.text()).toContain('Sem alterações por guardar');
    });

    it('com texto inválido nem as setas nem o Enter saem da célula', async () => {
        const wrapper = build();

        await type(wrapper, 0, 0, 'abc');

        for (const key of ['ArrowDown', 'ArrowRight', 'Enter']) {
            await press(wrapper, 0, 0, key, 'end');
            expect(focused()).toBe('0-0');
        }

        expect(value(wrapper, 0, 0)).toBe('abc');
        expect(box(wrapper, 0, 0).attributes('aria-invalid')).toBe('true');
    });

    it('numa correção concluída o foco move-se mas nada é editável', async () => {
        const wrapper = build({ completed: true });

        expect(box(wrapper, 0, 0).attributes('readonly')).toBeDefined();
        await press(wrapper, 0, 0, 'ArrowDown');

        expect(focused()).toBe('1-0');
        expect(box(wrapper, 1, 0).attributes('readonly')).toBeDefined();
    });

    it('mostra a linha de ajuda das teclas', () => {
        expect(build().text()).toContain('Setas: mover entre células');
    });
});

describe('instruments/Grid — cabeçalho preso ao topo', () => {
    it('a grelha é um painel que rola nos dois eixos, com o cabeçalho sticky e opaco', () => {
        const wrapper = build();

        // Um sticky só prende dentro do contentor que rola: o painel tem de rolar na vertical
        // (altura máxima) e não apenas na horizontal.
        const pane = wrapper.get('[data-testid="grid-scroll"]').classes();
        expect(pane).toContain('overflow-auto');
        expect(pane.some((c) => c.startsWith('max-h-'))).toBe(true);

        const head = wrapper.get('[data-testid="grid-head"]').classes();
        expect(head).toContain('sticky');
        expect(head).toContain('top-0');
        // Opaco: nada do que desliza por baixo pode transparecer.
        expect(head).toContain('bg-muted');
        expect(head.join(' ')).not.toContain('bg-muted/');

        // A célula «Aluno» prende nos dois eixos e também é opaca.
        const corner = wrapper.get('[data-testid="grid-head"] th').classes();
        expect(corner).toContain('sticky');
        expect(corner).toContain('left-0');
        expect(corner).toContain('bg-muted');
    });
});

describe('instruments/Grid — barra de deslocação horizontal', () => {
    function withLayout(scrollWidth: number, clientWidth: number, rect?: { top: number; bottom: number }) {
        const spyWidth = vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(scrollWidth);
        const spyClient = vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(clientWidth);
        const spyRect = rect === undefined
            ? null
            : vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
                x: 0,
                y: rect.top,
                top: rect.top,
                bottom: rect.bottom,
                left: 0,
                right: clientWidth,
                width: clientWidth,
                height: rect.bottom - rect.top,
                toJSON: () => ({}),
            } as DOMRect);

        return () => {
            spyWidth.mockRestore();
            spyClient.mockRestore();
            spyRect?.mockRestore();
        };
    }

    it('não aparece quando a tabela cabe no contentor', () => {
        const restore = withLayout(500, 800);
        const wrapper = build();

        expect(wrapper.get('[data-testid="grid-scrollbar"]').attributes('style')).toContain('display: none');
        restore();
    });

    it('aparece, com a largura da tabela, quando há overflow e o fundo do painel está abaixo do ecrã', async () => {
        const restore = withLayout(1600, 800, { top: 300, bottom: window.innerHeight + 400 });
        const wrapper = build();
        await nextTick();

        const bar = wrapper.get('[data-testid="grid-scrollbar"]');
        expect(bar.attributes('style') ?? '').not.toContain('display: none');
        // `fixed`, não `sticky`: o <main> da aplicação tem overflow e prenderia um sticky ao seu fundo.
        expect(bar.classes()).toContain('fixed');
        expect(bar.classes()).toContain('bottom-0');
        expect(bar.get('div').attributes('style')).toContain('width: 1600px');
        // As barras nativas do painel nunca são escondidas: a vertical é o sinal de que há mais
        // alunos abaixo.
        expect(wrapper.get('[data-testid="grid-scroll"]').classes().join(' ')).not.toContain('scrollbar-width');
        restore();
    });

    it('esconde-se quando o fundo do painel (com a barra nativa) já está no ecrã', async () => {
        const restore = withLayout(1600, 800, { top: 100, bottom: window.innerHeight - 50 });
        const wrapper = build();
        await nextTick();

        expect(wrapper.get('[data-testid="grid-scrollbar"]').attributes('style')).toContain('display: none');
        restore();
    });

    it('sincroniza o scrollLeft nos dois sentidos', async () => {
        const restore = withLayout(1600, 800);
        const wrapper = build();
        await nextTick();

        const container = wrapper.get('[data-testid="grid-scroll"]').element as HTMLElement;
        const bar = wrapper.get('[data-testid="grid-scrollbar"]').element as HTMLElement;

        bar.scrollLeft = 240;
        await wrapper.get('[data-testid="grid-scrollbar"]').trigger('scroll');
        expect(container.scrollLeft).toBe(240);

        container.scrollLeft = 90;
        await wrapper.get('[data-testid="grid-scroll"]').trigger('scroll');
        expect(bar.scrollLeft).toBe(90);
        restore();
    });

    it('a coluna do aluno continua colada à esquerda', () => {
        const restore = withLayout(1600, 800);
        const wrapper = build();

        expect(wrapper.find('tbody td.sticky.left-0').exists()).toBe(true);
        restore();
    });
});
