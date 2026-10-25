import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import Preview from './Preview.vue';

// The errors a confirmation comes back with — set per test, cleared after.
const { formErrors } = vi.hoisted(() => ({ formErrors: {} as Record<string, string> }));

vi.mock('@inertiajs/vue3', () => ({
    Head: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', slots.default?.()),
    }),
    Link: defineComponent({
        props: { href: { type: String, default: '' } },
        setup:
            (props, { slots }) =>
            () =>
                h('a', { 'data-href': props.href }, slots.default?.()),
    }),
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            processing: false,
            errors: formErrors,
            post: vi.fn(),
            delete: vi.fn(),
        }),
}));

const wrappers: VueWrapper[] = [];

const SENTENCE = 'Campos obrigatórios em falta ou inválidos.';

function tally(partial: Partial<Record<string, number>> = {}) {
    return {
        new: 0,
        existing: 0,
        conflict: 0,
        invalid: 0,
        unsupported: 0,
        ...partial,
    };
}

function mountPreview() {
    const wrapper = mount(Preview, {
        props: {
            dataImport: {
                ulid: '01J0000000000000000000000A',
                status: 'pending',
                status_label: 'Por confirmar',
                original_filename: 'backup.zip',
                source_schema_version: 13,
                source_app_version: '0.157.0',
                source_generated_at: null,
                source_organization: null,
                summary: null,
                failure_reason: null,
            },
            destination: { name: 'Org fictícia', type: 'personal' },
            plan: {
                rows: {
                    student_item_scores: [
                        { classification: 'new', reason: null },
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    item_domain_allocations: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    self_assessment_responses: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    profile_version_domains: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                    cancelled_lesson_occurrences: [
                        { classification: 'invalid', reason: SENTENCE },
                    ],
                },
                counts: {
                    student_item_scores: tally({ new: 1, invalid: 1 }),
                    item_domain_allocations: tally({ invalid: 1 }),
                    self_assessment_responses: tally({ invalid: 1 }),
                    profile_version_domains: tally({ invalid: 1 }),
                    cancelled_lesson_occurrences: tally({ invalid: 1 }),
                },
                can_confirm: true,
            },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    Object.keys(formErrors).forEach((key) => delete formErrors[key]);
});

function mountFollowupPreview(summary: Record<string, unknown> | null = null) {
    const wrapper = mount(Preview, {
        props: {
            dataImport: {
                ulid: '01J0000000000000000000000B',
                status: summary === null ? 'pending' : 'imported',
                status_label: summary === null ? 'Por confirmar' : 'Importado',
                original_filename: 'backup.zip',
                source_schema_version: 15,
                source_app_version: '0.164.0',
                source_generated_at: null,
                source_organization: null,
                summary,
                failure_reason: null,
            },
            destination: { name: 'Org fictícia', type: 'personal' },
            plan: {
                rows: {
                    record_followup_cases: [
                        { classification: 'new', reason: null, label: 'Atrasos · 7.º Z · 1.º Período' },
                        { classification: 'conflict', reason: 'No destino, este acompanhamento tem outra informação de alerta. O destino é mantido.', label: 'Faltas de material · 7.º Z · 1.º Período' },
                    ],
                    record_followup_confirmations: [
                        {
                            classification: 'new',
                            reason: null,
                            label: 'Atrasos · 7.º Z · 1.º Período',
                            notice: 'A autoria desta marcação fica por associar.',
                            warning: 'Esta marcação abrange ocorrências cujos registos não existem neste restauro.',
                        },
                    ],
                },
                counts: {
                    record_followup_cases: tally({ new: 1, conflict: 1 }),
                    record_followup_confirmations: tally({ new: 1 }),
                },
                can_confirm: true,
            },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

describe('imports/data/Preview — retificações sem autor original', () => {
    function mountRectifications(rows: { classification: 'new'; reason: null; rectifier_unidentified: boolean }[]) {
        const wrapper = mount(Preview, {
            props: {
                dataImport: {
                    ulid: '01J0000000000000000000000C',
                    status: 'pending',
                    status_label: 'Por confirmar',
                    original_filename: 'backup.zip',
                    source_schema_version: 20,
                    source_app_version: '0.180.0',
                    source_generated_at: null,
                    source_organization: null,
                    summary: null,
                    failure_reason: null,
                },
                destination: { name: 'Org fictícia', type: 'personal' },
                plan: {
                    rows: { classifications: rows },
                    counts: { classifications: tally({ new: rows.length }) },
                    can_confirm: true,
                },
            },
        });
        wrappers.push(wrapper);

        return wrapper;
    }

    it('diz quantas retificações ficam sem autor, sem nomear ninguém', () => {
        const text = mountRectifications([
            { classification: 'new', reason: null, rectifier_unidentified: true },
            { classification: 'new', reason: null, rectifier_unidentified: true },
            { classification: 'new', reason: null, rectifier_unidentified: false },
        ]).text();

        expect(text).toContain('2 retificações');
        expect(text).toContain('ficam com «Autor original não identificado»');
        expect(text).toContain('nenhuma é atribuída à conta que importa');
    });

    it('sem retificações anónimas não mostra o aviso', () => {
        const text = mountRectifications([{ classification: 'new', reason: null, rectifier_unidentified: false }]).text();

        expect(text).not.toContain('Autor original não identificado');
    });
});

describe('imports/data/Preview — acompanhamento dos registos (v15)', () => {
    it('mostra os dois domínios, o conflito com a frase do plano e o restauro incompleto à parte da autoria', () => {
        const wrapper = mountFollowupPreview();
        const text = wrapper.text();

        expect(text).toContain('Casos de acompanhamento dos registos');
        expect(text).toContain('Marcações «Registado no Inovar»');
        expect(text).toContain('Faltas de material · 7.º Z · 1.º Período');
        expect(text).toContain('outra informação de alerta');

        const warnings = wrapper.find('[data-test="restore-warnings"]');
        expect(warnings.text()).toContain('Restauro incompleto');
        expect(warnings.text()).toContain('1 registo(s)');
        expect(warnings.text()).not.toContain('autoria');
        expect(text).toContain('Autoria original');
    });

    it('resume o que foi criado e quantas marcações ficaram incompletas', () => {
        const text = mountFollowupPreview({
            record_followup_cases_created: 2,
            record_followup_confirmations_created: 3,
            record_followup_confirmations_incomplete: 1,
        }).text();

        expect(text).toContain('Casos de acompanhamento dos registos');
        expect(text).toContain('Marcações restauradas sem todas as ocorrências');
    });
});

describe('imports/data/Preview — recusas de linhas filhas', () => {
    it('lista cada recusa em «Pontos a rever» com o nome do domínio e a frase do validador', () => {
        const text = mountPreview().text();

        expect(text).toContain('Pontos a rever');
        expect(text).toContain('Pontuações');
        expect(text).toContain('Alocações de itens a domínios');
        expect(text).toContain('Respostas de autoavaliação');
        expect(text).toContain('Pesos dos domínios nos perfis');
        expect(text).toContain('Aulas canceladas');
        expect(text.split(SENTENCE)).toHaveLength(6);
    });

    it('nunca mostra um traço solto no lugar do nome de um domínio escondido da tabela', () => {
        const items = mountPreview().findAll('li');
        const issues = items.filter((li) => li.text().includes(SENTENCE));

        expect(issues).toHaveLength(5);
        issues.forEach((li) => expect(li.text().startsWith('—')).toBe(false));
    });

    it('diz porque é que a confirmação não importou — por exemplo, restauros suspensos durante a recuperação', () => {
        const message = 'O restauro de cópias de segurança está temporariamente suspenso, enquanto a autoavaliação está só de leitura. Nada foi importado; a cópia carregada fica guardada e pode ser confirmada mais tarde.';
        formErrors.import = message;

        const alert = mountPreview().get('[data-test="import-error"]');

        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toBe(message);
    });

    it('sem erro de confirmação, não mostra aviso', () => {
        expect(mountPreview().find('[data-test="import-error"]').exists()).toBe(false);
    });
});

describe('imports/data/Preview — numeração das lições em conflito com o destino', () => {
    const message = 'A aula de 22/10/2026 (8.º F) do ficheiro tem a Lição 12, que no destino já é da aula de 23/10/2026. Corrija o número no destino ou restaure noutra conta.';

    function mountBlocked(blocking: string[]) {
        const wrapper = mount(Preview, {
            props: {
                dataImport: {
                    ulid: '01J0000000000000000000000C',
                    status: 'pending',
                    status_label: 'Por confirmar',
                    original_filename: 'backup.zip',
                    source_schema_version: 18,
                    source_app_version: '0.169.0',
                    source_generated_at: null,
                    source_organization: null,
                    summary: null,
                    failure_reason: null,
                },
                destination: { name: 'Org fictícia', type: 'personal' },
                plan: {
                    rows: {},
                    counts: {
                        lessons: { new: 3, existing: 0, conflict: 0, invalid: 0, unsupported: 0 },
                    },
                    can_confirm: blocking.length === 0,
                    blocking,
                },
            },
        });

        wrappers.push(wrapper);

        return wrapper;
    }

    it('mostra os conflitos em destaque e não deixa confirmar', () => {
        const wrapper = mountBlocked([message]);
        const alert = wrapper.get('[data-test="restore-blocking"]');

        expect(alert.attributes('role')).toBe('alert');
        expect(alert.text()).toContain(message);
        expect(alert.text()).toContain('nada é gravado');
        expect(wrapper.text()).toContain('Resolva a numeração das lições');
        expect(wrapper.find('button[disabled]').exists()).toBe(true);
    });

    it('sem conflitos, não mostra o aviso e deixa importar', () => {
        const wrapper = mountBlocked([]);

        expect(wrapper.find('[data-test="restore-blocking"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Importar dados');
    });
});
