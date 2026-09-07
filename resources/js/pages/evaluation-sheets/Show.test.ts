import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import type { EvaluationSheet, EvaluationSheetDecisionScale, EvaluationSheetReadiness } from '@/types';
import Show from './Show.vue';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({ setup: (_, { slots }) => () => h('div', slots.default?.()) }),
    Link: defineComponent({ inheritAttrs: false, setup: (_, { attrs, slots }) => () => h('a', attrs, slots.default?.()) }),
    router: { get: vi.fn(), post: (...args: unknown[]) => routerPost(...args) },
    useForm: (fields: Record<string, unknown>) => reactive({ ...fields, errors: {}, processing: false, post: vi.fn() }),
    usePage: () => ({ props: { errors: {} } }),
}));

/**
 * Pautas de Avaliação (Fatia 2) — UMA ÚNICA VISTA, nunca três.
 *
 * Estes testes cobrem a regra central: os toggles de apresentação só
 * escondem colunas, nunca tocam nas props recebidas — e a coluna de nível
 * atribuído mostra "—" quando não há classificação, sem inventar um valor.
 */
function baseSheet(): EvaluationSheet {
    return {
        class_id: 1,
        academic_period_id: 1,
        scope: 'period',
        domains: [
            { domain_id: 1, name: 'Oralidade', sequence: 0, weight_percent: '25.0000', color: '#DCEAFB' },
            { domain_id: 2, name: 'Leitura', sequence: 1, weight_percent: '25.0000', color: '#E1F0E1' },
        ],
        students: [
            {
                enrollment_id: 10,
                class_number: 1,
                name: 'Carolina Nunes',
                overall: {
                    normalized_value: '91.000000',
                    scale_value: '5.000',
                    scale_level_id: 5,
                    scale_level_code: '5',
                    scale_level_label: 'Muito Bom',
                    result_state: 'ok',
                    has_coverage_warning: false,
                },
                domains: [
                    {
                        domain_id: 1,
                        name: 'Oralidade',
                        sequence: 0,
                        normalized_value: '90.000000',
                        weight_percent_applied: '25.0000',
                        scale_level_id: 5,
                        scale_level_code: '5',
                        scale_level_label: 'Muito Bom',
                        has_coverage_warning: false,
                        coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
                    },
                    {
                        domain_id: 2,
                        name: 'Leitura',
                        sequence: 1,
                        normalized_value: '93.125000',
                        weight_percent_applied: '25.0000',
                        scale_level_id: 5,
                        scale_level_code: '5',
                        scale_level_label: 'Muito Bom',
                        has_coverage_warning: false,
                        coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
                    },
                ],
                classification: {
                    status: 'confirmed',
                    proposed_value: '91.000',
                    proposed_scale_level_id: 5,
                    proposed_scale_level_code: '5',
                    proposed_scale_level_label: 'Muito Bom',
                    final_value: '4.000',
                    final_scale_level_id: 4,
                    final_scale_level_code: '4',
                    final_scale_level_label: 'Bom',
                    override_reason: null,
                },
                coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
            },
            {
                enrollment_id: 11,
                class_number: 2,
                name: 'Diogo Ferreira',
                overall: {
                    normalized_value: null,
                    scale_value: null,
                    scale_level_id: null,
                    scale_level_code: null,
                    scale_level_label: null,
                    result_state: 'no_value',
                    has_coverage_warning: true,
                },
                domains: [],
                classification: null,
                coverage: { absences: [], no_elements: true, excluded_domain_ids: [] },
            },
        ],
    };
}

/** A escala em que a decisão é tomada — «Escala 1 a 5», como o perfil-sistema. */
function baseDecision(): EvaluationSheetDecisionScale {
    return {
        label: 'Nível atribuído',
        classifies_by_level: true,
        levels: [
            { id: 1, code: '1', label: 'Muito Insuficiente' },
            { id: 2, code: '2', label: 'Insuficiente' },
            { id: 3, code: '3', label: 'Suficiente' },
            { id: 4, code: '4', label: 'Bom' },
            { id: 5, code: '5', label: 'Muito Bom' },
        ],
        min_value: '1',
        max_value: '5',
    };
}

function baseProps() {
    return {
        schoolClass: { ulid: 'class-1', label: '7.º A', subject: 'Português', academic_year: '2026/2027', has_profile: true },
        // Os dois momentos estruturais de uma unidade temporal, na ordem em que
        // se vivem — o intercalar imediatamente ANTES do respetivo final.
        periods: [
            {
                ulid: 'period-1',
                moment: 'interim' as const,
                label: 'Intercalar 1.º Semestre',
                kind_label: 'Semestre',
                moment_label: 'Momento intercalar do 1.º Semestre',
                selected: false,
            },
            {
                ulid: 'period-1',
                moment: 'final' as const,
                label: '1.º Semestre',
                kind_label: 'Semestre',
                moment_label: 'Semestre — 1.º Semestre',
                selected: true,
            },
        ],
        moment: 'final' as const,
        sheet: baseSheet(),
        decision: baseDecision(),
        // A escala por ordem: é dela que sai a COR de cada apreciação, pela
        // posição do nível e nunca pelo número que ele tem (§24).
        scaleBands: [
            { code: '1', label: 'Muito Insuficiente', sequence: 1, is_negative: true },
            { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true },
            { code: '3', label: 'Suficiente', sequence: 3, is_negative: false },
            { code: '4', label: 'Bom', sequence: 4, is_negative: false },
            { code: '5', label: 'Muito Bom', sequence: 5, is_negative: false },
        ],
    };
}

/** A mesma pauta, com o que os alunos disseram de si próprios. */
function selfAssessedProps() {
    const props = decidableProps();
    const sheet = props.sheet;

    sheet.students[0].self_assessment = { code: '3', label: 'Suficiente', sequence: 3, is_negative: false };
    sheet.students[0].domains[0].self_assessment = { code: '2', label: 'Insuficiente', sequence: 2, is_negative: true };
    // Diogo não respondeu — ausência, nunca zero.

    return props;
}

/**
 * A mesma pauta, agora endereçável: o servidor disse onde cada decisão se
 * escreve e se ainda pode ser escrita. É isso — e só isso — que liga as ações.
 */
function decidableProps() {
    const props = baseProps();
    const sheet = props.sheet;

    sheet.students[0].enrollment_ulid = 'enrollment-carolina';
    sheet.students[0].can_decide = true;
    sheet.students[0].can_use_proposal = false;
    sheet.students[1].enrollment_ulid = 'enrollment-diogo';
    sheet.students[1].can_decide = true;
    sheet.students[1].can_use_proposal = false;

    return props;
}

describe('evaluation-sheets/Show — a única vista', () => {
    it('renders every domain name from the payload', () => {
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.text()).toContain('Oralidade');
        expect(wrapper.text()).toContain('Leitura');
    });

    it('shows "—" for the assigned level when the student has no classification', () => {
        const wrapper = mount(Show, { props: baseProps() });
        const rows = wrapper.findAll('tbody tr');
        const diogoRow = rows.find((row) => row.text().includes('Diogo Ferreira'));

        expect(diogoRow).toBeDefined();
        expect(diogoRow!.text()).toContain('—');
    });

    it('shows the decided level in bold and never the proposal once a decision exists', () => {
        const wrapper = mount(Show, { props: baseProps() });

        // Carolina's final decision (code "4") is what shows, not the proposal
        // (code "5") — a decision is never overwritten by the proposal.
        const rows = wrapper.findAll('tbody tr');
        const carolinaRow = rows.find((row) => row.text().includes('Carolina Nunes'));

        expect(carolinaRow).toBeDefined();
        expect(carolinaRow!.find('.font-bold').text()).toBe('4');
    });

    it('names the level by its code and never by the qualitative mention', () => {
        const wrapper = mount(Show, { props: baseProps() });

        const carolinaRow = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'));

        // COM OS QUANTITATIVOS À VISTA, numa pauta o nível é o número. A menção
        // não aparece em célula nenhuma da linha — nem no global, nem nos
        // domínios, nem na decisão. (Desligando-os, a regra inverte-se: ver
        // «quantitativos desligados» mais abaixo.)
        expect(carolinaRow!.text()).not.toContain('Muito Bom');

        // Mas não se perde: a frase inteira acompanha a célula, e diz também DE
        // QUEM é o juízo — negrito e itálico não são informação para quem não
        // os vê (§8, §14).
        const assigned = carolinaRow!.find('.font-bold');

        expect(assigned.attributes('title')).toBe('Decisão do professor: 4 — Bom.');
        expect(assigned.attributes('aria-label')).toBe('Decisão do professor: 4 — Bom.');
    });

    it('still reads a snapshot kept before the code existed, by falling back to its mention', () => {
        // Uma pauta guardada antes desta correção traz `scale_level_label` e
        // código nenhum. O histórico tem de continuar a dizer o que estava no
        // ecrã no dia em que foi guardado — não um travessão vazio.
        const props = baseProps();
        const student = props.sheet!.students[0];

        delete student.overall.scale_level_code;
        delete student.classification!.final_scale_level_code;
        delete student.classification!.proposed_scale_level_code;
        student.domains.forEach((domain) => {
            delete domain.scale_level_code;
        });

        const wrapper = mount(Show, { props });
        const carolinaRow = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'));

        expect(carolinaRow!.find('.font-bold').text()).toBe('Bom');
        expect(carolinaRow!.text()).toContain('Muito Bom');
    });

    it('hiding the quantitative toggle changes only what is rendered, never the props the component received', async () => {
        const props = baseProps();
        const wrapper = mount(Show, { props });

        const before = JSON.stringify(wrapper.props('sheet'));

        const quantitativeCheckbox = wrapper.findAll('input[type="checkbox"]')[0];
        await quantitativeCheckbox.setValue(false);

        const after = JSON.stringify(wrapper.props('sheet'));

        expect(after).toBe(before);
        // The percentage figure that only shows in quantitative mode is gone.
        expect(wrapper.text()).not.toContain('91,0%');
    });

    it('hiding domain detail collapses to the global column without touching the payload', async () => {
        const wrapper = mount(Show, { props: baseProps() });
        const before = JSON.stringify(wrapper.props('sheet'));

        const domainDetailCheckbox = wrapper.findAll('input[type="checkbox"]')[1];
        await domainDetailCheckbox.setValue(false);

        expect(JSON.stringify(wrapper.props('sheet'))).toBe(before);
        expect(wrapper.find('th').text()).not.toBe('Oralidade');
    });
});

/**
 * «Preparar fecho» — o botão abre uma LEITURA já recebida do servidor.
 *
 * Abrir e fechar o painel não pede nada ao servidor e não toca na pauta;
 * sem payload de preparação (sem pauta, sem alunos) o botão nem aparece.
 */
describe('evaluation-sheets/Show — preparar fecho', () => {
    function readiness(): EvaluationSheetReadiness {
        return {
            moment: { period_label: '1.º Semestre', kind_label: 'Semestre', is_closing: true },
            summary: { students_total: 2, students_with_notes: 1, students_ready: 1, attention_count: 2 },
            items: [
                { key: 'decisions', state: 'attention', label: '1 de 2 níveis atribuídos', detail: null, action: 'classifications' },
            ],
            students: [
                {
                    enrollment_ulid: 'enr-diogo',
                    class_number: 2,
                    name: 'Diogo Ferreira',
                    pending: [{ state: 'attention', label: 'Nível ainda não atribuído', action: 'classifications' }],
                },
            ],
        };
    }

    it('shows the button with the attention count, and only opens the panel on demand', async () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: readiness() } });

        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Preparar fecho'));
        expect(button).toBeDefined();
        expect(button!.text()).toContain('2');
        expect(wrapper.text()).not.toContain('Preparação — Semestre: 1.º Semestre');

        await button!.trigger('click');

        expect(wrapper.text()).toContain('Preparação — Semestre: 1.º Semestre');
        expect(wrapper.text()).toContain('Nível ainda não atribuído');
    });

    it('conta pontos por palavras, e não um número solto', () => {
        // «Preparar fecho [2]» PODIA SER QUALQUER COISA: um passo de um
        // assistente, um contador de pautas, um aviso. A palavra diz o que se
        // conta, e o texto acessível diz o que fazer com ele.
        const button = (count: number) => {
            const reading = readiness();
            reading.summary.attention_count = count;
            const wrapper = mount(Show, { props: { ...baseProps(), readiness: reading } });

            return wrapper.findAll('button').find((candidate) => candidate.text().includes('Preparar fecho'))!;
        };

        const many = button(2);
        expect(many.text()).toContain('2 pontos');
        expect(many.attributes('aria-label')).toBe('Preparar fecho — 2 pontos a verificar antes do fecho');

        // O singular é singular.
        const single = button(1);
        expect(single.text()).toContain('1 ponto');
        expect(single.text()).not.toContain('1 pontos');
        expect(single.attributes('aria-label')).toBe('Preparar fecho — 1 ponto a verificar antes do fecho');

        // E SEM PENDÊNCIAS NÃO SE ANUNCIA UM ZERO: anuncia-se o que o botão faz.
        const clean = button(0);
        expect(clean.text()).not.toContain('0');
        expect(clean.attributes('aria-label')).toBe('Preparar fecho — abrir a leitura de preparação desta pauta');
    });

    it('offers no button at all when there is nothing to prepare', () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: null } });

        expect(wrapper.findAll('button').some((candidate) => candidate.text().includes('Preparar fecho'))).toBe(false);
    });

    it('keeps the panel off the printed sheet', async () => {
        const wrapper = mount(Show, { props: { ...baseProps(), readiness: readiness() } });

        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Preparar fecho'));
        await button!.trigger('click');

        const panel = wrapper.find('section[aria-label="Preparação do fecho do momento"]');
        expect(panel.exists()).toBe(true);
        expect(panel.classes()).toContain('print-hide');
    });
});

/**
 * Imprimir e exportar — a mesma pauta, duas regras OPOSTAS e deliberadas.
 *
 * O papel é WYSIWYG: sai o que está no ecrã, incluindo o que o professor
 * escolheu esconder. O CSV é um ficheiro de dados: leva sempre tudo, e por isso
 * o seu link nunca muda com os toggles — nem sequer chega a falar deles.
 */
describe('evaluation-sheets/Show — imprimir e exportar', () => {
    it('offers a CSV download whose address never changes with the toggles', async () => {
        const wrapper = mount(Show, { props: baseProps() });

        const link = wrapper.findAll('a').find((anchor) => anchor.text().includes('Exportar CSV'));
        expect(link).toBeDefined();

        const before = link!.attributes('href');
        expect(before).toBe('/classes/class-1/pauta-avaliacao/csv/period-1');

        // Desligar tudo o que se pode desligar não retira uma única coluna ao
        // ficheiro, porque o pedido é o mesmo pedido.
        for (const checkbox of wrapper.findAll('input[type="checkbox"]')) {
            await checkbox.setValue(false);
        }

        const after = wrapper.findAll('a').find((anchor) => anchor.text().includes('Exportar CSV'));
        expect(after!.attributes('href')).toBe(before);
    });

    it('prints from this very screen, and keeps the controls off the paper', () => {
        const wrapper = mount(Show, { props: baseProps() });

        const printButton = wrapper.findAll('button').find((button) => button.text().includes('Imprimir'));
        expect(printButton).toBeDefined();

        // Tudo o que é interativo está marcado para não sair impresso.
        const controls = wrapper.findAll('.print-hide');
        expect(controls.length).toBeGreaterThanOrEqual(3);

        // O botão de imprimir é ele próprio um controlo, e por isso está dentro
        // de um bloco que não vai ao papel.
        expect(printButton!.element.closest('.print-hide')).not.toBeNull();

        // E a grelha NÃO está: é precisamente o que se imprime.
        expect(wrapper.find('table').element.closest('.print-hide')).toBeNull();
        expect(wrapper.find('table').element.closest('.pauta-print')).not.toBeNull();
    });

    it('the printed header names the class, the subject and the period in its own terminology', () => {
        const wrapper = mount(Show, { props: baseProps() });

        const header = wrapper.find('.pauta-print .print\\:block');
        expect(header.exists()).toBe(true);
        expect(header.text()).toContain('7.º A');
        expect(header.text()).toContain('Português');
        // «Semestre», vindo do período — nunca uma palavra fixa no código.
        expect(header.text()).toContain('Semestre');
        expect(header.text()).toContain('1.º Semestre');
        expect(header.text()).toContain('Impresso em');
    });
});

/**
 * A DECISÃO, A PARTIR DA PRÓPRIA PAUTA.
 *
 * A pauta é onde a informação toda já está: é onde o professor atribui. O que
 * estes testes fixam é a separação que não pode cair — a PROPOSTA continua a ser
 * proposta, a DECISÃO é do professor, e o ecrã nunca escreve nada por sua conta:
 * envia para o caminho canónico e espera.
 */
describe('evaluation-sheets/Show — atribuir e alterar', () => {
    // O painel é um portal: vive no `body`, fora da árvore do wrapper. Sem
    // limpar, o diálogo de um teste continuaria a responder às perguntas do
    // seguinte — e um `select` do teste anterior daria a resposta errada.
    beforeEach(() => {
        routerPost.mockReset();
        document.body.innerHTML = '';
    });

    it('offers «Atribuir» where nothing was decided and «Alterar» where something was', () => {
        const wrapper = mount(Show, { props: decidableProps() });
        const rows = wrapper.findAll('tbody tr');

        const diogo = rows.find((row) => row.text().includes('Diogo Ferreira'))!;
        const atribuir = diogo.findAll('button').find((button) => button.text() === 'Atribuir');
        expect(atribuir).toBeDefined();
        expect(atribuir!.attributes('aria-label')).toBe('Atribuir classificação a Diogo Ferreira');

        // Carolina já tem 4 decidido: o próprio valor é a ação de alterar, e o
        // nome da ação diz de quem é e o que lá está.
        const carolina = rows.find((row) => row.text().includes('Carolina Nunes'))!;
        const alterar = carolina.findAll('button').find((button) => button.attributes('aria-label')?.startsWith('Alterar'));
        expect(alterar).toBeDefined();
        expect(alterar!.attributes('aria-label')).toBe('Alterar a classificação de Carolina Nunes — atualmente 4');
        expect(alterar!.text()).toBe('4');
    });

    it('offers no action at all on a sheet the server did not address', () => {
        // Sem `enrollment_ulid`/`can_decide` não há para onde escrever — que é
        // exatamente o caso de uma pauta guardada.
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.findAll('button').filter((button) => button.text() === 'Atribuir')).toHaveLength(0);
        expect(
            wrapper.findAll('button').filter((button) => button.attributes('aria-label')?.startsWith('Alterar')),
        ).toHaveLength(0);
    });

    it('writes the decision through the canonical classifications endpoint, and nowhere else', async () => {
        const wrapper = mount(Show, { props: decidableProps() });
        const rows = wrapper.findAll('tbody tr');
        const diogo = rows.find((row) => row.text().includes('Diogo Ferreira'))!;

        await diogo.findAll('button').find((button) => button.text() === 'Atribuir')!.trigger('click');

        // O painel abriu com a proposta à vista e nada preenchido: uma proposta
        // pré-selecionada seria o sistema a decidir por omissão.
        const select = document.querySelector('select') as HTMLSelectElement | null;
        expect(select).not.toBeNull();

        select!.value = '3';
        select!.dispatchEvent(new Event('change'));
        await wrapper.vm.$nextTick();

        const save = [...document.querySelectorAll('button')].find((button) => button.textContent?.includes('Guardar decisão'));
        expect(save).toBeDefined();
        save!.click();
        await wrapper.vm.$nextTick();

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe('/classes/class-1/classifications/period-1/enrollment-diogo/decide');
        expect(routerPost.mock.calls[0][1]).toEqual({ final_scale_level_id: 3, final_value: null });
    });

    it('opens the panel on the decision that stands, never on the proposal', async () => {
        const wrapper = mount(Show, { props: decidableProps() });
        const rows = wrapper.findAll('tbody tr');
        const carolina = rows.find((row) => row.text().includes('Carolina Nunes'))!;

        await carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.startsWith('Alterar'))!
            .trigger('click');

        // Carolina foi decidida em 4 e a proposta era 5. O seletor abre em 4.
        const select = document.querySelector('select') as HTMLSelectElement | null;
        expect(select).not.toBeNull();
        expect(select!.value).toBe('4');

        const panel = document.querySelector('[role="dialog"]');
        expect(panel).not.toBeNull();
        expect(panel!.textContent).toContain('Proposta do Lapispro');
        // A proposta continua a dizer-se pelo código, com a menção à ilharga.
        expect(panel!.textContent).toContain('5 — Muito Bom');
    });
});

/**
 * A AUTOAVALIAÇÃO NA PAUTA.
 *
 * Aparece porque é com ela ao lado que a decisão se toma; esconde-se porque a
 * pauta continua a ser do professor; e não existe de todo onde ninguém se
 * pronunciou, porque uma coluna vazia não informa ninguém.
 */
describe('evaluation-sheets/Show — autoavaliação', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('shows the self-assessment by default when there is one', () => {
        const wrapper = mount(Show, { props: selfAssessedProps() });

        const headers = wrapper.findAll('thead th').map((header) => header.text());
        expect(headers.some((header) => header.replace(/\s/g, '').includes('Autoavaliação'))).toBe(true);

        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;
        // A síntese é curta: o código do juízo global, com a menção no título.
        const cell = carolina
            .findAll('span')
            .find((span) => span.attributes('title') === 'Autoavaliação do aluno: 3 — Suficiente');
        expect(cell).toBeDefined();
        expect(cell!.text()).toBe('3');

        // E o que ela disse sobre um domínio, em expoente, dentro do domínio.
        const perDomain = carolina
            .findAll('sup')
            .find((sup) => sup.attributes('title')?.startsWith('Autoavaliação do aluno — Oralidade'));
        expect(perDomain).toBeDefined();
        expect(perDomain!.text()).toBe('A2');
    });

    it('has neither column nor toggle where nobody self-assessed', () => {
        const wrapper = mount(Show, { props: decidableProps() });

        const headers = wrapper.findAll('thead th').map((header) => header.text().replace(/\s/g, ''));
        expect(headers.some((header) => header.includes('Autoavaliação'))).toBe(false);

        const labels = wrapper.findAll('label').map((label) => label.text());
        expect(labels.some((label) => label.includes('Autoavaliação'))).toBe(false);
    });

    it('hides the column on request, without touching a single value', async () => {
        const props = selfAssessedProps();
        const before = JSON.stringify(props.sheet);
        const wrapper = mount(Show, { props });

        const toggle = wrapper
            .findAll('label')
            .find((label) => label.text().includes('Autoavaliação'))!
            .find('input');

        await toggle.setValue(false);

        const headers = wrapper.findAll('thead th').map((header) => header.text().replace(/\s/g, ''));
        expect(headers.some((header) => header.includes('Autoavaliação'))).toBe(false);

        // ESCONDER É ESCONDER. O payload recebido do servidor fica intacto — o
        // toggle não recalcula nada e nem sequer chega ao servidor.
        expect(JSON.stringify(props.sheet)).toBe(before);
    });

    it('puts what the student said inside the decision panel, labelled as theirs', async () => {
        const wrapper = mount(Show, { props: selfAssessedProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        await carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.startsWith('Alterar'))!
            .trigger('click');

        const panel = document.querySelector('[role="dialog"]')!;
        expect(panel.textContent).toContain('Autoavaliação');
        // E dito como apoio, nunca como uma segunda nota.
        expect(panel.textContent).toContain('nunca determina a classificação');
    });
});

/**
 * A pauta com as duas moradas que uma decisão por domínio precisa: a do aluno e
 * a do domínio. Sem as duas não há ação a oferecer — e é isso que mantém uma
 * fotografia sem botões.
 */
function domainDecidableProps() {
    const props = decidableProps();

    props.sheet!.domains[0].domain_ulid = 'domain-oralidade';
    props.sheet!.domains[1].domain_ulid = 'domain-leitura';

    return props;
}

/**
 * O painel de um domínio, encontrado pelo que ele diz.
 *
 * Os diálogos são teleportados para o `body` e o jsdom é partilhado por todos
 * os testes deste ficheiro: `querySelector('[role="dialog"]')` devolveria o
 * primeiro que alguma vez foi aberto, não o que acabou de abrir.
 */
function openDomainPanel(): Element {
    const panels = Array.from(document.querySelectorAll('[role="dialog"]'));
    const panel = panels.reverse().find((candidate) => candidate.textContent?.includes('Quantitativo calculado'));

    if (panel === undefined) {
        throw new Error('O painel da apreciação por domínio não está aberto.');
    }

    return panel;
}

describe('evaluation-sheets/Show — a apreciação de cada domínio', () => {
    beforeEach(() => {
        routerPost.mockClear();
    });

    it('a apreciação de um domínio é o botão, e diz que é uma proposta', () => {
        const wrapper = mount(Show, { props: domainDecidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        const cell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        expect(cell.text()).toBe('5');
        expect(cell.attributes('title')).toContain('Proposta do Lapispro');
        // O estilo não é informação para quem não o vê: a frase acompanha.
        expect(cell.attributes('aria-label')).toContain('Proposta do Lapispro');
    });

    it('uma proposta tacitamente aceite não se veste de pendência', () => {
        // ACEITAÇÃO TÁCITA (§2). Uma proposta por domínio que o professor não
        // alterou É a apreciação vigente. O itálico dizia-lhe «isto está por
        // decidir» — e multiplicava por trinta alunos × cinco domínios um
        // trabalho que não existe.
        const wrapper = mount(Show, { props: domainDecidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;
        const cell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        expect(cell.classes()).not.toContain('italic');
        expect(cell.attributes('title')).toContain('vigente enquanto o professor não a alterar');
        expect(cell.attributes('title')).not.toContain('ainda não');

        // E não há marca de alteração onde ninguém alterou nada.
        expect(carolina.html()).not.toContain('>prof.</sup>');
    });

    it('o nível atribuído CONTINUA a mostrar a pendência que tem mesmo', () => {
        // A distinção é de natureza e não de grau: um domínio é uma leitura
        // qualitativa que acompanha o número; o nível atribuído é a decisão
        // formal do professor (§3.3), e enquanto ela não existe há mesmo algo
        // por fazer. O itálico e o botão «Atribuir» continuam lá.
        //
        // A Carolina do cenário já tem a classificação decidida; o que interessa
        // aqui é o estado ANTERIOR a essa decisão — proposta feita, decisão por
        // tomar.
        const props = domainDecidableProps();
        props.sheet!.students[0].classification = {
            status: 'proposed',
            proposed_value: '4',
            proposed_scale_level_id: 4,
            proposed_scale_level_code: '4',
            proposed_scale_level_label: 'Bom',
            final_value: null,
            final_scale_level_id: null,
            final_scale_level_code: null,
            final_scale_level_label: null,
            override_reason: null,
        };

        const wrapper = mount(Show, { props });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        expect(carolina.html()).toContain('italic');
        expect(carolina.text()).toContain('Atribuir');
    });

    it('escreve a decisão pelo endereço do aluno e do domínio, e nunca pelo da classificação', async () => {
        const wrapper = mount(Show, { props: domainDecidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        await carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!
            .trigger('click');

        // O painel deste domínio, e não um qualquer que tenha ficado no
        // documento de um teste anterior: os diálogos são teleportados para o
        // `body` e o jsdom é partilhado por todo o ficheiro.
        const panel = openDomainPanel();

        expect(panel.textContent).toContain('Oralidade — Carolina Nunes');
        // O painel mostra o quantitativo e a proposta como CONTEXTO, e nenhum
        // dos dois é um campo.
        expect(panel.textContent).toContain('Quantitativo calculado');
        expect(panel.textContent).toContain('Proposta do Lapispro');

        const suficiente = Array.from(panel.querySelectorAll('button')).find((button) =>
            button.textContent?.includes('Suficiente'),
        )! as HTMLButtonElement;
        suficiente.click();
        await wrapper.vm.$nextTick();

        expect(routerPost).toHaveBeenCalledTimes(1);
        expect(routerPost.mock.calls[0][0]).toBe(
            '/classes/class-1/pauta-avaliacao/period-1/dominios/enrollment-carolina/domain-oralidade',
        );
        expect(routerPost.mock.calls[0][1]).toEqual({ scale_level_id: 3 });
    });

    it('a decisão aparece a negrito e a proposta continua a ser dita', () => {
        const props = domainDecidableProps();
        props.sheet!.students[0].domains[0].decided_scale_level_id = 3;
        props.sheet!.students[0].domains[0].decided_scale_level_code = '3';
        props.sheet!.students[0].domains[0].decided_scale_level_label = 'Suficiente';

        const wrapper = mount(Show, { props });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;
        const cell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        expect(cell.text()).toBe('3');
        expect(cell.classes()).toContain('font-semibold');
        expect(cell.attributes('title')).toContain('Decisão do professor: 3 — Suficiente');
        // A proposta não desaparece: é ela que explica por que houve decisão.
        expect(cell.attributes('title')).toContain('Proposta do Lapispro: 5 — Muito Bom');

        // E A ALTERAÇÃO TEM MARCA QUE NÃO É SÓ O NEGRITO (§25). Numa pauta
        // guardada o anel do botão desaparece, e a espessura da letra ficaria a
        // ser a única informação de que alguém interveio.
        expect(carolina.html()).toContain('prof.');
    });

    it('«Usar a proposta do Lapispro» apaga a decisão em vez de guardar uma vazia', async () => {
        const props = domainDecidableProps();
        props.sheet!.students[0].domains[0].decided_scale_level_id = 3;
        props.sheet!.students[0].domains[0].decided_scale_level_code = '3';
        props.sheet!.students[0].domains[0].decided_scale_level_label = 'Suficiente';

        const wrapper = mount(Show, { props });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        await carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!
            .trigger('click');

        const panel = openDomainPanel();
        const back = Array.from(panel.querySelectorAll('button')).find((button) =>
            button.textContent?.includes('Usar a proposta do Lapispro'),
        )! as HTMLButtonElement;
        back.click();
        await wrapper.vm.$nextTick();

        expect(routerPost.mock.calls[0][1]).toEqual({ scale_level_id: null });
    });

    it('uma pauta sem moradas não oferece ação nenhuma nos domínios', () => {
        // `decidableProps()` endereça os alunos mas não os domínios — que é o
        // que uma fotografia guardada faz.
        const wrapper = mount(Show, { props: decidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        const actions = carolina
            .findAll('button')
            .filter((button) => button.attributes('aria-label')?.includes('apreciação'));

        expect(actions).toHaveLength(0);
    });
});

describe('evaluation-sheets/Show — valores quantitativos desligados', () => {
    async function withoutQuantitative(props: ReturnType<typeof domainDecidableProps>) {
        const wrapper = mount(Show, { props });

        const toggle = wrapper
            .findAll('label')
            .find((label) => label.text().includes('Valores quantitativos'))!
            .find('input');

        await toggle.setValue(false);

        return wrapper;
    }

    it('substitui os códigos pelas menções da escala, e nunca por um valor inventado', async () => {
        const wrapper = await withoutQuantitative(domainDecidableProps());
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        // Domínio: «Muito Bom», não «5».
        const domainCell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;
        expect(domainCell.text()).toBe('Muito Bom');

        // Global e nível atribuído seguem a mesma regra.
        expect(carolina.find('.font-bold').text()).toBe('Bom');
        expect(carolina.text()).not.toMatch(/\b5\b/);
    });

    it('o código não se perde: fica no texto acessível de cada célula', async () => {
        const wrapper = await withoutQuantitative(domainDecidableProps());
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        const domainCell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        expect(domainCell.attributes('title')).toContain('código 5');
        expect(carolina.find('.font-bold').attributes('title')).toContain('código 4');
    });

    it('esconder os quantitativos não toca no que o servidor enviou', async () => {
        const props = domainDecidableProps();
        const before = JSON.stringify(props.sheet);

        await withoutQuantitative(props);

        expect(JSON.stringify(props.sheet)).toBe(before);
    });
});

/**
 * O TÍTULO DE UMA PAUTA GUARDADA é uma escolha, e não um campo em branco (§20).
 *
 * A razão é o histórico: escrever o título à mão de cada vez é como uma turma
 * acaba com «1º semestre», «1.o Semestre» e «Semestre 1» a designarem o mesmo
 * momento, e um histórico assim não se lê nem se ordena.
 *
 * «Outro…» é o que torna a lista aceitável — uma pauta guardada para uma
 * reunião não é nenhum dos momentos estruturais — e o título continua a ser SÓ
 * um título: não muda o momento nem a data, que viajam nos seus próprios campos.
 */
describe('o título do momento ao guardar a pauta', () => {
    function propsWithSave() {
        return {
            ...baseProps(),
            saveDefaults: {
                period_ulid: 'period-1',
                moment_label: 'Intercalar 1.º Semestre',
                moment_titles: [
                    'Intercalar 1.º Semestre',
                    '1.º Semestre',
                    'Intercalar 2.º Semestre',
                    '2.º Semestre',
                ],
                effective_at: '2026-11-20',
                starts_on: '2026-09-14',
                ends_on: '2027-01-29',
            },
        };
    }

    async function openSaveForm(props: ReturnType<typeof propsWithSave>) {
        const wrapper = mount(Show, { props });
        const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Guardar esta pauta'))!;
        await button.trigger('click');

        return wrapper;
    }

    it('oferece todos os momentos estruturais do ano, e mais «Outro…»', async () => {
        const wrapper = await openSaveForm(propsWithSave());
        const select = wrapper.find('#moment-title');

        expect(select.exists()).toBe(true);
        expect(select.findAll('option').map((option) => option.text())).toEqual([
            'Intercalar 1.º Semestre',
            '1.º Semestre',
            'Intercalar 2.º Semestre',
            '2.º Semestre',
            'Outro…',
        ]);
    });

    it('abre no momento em que o professor está, e não em «Outro…»', async () => {
        const wrapper = await openSaveForm(propsWithSave());

        expect((wrapper.find('#moment-title').element as HTMLSelectElement).value).toBe('Intercalar 1.º Semestre');
        // Sem campo livre à vista: não há nada para escrever enquanto a escolha
        // for um dos momentos estruturais.
        expect(wrapper.find('#moment-label').exists()).toBe(false);
    });

    it('«Outro…» abre um campo livre, e abre-o vazio', async () => {
        const wrapper = await openSaveForm(propsWithSave());
        const select = wrapper.find('#moment-title');

        await select.setValue('__other__');

        const field = wrapper.find('#moment-label');
        expect(field.exists()).toBe(true);
        // Vazio, e não pré-preenchido com «Intercalar 1.º Semestre»: um campo
        // já preenchido convida a guardar exatamente o que se acabou de recusar.
        expect((field.element as HTMLInputElement).value).toBe('');
    });

    it('diz que o título não muda o momento que está a ser guardado', async () => {
        const wrapper = await openSaveForm(propsWithSave());

        expect(wrapper.text()).toContain('Não altera o momento que está a ser guardado');
    });
});

/**
 * A COR E A SIGLA — as duas coisas que uma pauta mostra e que ninguém explicou
 * ao professor até aqui.
 *
 * A cor de uma apreciação vem da POSIÇÃO do nível na escala, e nunca do número
 * que ele calha ter (§24). E nunca é a única informação: o código continua
 * escrito e a frase inteira continua no texto acessível de cada célula (§25).
 *
 * O «A» em expoente é a autoavaliação do aluno naquele domínio. Um «A4» sem
 * explicação é uma sigla opaca, e o `title` só chega a quem passa o rato — por
 * isso a legenda está escrita e visível sempre que a autoavaliação estiver
 * ligada (§37).
 */
describe('a cor de uma apreciação e a sigla da autoavaliação', () => {
    it('pinta a apreciação pela posição do nível na escala', () => {
        const wrapper = mount(Show, { props: domainDecidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        const domainCell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        // «5» é o nível mais alto da escala de 1 a 5 → verde. O que o decide é
        // a posição, não o número.
        expect(domainCell.classes().join(' ')).toContain('emerald');
    });

    it('não deixa a cor ser a única informação', () => {
        const wrapper = mount(Show, { props: domainDecidableProps() });
        const carolina = wrapper.findAll('tbody tr').find((row) => row.text().includes('Carolina Nunes'))!;

        const domainCell = carolina
            .findAll('button')
            .find((button) => button.attributes('aria-label')?.includes('Oralidade'))!;

        // O código está escrito, e a frase inteira — de quem é o juízo e qual é
        // — chega a quem não vê cor nenhuma.
        expect(domainCell.text()).toBe('5');
        expect(domainCell.attributes('aria-label')).toContain('Oralidade');
        expect(domainCell.attributes('title')).toContain('Muito Bom');
    });

    it('explica o «A» por escrito, e não só ao passar o rato', () => {
        const wrapper = mount(Show, { props: selfAssessedProps() });

        expect(wrapper.text()).toContain('são a');
        expect(wrapper.text()).toContain('autoavaliação do aluno');
        // E diz o que ela NÃO é, que é a parte que mais importa (§15).
        expect(wrapper.text()).toContain('não entra em cálculo nenhum');
    });

    it('não deixa a legenda do «A» quando a autoavaliação não está à vista', async () => {
        const wrapper = mount(Show, { props: selfAssessedProps() });

        const toggle = wrapper
            .findAll('label')
            .find((label) => label.text().includes('Autoavaliação'))!
            .find('input');

        await toggle.setValue(false);

        expect(wrapper.text()).not.toContain('autoavaliação do aluno');
    });
});
