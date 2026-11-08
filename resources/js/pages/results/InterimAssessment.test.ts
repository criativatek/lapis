import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import InterimAssessment from './InterimAssessment.vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}));

/**
 * IMPRIMIR UMA AVALIAÇÃO INTERCALAR (0.191.2).
 *
 * O papel não é o ecrã: é um documento próprio, invisível no ecrã, só com o
 * mapa daquela data em siglas. O jsdom não pagina nem mede, por isso o que se
 * fixa aqui é o contrato: o documento existe e está marcado para o papel, o
 * cabeçalho diz o que é e de quando, cada célula lê-se «78,0% · B», a menção
 * «3 · S», a cobertura parcial leva asterisco e nota, a legenda explica as
 * siglas, e a escala medida chega ao PrintableDocument com o aviso no ecrã.
 */

const BAND = (code: string, label: string, sequence: number) => ({
    scale_level_id: sequence,
    code_snapshot: code,
    label_snapshot: label,
    sequence,
    is_negative: sequence <= 2,
});

function props() {
    return {
        schoolClass: { ulid: 'class-1', label: '8.º F', subject: 'Português' },
        decision: { label: 'Nível atribuído', classifies_by_level: true },
        canExportToInovar: false,
        periodUlid: 'period-1',
        interim: {
            ulid: 'interim-1',
            name: 'Intercalar de novembro',
            reference_date: '2026-11-06',
            reference_date_label: '06/11/2026',
            note: null,
            created_at: '2026-11-06T10:00:00+00:00',
            snapshot_version: 1,
            is_intact: true,
        },
        snapshot: {
            version: 1,
            reference_date: '2026-11-06',
            class: { label_snapshot: '8.º F', subject_label_snapshot: 'Português' },
            period: { label_snapshot: '1.º Semestre' },
            scale: {
                name_snapshot: 'Escala 1 a 5',
                bands: [
                    { label_snapshot: 'Muito Insuficiente', sequence: 1, is_negative: true },
                    { label_snapshot: 'Insuficiente', sequence: 2, is_negative: true },
                    { label_snapshot: 'Suficiente', sequence: 3, is_negative: false },
                    { label_snapshot: 'Bom', sequence: 4, is_negative: false },
                    { label_snapshot: 'Muito Bom', sequence: 5, is_negative: false },
                ],
            },
            domains: [
                { domain_id: 1, label_snapshot: 'Oralidade' },
                { domain_id: 2, label_snapshot: 'Educação Literária' },
            ],
            summary: {
                students_total: 2,
                students_with_result: 2,
                students_without_result: 0,
                class_average: '70.000000',
                accumulated_average: '70.000000',
                partial_coverage_count: 1,
                most_common_band: null,
            },
            distribution: [],
            domain_statistics: [],
            students: [
                {
                    enrollment_id: 10,
                    name_snapshot: 'Carolina Nunes',
                    class_number: 1,
                    weighted_average: '78.000000',
                    accumulated_average: '78.000000',
                    coverage_warning: false,
                    band: BAND('4', 'Bom', 4),
                    domains: [
                        { domain_id: 1, label_snapshot: 'Oralidade', weighted_average: '78.000000', mention: BAND('4', 'Bom', 4) },
                        { domain_id: 2, label_snapshot: 'Educação Literária', weighted_average: '62.500000', mention: BAND('3', 'Suficiente', 3) },
                    ],
                },
                {
                    enrollment_id: 11,
                    name_snapshot: 'Diogo Ferreira',
                    class_number: 2,
                    weighted_average: '55.000000',
                    accumulated_average: '55.000000',
                    coverage_warning: true,
                    band: BAND('3', 'Suficiente', 3),
                    domains: [
                        { domain_id: 1, label_snapshot: 'Oralidade', weighted_average: '55.000000', mention: BAND('3', 'Suficiente', 3) },
                        { domain_id: 2, label_snapshot: 'Educação Literária', weighted_average: null, mention: null },
                    ],
                },
            ],
        },
    };
}

describe('results/InterimAssessment — imprimir', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('tem um «Imprimir» na barra de ações e um documento só para o papel, horizontal e compacto', () => {
        const wrapper = mount(InterimAssessment, { props: props() });

        const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Imprimir');
        expect(button).toBeDefined();

        const document = wrapper.find('[data-test="print-document"]');
        expect(document.classes()).toContain('document-print');
        expect(document.classes()).toContain('hidden');
        expect(document.classes()).toContain('print:block');
        expect(document.attributes('data-orientation')).toBe('landscape');
        expect(document.attributes('data-compact')).toBe('true');
        // O mapa do ecrã fica fora do documento: o papel é outra coisa.
        expect(document.find('.overflow-x-auto').exists()).toBe(false);
    });

    it('o cabeçalho diz o que é, de que turma, de que data, e quando foi impresso', () => {
        const wrapper = mount(InterimAssessment, { props: props() });
        const header = wrapper.find('[data-test="print-document"] .interim-print-header').text().replace(/\s+/g, ' ');

        expect(header).toContain('Avaliação intercalar — 8.º F');
        expect(header).toContain('Português · 1.º Semestre');
        expect(header).toContain('Referência: 06/11/2026');
        expect(header).toContain('Impresso em');
    });

    it('uma célula por domínio «78,0% · B», a menção «4 · B», siglas nos cabeçalhos com o nome inteiro no title', () => {
        const wrapper = mount(InterimAssessment, { props: props() });
        const table = wrapper.find('[data-test="print-table"]');

        expect(table.findAll('thead th').map((th) => th.text())).toEqual(['Aluno', 'Oral.', 'Edu. Lit.', 'Menção']);
        expect(table.findAll('thead th')[2].attributes('title')).toBe('Educação Literária');

        const carolina = table.findAll('tbody tr')[0].findAll('td').map((td) => td.text());
        expect(carolina[0]).toContain('Carolina Nunes');
        expect(carolina[1]).toBe('78,0% · B');
        expect(carolina[2]).toBe('62,5% · S');
        expect(carolina[3]).toBe('4 · B');
    });

    it('a cobertura parcial leva asterisco na menção e nota na legenda; sem valor fica um travessão', () => {
        const wrapper = mount(InterimAssessment, { props: props() });
        const table = wrapper.find('[data-test="print-table"]');

        const diogo = table.findAll('tbody tr')[1].findAll('td').map((td) => td.text());
        expect(diogo[2]).toBe('—');
        expect(diogo[3]).toBe('3 · S*');

        const legend = wrapper.find('[data-abbreviations]').text().replace(/\s+/g, ' ');
        expect(legend).toContain('Oral. = Oralidade');
        expect(legend).toContain('Edu. Lit. = Educação Literária');
        expect(legend).toContain('MI = Muito Insuficiente');
        expect(legend).toContain('MB = Muito Bom');
        expect(legend).toContain('* = informação parcial nesta data');
    });

    it('sem cobertura parcial em ninguém, a legenda não explica um asterisco que não existe', () => {
        const data = props();
        data.snapshot.students[1].coverage_warning = false;
        const wrapper = mount(InterimAssessment, { props: data });

        expect(wrapper.find('[data-abbreviations]').text()).not.toContain('informação parcial');
    });

    it('no jsdom nada se mede: a escala publicada é 1 e não há aviso', async () => {
        const wrapper = mount(InterimAssessment, { props: props() });
        await nextTick();
        await nextTick();

        expect(wrapper.find('.document-print').attributes('style') ?? '').toContain('--document-print-zoom: 1');
        expect(wrapper.find('[data-fit-notice]').exists()).toBe(false);
    });

    it('com medidas, publica a escala no PrintableDocument e avisa no ecrã, sem deixar rasto', async () => {
        vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockReturnValue(1200);
        vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
            const isFit = this.classList.contains('document-print-fit');
            const rect = isFit ? { top: 60, bottom: 760, height: 700 } : { top: 0, bottom: 800, height: 800 };

            return { ...rect, left: 0, right: 1000, width: 1000, x: 0, y: rect.top, toJSON: () => ({}) } as DOMRect;
        });

        const wrapper = mount(InterimAssessment, { props: props() });
        await nextTick();
        await nextTick();

        const style = wrapper.find('.document-print').attributes('style') ?? '';
        const zoom = Number(/--document-print-zoom: ([0-9.]+)/.exec(style)?.[1]);
        expect(zoom).toBeLessThan(1);
        expect(zoom).toBeGreaterThanOrEqual(0.8);
        expect(wrapper.find('[data-fit-notice]').text()).toMatch(/Imprime-se a \d+ % para caber numa página\./);
        expect(wrapper.find('.document-print').classes()).not.toContain('interim-measuring');
        // O aviso é do ecrã: está fora do documento impresso.
        expect(wrapper.find('[data-fit-notice]').element.closest('.document-print')).toBeNull();
    });

    it('«Imprimir» mede e chama o browser', async () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
        const wrapper = mount(InterimAssessment, { props: props() });

        await wrapper.findAll('button').find((candidate) => candidate.text() === 'Imprimir')!.trigger('click');
        await nextTick();

        expect(print).toHaveBeenCalledTimes(1);
    });
});
