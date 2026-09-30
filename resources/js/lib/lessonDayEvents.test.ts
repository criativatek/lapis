import { describe, expect, it } from 'vitest';
import {
    appendEventToSummary,
    LESSON_SUMMARY_MAX_LENGTH,
    summaryAppendBlockedReason,
    summaryLineFor,
} from '@/lib/lessonDayEvents';

describe('summaryLineFor', () => {
    it('é só o título quando não há notas', () => {
        expect(summaryLineFor({ title: 'Reunião de departamento', notes: null })).toBe('Reunião de departamento');
        expect(summaryLineFor({ title: 'Reunião de departamento', notes: '' })).toBe('Reunião de departamento');
    });

    it('junta o título às notas com « — » numa única linha', () => {
        expect(summaryLineFor({ title: 'Visita a Évora', notes: 'Levar o material de campo.' })).toBe(
            'Visita a Évora — Levar o material de campo.',
        );
    });

    it('colapsa quebras de linha internas das notas em espaços', () => {
        expect(summaryLineFor({ title: 'Visita a Évora', notes: 'Linha um.\nLinha dois.\n\nLinha três.' })).toBe(
            'Visita a Évora — Linha um. Linha dois. Linha três.',
        );
    });
});

describe('appendEventToSummary', () => {
    const event = { title: 'Reunião de departamento', notes: null };

    it('conteúdo vazio fica só a linha nova', () => {
        expect(appendEventToSummary('', event)).toBe('Reunião de departamento');
    });

    it('acrescenta uma quebra de linha quando o conteúdo não acaba numa', () => {
        expect(appendEventToSummary('Introdução aos números racionais.', event)).toBe(
            'Introdução aos números racionais.\nReunião de departamento',
        );
    });

    it('não duplica a quebra de linha quando o conteúdo já acaba numa', () => {
        expect(appendEventToSummary('Introdução aos números racionais.\n', event)).toBe(
            'Introdução aos números racionais.\nReunião de departamento',
        );
    });
});

describe('summaryAppendBlockedReason', () => {
    const event = { title: 'Reunião de departamento', notes: 'Ata em anexo.' };

    it('null quando pode acrescentar', () => {
        expect(summaryAppendBlockedReason('Introdução aos números racionais.', event)).toBeNull();
    });

    it('already_present quando a linha exata já está no sumário', () => {
        const content = 'Aula normal.\nReunião de departamento — Ata em anexo.';

        expect(summaryAppendBlockedReason(content, event)).toBe('already_present');
    });

    it('colapsa também quebras de linha no título', () => {
        const multilineTitle = { title: 'Visita\nde estudo', notes: null };

        expect(summaryAppendBlockedReason('Aula normal.\nVisita de estudo', multilineTitle)).toBe('already_present');
    });

    it('não bloqueia quando o título só aparece DENTRO de outra linha', () => {
        const shortEvent = { title: 'Reunião', notes: null };

        expect(summaryAppendBlockedReason('Preparação da Reunião de pais.', shortEvent)).toBeNull();
        expect(summaryAppendBlockedReason('Aula normal.\nReunião', shortEvent)).toBe('already_present');
    });

    it('too_long quando acrescentar ultrapassaria o limite do sumário', () => {
        const content = 'x'.repeat(LESSON_SUMMARY_MAX_LENGTH - 5);

        expect(summaryAppendBlockedReason(content, event)).toBe('too_long');
    });
});
