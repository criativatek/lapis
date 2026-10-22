import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { AnalysisDocument } from '@/lib/analysisDocument';
import Document from './Document.vue';

const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: (...args: unknown[]) => get(...args) },
}));

/**
 * O DOCUMENTO DA ANÁLISE DA AVALIAÇÃO — a pré-visualização.
 *
 * O QUE ESTES TESTES DEFENDEM: que a página desenha a estrutura que o servidor enviou, bloco a bloco e
 * sem lhe tocar (o PDF lê a mesma); que os avisos e a identificação estão à vista; que as opções
 * (secções e lista nominal) pedem ao servidor o mesmo documento com outras opções, mantendo os momentos;
 * que a lista nominal é opt-in; e que o PDF leva exatamente o que o ecrã está a mostrar.
 */

function documentFixture(over: Partial<AnalysisDocument> = {}): AnalysisDocument {
    return {
        title: 'Análise da Avaliação',
        subtitle: '7.º A · Português · 2026/2027',
        identity: { name: 'Escola de Teste', header_lines: ['Rua A'], footer_note: null, is_configured: true, logo_url: null },
        issued_at: '2026-12-15T10:00:00+00:00',
        issued_label: 'Emitido a 15/12/2026, 10:00',
        identification: [{ label: 'Turma', value: '7.º A' }, { label: 'Momentos comparados', value: '1.º Semestre → 2.º Semestre' }],
        warnings: ['A comparação assenta em apenas 4 aluno(s) com resultado nos dois momentos: leia as variações com cautela.'],
        sections: [
            {
                key: 'comparacao', title: 'Comparação entre momentos',
                blocks: [
                    { type: 'heading', text: 'A turma' },
                    { type: 'paragraph', text: 'Os dois momentos são calculados com os dados de hoje.', tone: 'muted' },
                    { type: 'paragraph', text: 'Estes dois momentos não são totalmente comparáveis:', tone: 'caution' },
                    { type: 'keyvalues', items: [{ label: 'Evolução da turma (mesmos alunos)', value: '62,0% → 70,5% (+8,5 p.p.) · 4 alunos' }] },
                    { type: 'list', title: 'Momentos fora da linha', items: ['Momento A: critérios diferentes'] },
                    {
                        type: 'table',
                        columns: [{ key: 'domain', label: 'Domínio', align: 'left' }, { key: 'mean', label: 'Média', align: 'right' }],
                        rows: [['Escrita', '68,8%'], ['Leitura', '—']],
                    },
                ],
            },
            { key: 'tendencias', title: 'Tendências e resultados persistentes', blocks: [{ type: 'paragraph', text: 'Há 2 momentos comparáveis.', tone: 'plain' }] },
        ],
        options: { sections: ['comparacao', 'tendencias'], students: false },
        notice: 'Este documento descreve notas e momentos. Não é um diagnóstico.',
        is_empty: false,
        ...over,
    };
}

function mountPage(document: AnalysisDocument = documentFixture(), controls: Record<string, unknown> = {}) {
    return mount(Document, {
        props: {
            schoolClass: { ulid: 'c1', label: '7.º A' },
            document,
            controls: {
                moments: { de: 'period:p1', para: 'period:p2' },
                options: { secoes: 'comparacao,tendencias', alunos: '0' },
                sections: [
                    { key: 'comparacao', label: 'Comparação entre momentos' },
                    { key: 'pontos-fortes', label: 'Pontos fortes e dificuldades' },
                    { key: 'tendencias', label: 'Tendências e resultados persistentes' },
                ],
                base_url: '/classes/c1/analise-avaliacao/documento',
                back: { href: '/classes/c1/analise-avaliacao?de=period%3Ap1', label: 'Voltar à análise' },
                ...controls,
            },
        },
        global: { stubs: { ReportLetterhead: true, Checkbox: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)" />' } } },
    });
}

describe('assessment-analysis/Document', () => {
    it('draws the structure it was given: title, identification and notice', () => {
        const wrapper = mountPage();
        const sheet = wrapper.find('[data-test="document"]');

        expect(sheet.text()).toContain('Análise da Avaliação');
        expect(sheet.text()).toContain('7.º A · Português · 2026/2027');
        expect(sheet.text()).toContain('Emitido a 15/12/2026, 10:00');
        expect(wrapper.find('[data-test="identification"]').text()).toContain('Momentos comparados');
        expect(wrapper.find('[data-test="identification"]').text()).toContain('1.º Semestre → 2.º Semestre');
        expect(sheet.text()).toContain('Não é um diagnóstico.');
    });

    it('shows the warnings at the top, where they limit everything below', () => {
        const warnings = mountPage().find('[data-test="warnings"]');

        expect(warnings.text()).toContain('Avisos: cobertura, comparabilidade e dados em falta');
        expect(warnings.text()).toContain('leia as variações com cautela');
    });

    it('has no warnings box when there is nothing to warn about', () => {
        expect(mountPage(documentFixture({ warnings: [] })).find('[data-test="warnings"]').exists()).toBe(false);
    });

    it('draws every kind of block with the text it carries', () => {
        const section = mountPage().find('[data-test="doc-section-comparacao"]');

        expect(section.find('h2').text()).toBe('Comparação entre momentos');
        expect(section.find('h3').text()).toBe('A turma');
        expect(section.text()).toContain('Os dois momentos são calculados com os dados de hoje.');
        expect(section.text()).toContain('Estes dois momentos não são totalmente comparáveis:');
        expect(section.text()).toContain('62,0% → 70,5% (+8,5 p.p.) · 4 alunos');
        expect(section.text()).toContain('Momentos fora da linha');
        expect(section.text()).toContain('Momento A: critérios diferentes');
    });

    it('draws a table with its headings and cells, and a missing value as a dash', () => {
        const table = mountPage().find('[data-test="doc-section-comparacao"] table');

        expect(table.findAll('th').map((th) => th.text())).toEqual(['Domínio', 'Média']);
        expect(table.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))).toEqual([['Escrita', '68,8%'], ['Leitura', '—']]);
        expect(table.find('th:last-child').classes()).toContain('text-right');
    });

    it('draws only the sections it was given', () => {
        const wrapper = mountPage(documentFixture({ sections: [documentFixture().sections[1]] }));

        expect(wrapper.find('[data-test="doc-section-tendencias"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="doc-section-comparacao"]').exists()).toBe(false);
    });

    it('says when no section is selected', () => {
        expect(mountPage(documentFixture({ sections: [], is_empty: true })).text()).toContain('Nenhuma secção selecionada.');
    });

    it('asks the server for another section set, keeping the moments and the names option', async () => {
        get.mockClear();
        const wrapper = mountPage();

        await wrapper.find('[data-test="section-pontos-fortes"]').setValue(true);

        expect(get).toHaveBeenLastCalledWith(
            '/classes/c1/analise-avaliacao/documento',
            { de: 'period:p1', para: 'period:p2', secoes: 'comparacao,pontos-fortes,tendencias', alunos: '0' },
            expect.objectContaining({ preserveScroll: true, replace: true }),
        );
    });

    it('never leaves the document with no section: the last one ticked cannot be unticked', () => {
        const wrapper = mountPage(documentFixture(), { options: { secoes: 'tendencias', alunos: '0' } });

        expect(wrapper.find('[data-test="section-tendencias"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-test="section-comparacao"]').attributes('disabled')).toBeUndefined();
    });

    it('names students only when asked: the nominal list is an option, off by default', async () => {
        get.mockClear();
        const wrapper = mountPage();

        expect((wrapper.find('[data-test="include-students"]').element as HTMLInputElement).checked).toBe(false);

        await wrapper.find('[data-test="include-students"]').setValue(true);

        expect(get).toHaveBeenLastCalledWith(
            '/classes/c1/analise-avaliacao/documento',
            expect.objectContaining({ alunos: '1', secoes: 'comparacao,tendencias', de: 'period:p1' }),
            expect.anything(),
        );
    });

    it('the PDF link carries what the screen is showing: the moments, the sections and the names option', () => {
        const href = mountPage(documentFixture(), { options: { secoes: 'tendencias', alunos: '1' } }).find('[data-test="pdf"]').attributes('href') ?? '';

        expect(href.startsWith('/classes/c1/analise-avaliacao/documento/pdf?')).toBe(true);

        const query = new URLSearchParams(href.split('?')[1]);
        expect(Object.fromEntries(query)).toEqual({ de: 'period:p1', para: 'period:p2', secoes: 'tendencias', alunos: '1' });
    });

    it('prints the preview itself', async () => {
        const print = vi.spyOn(window, 'print').mockImplementation(() => undefined);
        const wrapper = mountPage();

        await wrapper.find('[data-test="print"]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(print).toHaveBeenCalledTimes(1);
        print.mockRestore();
    });

    it('goes back to the analysis with the same moments', () => {
        const back = mountPage().findAll('a').find((link) => link.text().includes('Voltar à análise'));

        expect(back?.attributes('href')).toBe('/classes/c1/analise-avaliacao?de=period%3Ap1');
    });

    it('does not escape into HTML: a text with markup is drawn as letters', () => {
        const hostile = documentFixture({ warnings: ['<b>Zé</b> & «aspas»'] });
        const warnings = mountPage(hostile).find('[data-test="warnings"]');

        expect(warnings.text()).toContain('<b>Zé</b> & «aspas»');
        expect(warnings.find('b').exists()).toBe(false);
    });
});
