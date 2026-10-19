import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LessonNumberField from '@/components/lessons/LessonNumberField.vue';
import { numberDraftFromLesson } from '@/lib/lessonNumber';
import type { LessonNumberDraft } from '@/lib/lessonNumber';

const HELP_SUGGESTED = 'Sugerido automaticamente. Pode alterar se necessário.';
const HELP_MANUAL = 'Pode alterar se necessário.';

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
        expect(wrapper.get('#n-help').text()).toBe(HELP_SUGGESTED);
        expect(input.attributes('aria-describedby')).toContain('n-help');
    });

    it('numa aula manual, ou sem número, o apoio é só «Pode alterar se necessário.»', () => {
        expect(mountField(undefined, { manual: true }).get('#n-help').text()).toBe(HELP_MANUAL);
        expect(mountField({ lesson_number: null, lesson_number_end: null }).get('#n-help').text()).toBe(HELP_MANUAL);
    });

    it('CASO E: não há caixa nem «até à lição» — uma aula, um número', () => {
        for (const lesson of [
            { lesson_number: 11, lesson_number_end: null },
            { lesson_number: 11, lesson_number_end: 12 },
        ]) {
            const wrapper = mountField(lesson);

            expect(wrapper.find('[data-testid="lesson-number-multiple"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="lesson-number-end"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="lesson-number-end-error"]').exists()).toBe(false);
            expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
            expect(wrapper.findAll('input')).toHaveLength(1);
            expect(wrapper.html()).not.toContain('mais do que uma lição');
            expect(wrapper.html()).not.toContain('até à lição');
        }
    });

    it('uma linha legada mostra o aviso, ligado ao campo, e editar o número apaga o fim', async () => {
        const wrapper = mountField({ lesson_number: 12, lesson_number_end: 13 });

        expect(wrapper.get('[data-testid="lesson-number-legacy-range"]').text()).toBe(
            'Esta aula ficou registada como Lições 12–13, uma opção que deixou de existir. Ao editar o número, a aula passa a corresponder a uma só lição.',
        );
        expect(wrapper.get('[data-testid="lesson-number-start"]').attributes('aria-describedby')).toContain('n-legacy');

        await wrapper.get('[data-testid="lesson-number-start"]').setValue('12');

        expect(lastEmitted(wrapper)).toMatchObject({ start: '12', legacyEnd: null });
    });

    it('sem legado não há aviso; depois de editar (fim apagado) o aviso desaparece', async () => {
        expect(mountField().find('[data-testid="lesson-number-legacy-range"]').exists()).toBe(false);

        const wrapper = mountField({ lesson_number: 12, lesson_number_end: 13 });
        await wrapper.setProps({ modelValue: { ...numberDraftFromLesson({ lesson_number: 12, lesson_number_end: 13 }), legacyEnd: null } });

        expect(wrapper.find('[data-testid="lesson-number-legacy-range"]').exists()).toBe(false);
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
        expect(exposed.payload('conf')).toEqual({ lesson_number: 20, lesson_number_confirmation: 'conf' });
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
        const wrapper = mountField(undefined, { errors: { number: 'A Lição 12 já está atribuída.' } });
        const input = wrapper.get('[data-testid="lesson-number-start"]');

        expect(wrapper.get('[data-testid="lesson-number-error"]').attributes('role')).toBe('alert');
        expect(wrapper.get('[data-testid="lesson-number-error"]').text()).toContain('A Lição 12 já está atribuída.');
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toContain('n-error');
    });

    it('com a numeração congelada mostra o número só de leitura, com a frase da contenção', () => {
        const wrapper = mountField({ lesson_number: 11, lesson_number_end: 12 }, { frozen: true, manual: true });

        expect(wrapper.get('[data-testid="lesson-number-frozen-value"]').text()).toBe('11–12');
        expect(wrapper.get('[data-testid="lesson-number-frozen"]').text()).toContain(
            'A numeração das lições está temporariamente bloqueada. Pode guardar o sumário sem alterar o número.',
        );
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('congelada, uma aula sem número diz-o em vez de mostrar um campo vazio', () => {
        const wrapper = mountField({ lesson_number: null, lesson_number_end: null }, { frozen: true });

        expect(wrapper.get('[data-testid="lesson-number-frozen-value"]').text()).toBe('sem número');
        expect(wrapper.find('input').exists()).toBe(false);
    });

    it('uma aula de professor ausente continua a mostrar só a sua frase, mesmo congelada', () => {
        const wrapper = mountField(undefined, { canSet: false, frozen: true });

        expect(wrapper.find('[data-testid="lesson-number-absent"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="lesson-number-frozen"]').exists()).toBe(false);
    });
});
