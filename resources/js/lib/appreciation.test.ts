import { describe, expect, it } from 'vitest';
import type { EvaluationSheetStudentDomain } from '@/types';
import {
    assignedLevel,
    domainAppreciation,
    levelDetail,
    levelText,
    overallAppreciation,
} from './appreciation';

/**
 * O QUE APARECE NUMA CÉLULA, E A METADE QUE NUNCA DESAPARECE.
 *
 * Nada aqui fixa códigos nem menções concretas de escala nenhuma: o par
 * código/menção vem sempre da escala configurada, e estes testes usam pares
 * inventados de propósito («N1»/«Emergente») para que nenhum deles possa passar
 * por acaso graças a uma tabela «4 → Bom» escondida no código.
 */

function domain(overrides: Partial<EvaluationSheetStudentDomain> = {}): EvaluationSheetStudentDomain {
    return {
        domain_id: 1,
        name: 'Leitura',
        sequence: 1,
        normalized_value: '49.000000',
        weight_percent_applied: '20.00',
        scale_level_id: 7,
        scale_level_code: 'N2',
        scale_level_label: 'Em desenvolvimento',
        has_coverage_warning: false,
        coverage: { absences: [], no_elements: false, excluded_domain_ids: [] },
        ...overrides,
    };
}

describe('levelText', () => {
    it('escreve o código com os quantitativos à vista e a menção sem eles', () => {
        const level = { code: 'N3', label: 'Consolidado' };

        expect(levelText(level, true)).toBe('N3');
        expect(levelText(level, false)).toBe('Consolidado');
    });

    it('usa a metade que existe quando só existe uma', () => {
        expect(levelText({ code: 'N3', label: null }, false)).toBe('N3');
        expect(levelText({ code: null, label: 'Consolidado' }, true)).toBe('Consolidado');
        expect(levelText({ code: null, label: null }, true)).toBeNull();
    });
});

describe('levelDetail', () => {
    it('leva sempre as duas metades, na ordem da vista', () => {
        const level = { code: 'N3', label: 'Consolidado' };

        expect(levelDetail(level, true)).toBe('N3 — Consolidado');
        expect(levelDetail(level, false)).toBe('Consolidado — código N3');
    });
});

describe('domainAppreciation', () => {
    it('mostra a proposta enquanto o professor não decidir, e diz que é uma proposta', () => {
        const reading = domainAppreciation(domain(), true);

        expect(reading.text).toBe('N2');
        expect(reading.origin).toBe('proposed');
        expect(reading.description).toContain('Proposta do Lapispro');
        expect(reading.description).toContain('N2 — Em desenvolvimento');
    });

    it('uma proposta por domínio é VIGENTE, e a frase não a trata como pendência', () => {
        // ACEITAÇÃO TÁCITA. Se o Lapispro propõe e o professor não altera, a
        // proposta é a apreciação que vale: não há nada a aprovar e não se
        // escreve linha nenhuma para o confirmar. A frase antiga dizia «ainda
        // não decidida pelo professor» — descrevia uma pendência inexistente e
        // fazia a pauta parecer ter trabalho por fazer.
        const reading = domainAppreciation(domain(), true);

        expect(reading.description).toBe(
            'Proposta do Lapispro: N2 — Em desenvolvimento — vigente enquanto o professor não a alterar.',
        );
        expect(reading.description).not.toContain('ainda não');
        expect(reading.description).not.toContain('não decidida');
    });

    it('com os quantitativos desligados escreve a menção, nunca o código', () => {
        const reading = domainAppreciation(domain(), false);

        expect(reading.text).toBe('Em desenvolvimento');
        // O código não desaparece: continua na frase acessível.
        expect(reading.description).toContain('código N2');
    });

    it('mostra a decisão do professor quando existe, sem apagar a proposta da frase', () => {
        const reading = domainAppreciation(
            domain({
                decided_scale_level_id: 9,
                decided_scale_level_code: 'N3',
                decided_scale_level_label: 'Consolidado',
            }),
            true,
        );

        expect(reading.text).toBe('N3');
        expect(reading.origin).toBe('decided');
        expect(reading.description).toContain('Decisão do professor: N3 — Consolidado');
        expect(reading.description).toContain('Proposta do Lapispro: N2 — Em desenvolvimento');
    });

    it('a decisão também obedece à vista', () => {
        const reading = domainAppreciation(
            domain({
                decided_scale_level_id: 9,
                decided_scale_level_code: 'N3',
                decided_scale_level_label: 'Consolidado',
            }),
            false,
        );

        expect(reading.text).toBe('Consolidado');
        expect(reading.description).toContain('Consolidado — código N3');
    });

    it('sem proposta e sem decisão escreve um travessão, nunca um zero', () => {
        const reading = domainAppreciation(
            domain({ normalized_value: null, scale_level_id: null, scale_level_code: null, scale_level_label: null }),
            true,
        );

        expect(reading.text).toBe('—');
        expect(reading.origin).toBe('none');
    });

    it('uma pauta guardada antes desta decisão lê-se pela menção que trouxe', () => {
        // Uma fotografia antiga não tem `decided_*` nem `scale_level_code`: só o
        // rótulo. Continua a abrir, e continua a dizer o que dizia.
        const reading = domainAppreciation(
            domain({ scale_level_code: undefined, scale_level_label: 'Suficiente' }),
            true,
        );

        expect(reading.text).toBe('Suficiente');
        expect(reading.origin).toBe('proposed');
    });
});

describe('overallAppreciation', () => {
    const overall = {
        normalized_value: '78.000000',
        scale_value: '4',
        scale_level_id: 4,
        scale_level_code: 'N3',
        scale_level_label: 'Consolidado',
        result_state: 'computed',
        has_coverage_warning: false,
    };

    it('segue a mesma vista que os domínios', () => {
        expect(overallAppreciation(overall, true).text).toBe('N3');
        expect(overallAppreciation(overall, false).text).toBe('Consolidado');
    });

    it('a menção ganha ao valor na escala numa escala de bandas', () => {
        // O `scale_value` de uma escala de bandas é o número do nível — «4»
        // aqui, «3.000» na Escala 1 a 5 —, e a menção diz isso melhor. Era
        // exatamente esse número que a coluna «Quant.» da Pauta mostrava no
        // lugar da percentagem global.
        expect(overallAppreciation(overall, true).text).not.toBe('4');
        expect(overallAppreciation(overall, false).text).not.toBe('4');
    });

    it('numa escala de intervalo a proposta é o valor na escala', () => {
        // Não há menção nenhuma a nomear — o valor É a resposta inteira, tal
        // como na coluna «Nível atribuído» ao lado. Sem isto a proposta global
        // de um professor do secundário não teria coluna nenhuma no ecrã: a de
        // «Quant.» é uma percentagem, aqui como em cada domínio.
        const interval = {
            ...overall,
            scale_value: '16.400',
            scale_level_id: null,
            scale_level_code: null,
            scale_level_label: null,
        };

        expect(overallAppreciation(interval, true).text).toBe('16.400');
        expect(overallAppreciation(interval, false).text).toBe('16.400');
        expect(overallAppreciation(interval, true).origin).toBe('proposed');
    });

    it('sem valor nenhum continua a ser um travessão, e nunca um zero', () => {
        const empty = {
            ...overall,
            normalized_value: null,
            scale_value: null,
            scale_level_id: null,
            scale_level_code: null,
            scale_level_label: null,
        };

        expect(overallAppreciation(empty, true).text).toBe('—');
        expect(overallAppreciation(empty, true).origin).toBe('none');
    });
});

describe('assignedLevel', () => {
    it('o nível atribuído CONTINUA a ter uma pendência verdadeira', () => {
        // A aceitação tácita vale para os DOMÍNIOS e não para aqui. O nível
        // atribuído é o ato formal do professor (§3.3): enquanto ele não o toma,
        // há mesmo alguma coisa por fazer, e a frase tem de continuar a dizê-lo.
        // Se um dia esta asserção cair junto com a dos domínios, alguém apagou a
        // distinção entre uma leitura qualitativa e uma decisão.
        const reading = assignedLevel(
            {
                status: 'proposed',
                proposed_value: '3',
                proposed_scale_level_id: 3,
                proposed_scale_level_code: 'N2',
                proposed_scale_level_label: 'Em desenvolvimento',
                final_value: null,
                final_scale_level_id: null,
                final_scale_level_code: null,
                final_scale_level_label: null,
                override_reason: null,
            },
            true,
        );

        expect(reading.origin).toBe('proposed');
        expect(reading.description).toContain('ainda não decidida pelo professor');
    });

    it('a decisão do professor domina a proposta e diz-se por inteiro', () => {
        const reading = assignedLevel(
            {
                status: 'confirmed',
                proposed_value: '3',
                proposed_scale_level_id: 3,
                proposed_scale_level_code: 'N2',
                proposed_scale_level_label: 'Em desenvolvimento',
                final_value: '4',
                final_scale_level_id: 4,
                final_scale_level_code: 'N3',
                final_scale_level_label: 'Consolidado',
                override_reason: null,
            },
            true,
        );

        expect(reading.text).toBe('N3');
        expect(reading.origin).toBe('decided');
    });

    it('com os quantitativos desligados a decisão aparece pela menção', () => {
        const reading = assignedLevel(
            {
                status: 'confirmed',
                proposed_value: null,
                proposed_scale_level_id: null,
                proposed_scale_level_code: null,
                proposed_scale_level_label: null,
                final_value: '4',
                final_scale_level_id: 4,
                final_scale_level_code: 'N3',
                final_scale_level_label: 'Consolidado',
                override_reason: null,
            },
            false,
        );

        expect(reading.text).toBe('Consolidado');
    });

    it('numa escala de intervalo o valor É a resposta, com ou sem quantitativos', () => {
        const classification = {
            status: 'confirmed',
            proposed_value: '15.500',
            proposed_scale_level_id: null,
            proposed_scale_level_code: null,
            proposed_scale_level_label: null,
            final_value: '16',
            final_scale_level_id: null,
            final_scale_level_code: null,
            final_scale_level_label: null,
            override_reason: null,
        };

        expect(assignedLevel(classification, true).text).toBe('16');
        expect(assignedLevel(classification, false).text).toBe('16');
    });

    it('sem linha de classificação não há nível a mostrar', () => {
        expect(assignedLevel(null, true).text).toBe('—');
        expect(assignedLevel(null, true).origin).toBe('none');
    });
});
