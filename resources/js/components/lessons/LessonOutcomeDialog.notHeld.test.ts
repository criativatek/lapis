import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { NotHeldOptions } from '@/lib/lessonOutcomeOptions';
import LessonOutcomeDialog from './LessonOutcomeDialog.vue';

const posted = vi.hoisted(() => [] as { url: string; data: Record<string, unknown> }[]);

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, {
            transform: (fn: (current: Record<string, unknown>) => Record<string, unknown>) => ({
                post: (url: string) => posted.push({ url, data: fn({ ...form }) }),
            }),
            reset: vi.fn(),
        });
    },
}));

// O diálogo real teleporta para fora da árvore; aqui renderiza-se inline.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = { template: '<div><slot /></div>' };

    return {
        Dialog: passthrough,
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const options: NotHeldOptions = {
    causes: [
        { value: 'strike', label: 'Greve' },
        { value: 'weather_closure', label: 'Encerramento por mau tempo' },
        { value: 'other', label: 'Outra causa' },
    ],
    specifications: [
        { value: 'adhered', label: 'Aderi à greve' },
        { value: 'not_adhered', label: 'Não aderi à greve' },
    ],
};

function mountDialog(notHeldOptions: NotHeldOptions | null = options) {
    return mount(LessonOutcomeDialog, {
        props: {
            open: true,
            lessonUlid: 'lesson-a',
            reasons: [{ value: 'training', label: 'Formação' }],
            notHeldOptions,
        },
        global: { stubs: { teleport: true } },
    });
}

async function chooseNotHeld(wrapper: ReturnType<typeof mountDialog>) {
    await wrapper.get('[data-testid="outcome-not-held"] input').setValue(true);
}

describe('LessonOutcomeDialog — aula não realizada', () => {
    beforeEach(() => {
        posted.length = 0;
    });

    it('não oferece «Aula não realizada» com a funcionalidade desligada', () => {
        const wrapper = mountDialog(null);

        expect(wrapper.find('[data-testid="outcome-not-held"]').exists()).toBe(false);
    });

    it('oferece a opção, pede a causa e só mostra a especificação com «Greve»', async () => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);

        expect(wrapper.find('[data-testid="not-held-fields"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="strike-specification"]').exists()).toBe(false);

        await wrapper.get('#outcome-cause').setValue('strike');

        expect(wrapper.get('[data-testid="strike-specification"]').text()).toContain('Não especificar');
        expect(wrapper.get('[data-testid="strike-specification"]').text()).toContain('Aderi à greve');
        expect(wrapper.get('[data-testid="strike-specification"]').text()).toContain('Não aderi à greve');
    });

    it('«Greve» sem especificar envia só a causa — a especificação é facultativa', async () => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');
        await wrapper.get('form').trigger('submit');

        expect(posted).toEqual([{ url: '/lessons/lesson-a/outcome', data: { outcome: 'not_held', cause: 'strike' } }]);
    });

    it.each([
        ['adhered', 'Aderi à greve'],
        ['not_adhered', 'Não aderi à greve'],
    ])('especificar «%s» envia a situação escolhida', async (value) => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');
        await wrapper.get(`[data-testid="strike-specification"] input[value="${value}"]`).setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(posted[0].data).toEqual({ outcome: 'not_held', cause: 'strike', situation: value });
    });

    it('mudar de «Greve» para outra causa limpa a especificação: nunca viaja sem greve', async () => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');
        await wrapper.get('[data-testid="strike-specification"] input[value="adhered"]').setValue(true);
        await wrapper.get('#outcome-cause').setValue('weather_closure');
        await wrapper.get('form').trigger('submit');

        expect(posted[0].data).toEqual({ outcome: 'not_held', cause: 'weather_closure' });
    });

    it('«Outra causa» pede uma descrição breve e envia-a aparada', async () => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('other');
        await wrapper.get('#outcome-cause-note').setValue('  Falha de eletricidade  ');
        await wrapper.get('form').trigger('submit');

        expect(posted[0].data).toEqual({ outcome: 'not_held', cause: 'other', cause_note: 'Falha de eletricidade' });
    });

    it('sem a chave da adesão, «Aderi» não é oferecido', async () => {
        const wrapper = mountDialog({ ...options, specifications: [{ value: 'not_adhered', label: 'Não aderi à greve' }] });
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');

        expect(wrapper.get('[data-testid="strike-specification"]').text()).not.toContain('Aderi à greve');
    });

    it('as outras opções continuam como estavam', async () => {
        const wrapper = mountDialog();
        await wrapper.get('input[value="class_external_activity"]').setValue(true);
        await wrapper.get('form').trigger('submit');

        expect(posted[0].data).toEqual({ outcome: 'class_external_activity', note: null });
    });
});

describe('LessonOutcomeDialog — registo retroativo', () => {
    beforeEach(() => {
        posted.length = 0;
    });

    function mountRetro(blockers: string[] = []) {
        return mount(LessonOutcomeDialog, {
            props: {
                open: true,
                lessonUlid: 'lesson-a',
                lessonContext: '7.º A · 09:30–10:20',
                reasons: [],
                notHeldOptions: options,
                retroactive: { closed_after: '22/10/2026', blockers },
            },
            global: { stubs: { teleport: true } },
        });
    }

    it('só oferece «Aula não realizada», diz a data da aula fechada e o efeito na numeração', () => {
        const wrapper = mountRetro();

        expect(wrapper.find('input[value="teacher_absent"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="retroactive-info"]').text()).toContain('22/10/2026');
        expect(wrapper.get('[data-testid="retroactive-info"]').text()).toContain('nenhuma aula já lecionada muda de número');
    });

    it('pede confirmação antes de gravar e envia retroactive só à segunda', async () => {
        const wrapper = mountRetro();
        await wrapper.get('#outcome-cause').setValue('strike');
        await wrapper.get('form').trigger('submit');

        expect(posted).toEqual([]);
        expect(wrapper.get('[data-testid="retroactive-confirm"]').text()).toContain('7.º A · 09:30–10:20');

        await wrapper.get('form').trigger('submit');

        expect(posted).toEqual([
            { url: '/lessons/lesson-a/outcome', data: { outcome: 'not_held', cause: 'strike', retroactive: true } },
        ]);
    });

    it('«Rever» volta atrás sem gravar', async () => {
        const wrapper = mountRetro();
        await wrapper.get('#outcome-cause').setValue('weather_closure');
        await wrapper.get('form').trigger('submit');
        await wrapper.get('[data-testid="retroactive-confirm"] button').trigger('click');

        expect(wrapper.find('[data-testid="retroactive-confirm"]').exists()).toBe(false);
        expect(posted).toEqual([]);
    });

    it('explica concretamente porque não é possível e não deixa continuar', async () => {
        const wrapper = mountRetro(['tem sumário ou preparação escritos', 'tem faltas em rascunho']);
        await wrapper.get('#outcome-cause').setValue('strike');

        const blockers = wrapper.get('[data-testid="retroactive-blockers"]').text();
        expect(blockers).toContain('tem sumário ou preparação escritos');
        expect(blockers).toContain('tem faltas em rascunho');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();

        await wrapper.get('form').trigger('submit');
        expect(posted).toEqual([]);
    });
});
