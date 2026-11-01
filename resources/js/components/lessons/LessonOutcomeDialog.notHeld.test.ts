import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { NotHeldOptions, OutcomeRectification } from '@/lib/lessonOutcomeOptions';
import LessonOutcomeDialog from './LessonOutcomeDialog.vue';

const posted = vi.hoisted(() => [] as { url: string; data: Record<string, unknown>; method?: string }[]);

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) => {
        const form = reactive({ ...data, errors: {}, processing: false });

        return Object.assign(form, {
            transform: (fn: (current: Record<string, unknown>) => Record<string, unknown>) => ({
                post: (url: string) => posted.push({ url, data: fn({ ...form }) }),
                patch: (url: string) => posted.push({ url, method: 'patch', data: fn({ ...form }) }),
            }),
            clearErrors: vi.fn(),
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
        { value: 'adhered', label: 'Greve do professor' },
        { value: 'school_closed', label: 'Greve — escola encerrada' },
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

        const text = wrapper.get('[data-testid="strike-specification"]').text();
        // As três opções, pela ordem: «Greve» (por defeito), «Greve do professor» e «Greve — escola encerrada».
        expect(text.indexOf('Greve')).toBeLessThan(text.indexOf('Greve do professor'));
        expect(text.indexOf('Greve do professor')).toBeLessThan(text.indexOf('Greve — escola encerrada'));
        expect((wrapper.get('input[name="situation"]:checked').element as HTMLInputElement).value).toBe('');
    });

    it('«Greve» sem especificar envia só a causa — a especificação é facultativa', async () => {
        const wrapper = mountDialog();
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');
        await wrapper.get('form').trigger('submit');

        expect(posted).toEqual([{ url: '/lessons/lesson-a/outcome', data: { outcome: 'not_held', cause: 'strike' } }]);
    });

    it.each([
        ['adhered', 'Greve do professor'],
        ['school_closed', 'Greve — escola encerrada'],
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

    it('sem a chave da adesão, «Greve do professor» não é oferecido mas «escola encerrada» sim', async () => {
        const wrapper = mountDialog({ ...options, specifications: [{ value: 'school_closed', label: 'Greve — escola encerrada' }] });
        await chooseNotHeld(wrapper);
        await wrapper.get('#outcome-cause').setValue('strike');

        expect(wrapper.get('[data-testid="strike-specification"]').text()).not.toContain('Greve do professor');
        expect(wrapper.get('[data-testid="strike-specification"]').text()).toContain('Greve — escola encerrada');
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

describe('LessonOutcomeDialog — corrigir o resultado', () => {
    beforeEach(() => {
        posted.length = 0;
    });

    const rectification = (overrides: Partial<OutcomeRectification> = {}): OutcomeRectification => ({
        current: { outcome: 'not_held', reason: null, note: null, cause: 'strike', situation: 'school_closed', cause_note: null },
        targets: ['teacher_absent', 'not_held'],
        outcome_locked_reason: null,
        history: [],
        ...overrides,
    });

    function mountRectify(rectify: OutcomeRectification, extra: Record<string, unknown> = {}) {
        return mount(LessonOutcomeDialog, {
            props: {
                open: false,
                lessonUlid: 'lesson-a',
                reasons: [{ value: 'training', label: 'Formação' }],
                notHeldOptions: options,
                lessonContext: '7.º A · 09:30–10:20',
                rectify,
                ...extra,
            },
            global: { stubs: { teleport: true } },
        });
    }

    async function opened(rectify: OutcomeRectification, extra: Record<string, unknown> = {}) {
        const wrapper = mountRectify(rectify, extra);
        await wrapper.setProps({ open: true });

        return wrapper;
    }

    it('abre com os valores atuais, diz que nada mais muda e pede o motivo da correção', async () => {
        const wrapper = await opened(rectification());

        expect(wrapper.text()).toContain('Corrigir o resultado desta aula');
        expect(wrapper.text()).toContain('os números das lições, o planeamento e o sumário não mudam');
        expect((wrapper.get('#outcome-cause').element as HTMLSelectElement).value).toBe('strike');
        expect((wrapper.get('input[name="situation"]:checked').element as HTMLInputElement).value).toBe('school_closed');
        expect(wrapper.find('[data-testid="rectify-motive"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="retroactive-info"]').exists()).toBe(false);
    });

    it('explica que não se corrige de ou para «Lecionada» nem «Turma noutra atividade letiva»', async () => {
        const wrapper = await opened(rectification());

        expect(wrapper.get('[data-testid="rectify-restriction"]').text()).toContain('alteraria a numeração');
    });

    it('só deixa guardar com um motivo e envia um PATCH com ele', async () => {
        const wrapper = await opened(rectification());
        await wrapper.get('#outcome-cause').setValue('weather_closure');

        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        await wrapper.get('form').trigger('submit');
        expect(posted).toEqual([]);

        await wrapper.get('#outcome-rectification-note').setValue('  Foi o mau tempo  ');
        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
        await wrapper.get('form').trigger('submit');

        expect(posted).toEqual([
            {
                url: '/lessons/lesson-a/outcome',
                method: 'patch',
                data: { rectification_note: 'Foi o mau tempo', outcome: 'not_held', cause: 'weather_closure' },
            },
        ]);
    });

    it('troca de «Aula não realizada» para «Professor ausente» com o motivo da ausência', async () => {
        const wrapper = await opened(rectification());
        await wrapper.get('input[value="teacher_absent"]').setValue(true);
        await wrapper.get('#outcome-reason').setValue('training');
        await wrapper.get('#outcome-rectification-note').setValue('Era formação');
        await wrapper.get('form').trigger('submit');

        expect(posted[0]).toEqual({
            url: '/lessons/lesson-a/outcome',
            method: 'patch',
            data: { outcome: 'teacher_absent', reason: 'training', rectification_note: 'Era formação' },
        });
    });

    it('numa atividade da turma só se corrige a descrição e diz-se porquê', async () => {
        const wrapper = await opened(
            rectification({
                current: { outcome: 'class_external_activity', reason: null, note: 'Visita', cause: null, situation: null, cause_note: null },
                targets: ['class_external_activity'],
                outcome_locked_reason: 'Passar para outro resultado altera a numeração.',
            }),
        );

        expect(wrapper.find('input[value="teacher_absent"]').exists()).toBe(false);
        expect(wrapper.find('input[value="not_held"]').exists()).toBe(false);
        expect(wrapper.get('[data-testid="rectify-locked"]').text()).toContain('altera a numeração');
        expect((wrapper.get('#outcome-note').element as HTMLInputElement).value).toBe('Visita');
    });

    it('as alterações por guardar no sumário não bloqueiam uma correção (não mexe no sumário)', async () => {
        const wrapper = await opened(rectification(), { unsavedChanges: true });

        expect(wrapper.find('[data-testid="outcome-unsaved"]').exists()).toBe(false);
        await wrapper.get('#outcome-cause').setValue('organizational');
        await wrapper.get('#outcome-rectification-note').setValue('Organizacional');

        expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
    });
});
