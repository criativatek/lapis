import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { ACCUMULATED, ACCUMULATED_LONG, CONTINUOUS } from '@/lib/readings';
import type { ScaleBand, Synopsis } from '@/lib/synopsis';
import ClassSynopsisTable from './ClassSynopsisTable.vue';

/**
 * AS DUAS LEITURAS DO ANO, no ecrã que as mostra juntas.
 *
 * A regra de produto que estes testes defendem: a **avaliação contínua** é o
 * indicador FORMAL — é dela que sai a proposta de nível — e o **desempenho
 * acumulado** é uma leitura complementar. Não se chamam o mesmo, não têm o mesmo
 * destaque, e as intercalares não entram na primeira.
 */

// `Link` é o único componente de `@inertiajs/vue3` que esta grelha usa, e é
// substituído por uma âncora no `mount` abaixo.

const bands: ScaleBand[] = [
    { code: '1', label: 'Muito Insuficiente', sequence: 1, is_negative: true },
    { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true },
    { code: '3', label: 'Suficiente', sequence: 3, is_negative: false },
    { code: '4', label: 'Bom', sequence: 4, is_negative: false },
    { code: '5', label: 'Muito Bom', sequence: 5, is_negative: false },
];

function synopsis(): Synopsis {
    return {
        moments: [
            {
                key: 'p1-interim',
                period_id: 1,
                period_ulid: 'p1',
                period_label: '1.º Semestre',
                kind: 'interim',
                label: 'Intercalar 1.º Semestre',
                moment_label: 'Momento intercalar do 1.º Semestre',
                kind_label: 'Semestre',
                is_formal: false,
                source: 'none',
                snapshot: null,
            },
            {
                key: 'p1-final',
                period_id: 1,
                period_ulid: 'p1',
                period_label: '1.º Semestre',
                kind: 'final',
                label: '1.º Semestre',
                moment_label: 'Semestre — 1.º Semestre',
                kind_label: 'Semestre',
                is_formal: true,
                source: 'live',
                snapshot: null,
            },
        ],
        domains: [{ domain_id: 1, name: 'Leitura', sequence: 0, color: '#DCEAFB', weight_percent: '25.0000' }],
        students: [
            {
                enrollment_id: 10,
                enrollment_ulid: 'enr-1',
                class_number: 1,
                name: 'Carolina Nunes',
                moments: [
                    { moment_key: 'p1-interim', available: false, overall: null, domains: [], self_assessment: null, trend: null },
                    {
                        moment_key: 'p1-final',
                        available: true,
                        overall: {
                            normalized_value: '91.302083',
                            scale_value: '5',
                            has_coverage_warning: false,
                            current: {
                                origin: 'proposed',
                                code: '5',
                                label: 'Muito Bom',
                                text: '5',
                                sequence: 5,
                                is_negative: false,
                            },
                        },
                        domains: [],
                        self_assessment: null,
                        trend: null,
                    },
                ],
                continuous: {
                    units: [],
                    counted_units: 2,
                    normalized_value: '89.4010415000',
                    proposal: { value: '4', state: 'resolved', is_percentage: false },
                    level: { code: '4', label: 'Bom', sequence: 4, is_negative: false },
                    decision: null,
                },
                elements: [],
            },
        ],
        continuous: {
            units: [
                { period_id: 1, label: '1.º Semestre', kind_label: 'Semestre', weight_percent: '1' },
                { period_id: 2, label: '2.º Semestre', kind_label: 'Semestre', weight_percent: '1' },
            ],
        },
        elements: [],
        periods: [{ id: 1, ulid: 'p1', label: '1.º Semestre', kind_label: 'Semestre', sequence: 1 }],
    };
}

const wrappers: ReturnType<typeof mount>[] = [];

function render(showQuantitative = true) {
    const wrapper = mount(ClassSynopsisTable, {
        props: {
            classUlid: 'class-1',
            scaleBands: bands,
            canViewStudentProgress: true,
            showQuantitative,
            synopsis: synopsis(),
        },
        global: {
            stubs: {
                Link: defineComponent({
                    inheritAttrs: false,
                    setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()),
                }),
            },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('as duas leituras do ano', () => {
    it('chama a coluna formal pelo nome canónico, e nunca «Acumulado»', () => {
        const header = render().find('thead').text();

        expect(header).toContain(CONTINUOUS);
        // «Acumulado» sozinho deixou de bastar a partir do momento em que as
        // duas leituras aparecem no mesmo produto.
        expect(header).not.toContain('Acumulado');
        expect(header).not.toContain(ACCUMULATED);
        expect(header).not.toContain(ACCUMULATED_LONG);
    });

    it('explica a coluna formal sem depender de quem lê saber a regra', () => {
        const column = render().find('thead th[scope=colgroup]:last-of-type');

        const explanation = column.attributes('title') ?? '';

        expect(explanation).toContain('Média dos resultados formais');
        // A frase diz o que fica de fora, que é a metade da regra que se perde.
        expect(explanation).toContain('intercalares não entram');
        // E nomeia as unidades desta turma, para não ficar genérica.
        expect(explanation).toContain('1.º Semestre e 2.º Semestre');
        // A explicação chega a quem não usa rato.
        expect(column.attributes('aria-label')).toBe(explanation);
    });

    it('dá à coluna formal um destaque que nenhum momento tem', () => {
        const wrapper = render();
        const headers = wrapper.findAll('thead th[scope=colgroup]');
        const formal = headers[headers.length - 1];

        // O azul do produto fica reservado ao indicador formal; os momentos —
        // o caminho — ficam em tons neutros. Se partilhassem a tinta, as duas
        // coisas teriam o mesmo peso e a hierarquia deixaria de existir.
        expect(formal.classes().join(' ')).toContain('bg-primary/15');

        headers.slice(0, -1).forEach((moment) => {
            expect(moment.classes().join(' ')).not.toContain('bg-primary');
        });
    });

    it('a proposta que mostra é a da média contínua, e diz de onde vem', () => {
        const row = render().find('tbody tr');
        const proposal = row.findAll('td').find((cell) => cell.text().trim() === '4');

        expect(proposal).toBeDefined();
        expect(proposal!.find('span').attributes('title')).toContain('Proposta do Lapispro para a avaliação contínua');
    });

    it('um momento intercalar por guardar não inventa números', () => {
        const row = render().find('tbody tr');

        expect(row.text()).toContain('—');
        // E a coluna formal continua a ter a sua média, que não depende dele.
        expect(row.text()).toContain('89,4 %');
    });
});
