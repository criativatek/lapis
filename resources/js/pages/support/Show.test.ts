import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Show from './Show.vue';

/**
 * O AVISO DE RECEÇÃO NÃO PODE MENTIR SOBRE O EMAIL.
 *
 * O pedido fica registado de qualquer maneira — é a confirmação por email que
 * pode ter falhado. Dizer «enviámos uma confirmação» quando o servidor a
 * recusou põe a pessoa à espera de algo que não vem e, quando não vier, a
 * duvidar do pedido inteiro, que está perfeitamente guardado.
 *
 * E há aqui uma segunda coisa a segurar, que já falhou uma vez: a flash do
 * Inertia viaja em `page.flash`, AO LADO dos props e não dentro deles. Lida de
 * `page.props.flash` é sempre `undefined`, e o painel simplesmente não aparece
 * — sem erro nenhum, sem nada partido, apenas silêncio. Estes testes montam o
 * componente com a flash no sítio real.
 */

const inertia = vi.hoisted(() => ({
    flash: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: () => () => null }),
    useForm: () => ({
        body: '',
        processing: false,
        errors: {},
        post: vi.fn(),
        reset: vi.fn(),
    }),
    usePage: () => ({ flash: inertia.flash }),
}));

vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        inheritAttrs: false,
        setup:
            (_, { slots }) =>
            () =>
                h('button', slots.default?.()),
    }),
}));

function request() {
    return {
        ulid: 'ped-1',
        reference: 'SUP-A1B2C3',
        subject: 'A pauta não importa',
        categoryLabel: 'Importações',
        status: 'open',
        statusLabel: 'Aberto',
        createdAt: '2026-08-31T10:00:00+01:00',
        autoResolved: false,
        description: 'O ficheiro dá erro.',
        messages: [],
    };
}

function render() {
    return mount(Show, { props: { request: request() } });
}

describe('o aviso de receção do pedido de suporte', () => {
    beforeEach(() => {
        inertia.flash = {};
    });

    it('confirma o email quando ele saiu mesmo', () => {
        inertia.flash = {
            supportAcknowledgement: {
                reference: 'SUP-A1B2C3',
                emailDelivered: true,
            },
        };

        const text = render().text();

        expect(text).toContain('Pedido enviado com sucesso');
        expect(text).toContain('SUP-A1B2C3');
        expect(text).toContain(
            'Enviámos também uma confirmação para o seu email',
        );
        expect(text).not.toContain('não pôde ser enviada');
    });

    it('admite que o email não saiu, em vez de afirmar que saiu', () => {
        inertia.flash = {
            supportAcknowledgement: {
                reference: 'SUP-A1B2C3',
                emailDelivered: false,
            },
        };

        const text = render().text();

        // O pedido ficou registado — e isso continua a ser dito.
        expect(text).toContain('SUP-A1B2C3');
        expect(text).toContain('foi recebido pela equipa de suporte');
        expect(text).toContain('o pedido ficou registado normalmente');
        // O que NÃO é dito: que houve email.
        expect(text).not.toContain('Enviámos também uma confirmação');
    });

    it('não aparece nas visitas seguintes ao pedido', () => {
        const text = render().text();

        expect(text).not.toContain('Pedido enviado com sucesso');
        expect(text).not.toContain('Enviámos também uma confirmação');
    });
});
