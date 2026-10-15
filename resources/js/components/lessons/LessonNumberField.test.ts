import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LessonNumberField from '@/components/lessons/LessonNumberField.vue';
import { numberDraftFromLesson } from '@/lib/lessonNumber';
import type { LessonNumberDraft } from '@/lib/lessonNumber';

const HELP =
    'A numeração é sugerida automaticamente. Pode ajustá-la se começou a utilizar o Lapispro a meio do ano ou precisar de acertar a sequência.';

function mountField(
    lesson: { lesson_number: number | null; lesson_number_end: number | null } = { lesson_number: 11, lesson_number_end: null },
    props: Record<string, unknown> = {},
) {
    const original = numberDraftFromLesson(lesson);

    return mount(LessonNumberField, {
        props: {
            modelValue: original,
            original,
            manual: false,
            idPrefix: 'n',
            ...props,
        },
    });
}

const lastEmitted = (wrapper: ReturnType<typeof mountField>) =>
    (wrapper.emitted('update:modelValue') ?? []).at(-1)?.[0] as LessonNumberDraft;

describe('LessonNumberField', () => {
    it('mostra o número atual no campo, com rótulo e o texto de apoio exato', () => {
        const wrapper = mountField();
        const input = wrapper.get('[data-testid="lesson-number-start"]');

        expect((input.element as HTMLInputElement).value).toBe('11');
        expect(input.attributes('inputmode')).toBe('numeric');
        expect(wrapper.get('label[for="n-start"]').text()).toBe('N.º da lição');
        expect(wrapper.get('#n-help').text()).toBe(HELP);
        expect(input.attributes('aria-describedby')).toContain('n-help');
        expect(wrapper.find('[data-testid="lesson-number-end"]').exists()).toBe(false);
    });

    it('a caixa «mais do que uma lição» revela «até à lição»', async () => {
        const wrapper = mountField();

        await wrapper.get('[data-testid="lesson-number-multiple"]').setValue(true);

        expect(lastEmitted(wrapper)).toMatchObject({ multiple: true, start: '11' });

        await wrapper.setProps({ modelValue: { ...lastEmitted(wrapper) } });
        expect(wrapper.get('label[for="n-end"]').text()).toBe('até à lição');
        expect(wrapper.find('[data-testid="lesson-number-end"]').exists()).toBe(true);
    });

    it('desmarcar a caixa esquece o fim', async () => {
        const wrapper = mountField({ lesson_number: 11, lesson_number_end: 12 });
        expect((wrapper.get('[data-testid="lesson-number-end"]').element as HTMLInputElement).value).toBe('12');

        await wrapper.get('[data-testid="lesson-number-multiple"]').setValue(false);

        expect(lastEmitted(wrapper)).toMatchObject({ multiple: false, end: '' });
    });

    it('escrever no início emite o rascunho novo', async () => {
        const wrapper = mountField();

        await wrapper.get('[data-testid="lesson-number-start"]').setValue('15');

        expect(lastEmitted(wrapper)).toMatchObject({ start: '15', automatic: false });
    });

    it('numa aula manual diz-no e «Repor numeração automática» emite o modo automático', async () => {
        const wrapper = mountField(undefined, { manual: true });

        expect(wrapper.get('[data-testid="lesson-number-manual"]').text()).toContain('Número indicado manualmente.');

        await wrapper.get('[data-testid="lesson-number-reset"]').trigger('click');

        expect(lastEmitted(wrapper).automatic).toBe(true);
    });

    it('depois de repor, explica o que acontece ao guardar e deixa manter o número', async () => {
        const original = numberDraftFromLesson({ lesson_number: 11, lesson_number_end: null });
        const wrapper = mountField(undefined, { manual: true, modelValue: { ...original, automatic: true } });

        expect(wrapper.get('[data-testid="lesson-number-automatic"]').text()).toContain('volta a numeração automática');
        expect(wrapper.find('[data-testid="lesson-number-start"]').exists()).toBe(false);

        await wrapper.get('[data-testid="lesson-number-keep"]').trigger('click');

        expect(lastEmitted(wrapper)).toEqual(original);
    });

    it('expõe `dirty` e as chaves do pedido', async () => {
        const wrapper = mountField();
        const exposed = wrapper.vm as unknown as { dirty: boolean; payload: (c?: string | null) => Record<string, unknown> };

        expect(exposed.dirty).toBe(false);

        await wrapper.setProps({ modelValue: { ...numberDraftFromLesson({ lesson_number: 11, lesson_number_end: null }), start: '20' } });

        expect(exposed.dirty).toBe(true);
        expect(exposed.payload('conf')).toEqual({ lesson_number: 20, lesson_number_end: null, lesson_number_confirmation: 'conf' });
    });

    it('sem número (e não ausente): campo vazio e a explicação', () => {
        const wrapper = mountField({ lesson_number: null, lesson_number_end: null });

        expect((wrapper.get('[data-testid="lesson-number-start"]').element as HTMLInputElement).value).toBe('');
        expect(wrapper.get('[data-testid="lesson-number-none"]').text()).toBe(
            'Esta aula ainda não tem número: não há um número livre entre as aulas vizinhas. Pode indicá-lo.',
        );
    });

    it('professor ausente: sem campos, só a frase', () => {
        const wrapper = mountField(undefined, { canSet: false });

        expect(wrapper.get('[data-testid="lesson-number-absent"]').text()).toBe('Professor ausente: esta aula não tem número de lição.');
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('os erros aparecem com role="alert" e ligados ao campo', () => {
        const wrapper = mountField(undefined, { errors: { number: 'A Lição 12 já está atribuída.', end: 'Fim inválido.' } });
        const input = wrapper.get('[data-testid="lesson-number-start"]');

        expect(wrapper.get('[data-testid="lesson-number-error"]').attributes('role')).toBe('alert');
        expect(wrapper.get('[data-testid="lesson-number-error"]').text()).toContain('A Lição 12 já está atribuída.');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toContain('n-error');
    });
});
