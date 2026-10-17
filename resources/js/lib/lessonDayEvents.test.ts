import { describe, expect, it } from 'vitest';
import {
    appendEventToSummary,
    coverageLabel,
    eventTimeLabel,
    LESSON_SUMMARY_MAX_LENGTH,
    splitCoinciding,
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

describe('A mesma linha no servidor e no cliente (0.171.0)', () => {
    // Os mesmos casos de LessonCalendarActivitiesTest::summaryLineCases — o
    // servidor reconhece pela mesma regra a linha cuja origem não se distingue,
    // e que por isso recusa «Registar e adiar a preparação».
    it.each([
        [{ title: 'Reunião de departamento', notes: null }, 'Reunião de departamento'],
        [{ title: 'Reunião de departamento', notes: '' }, 'Reunião de departamento'],
        [{ title: 'Visita a Évora', notes: 'Levar o material de campo.' }, 'Visita a Évora — Levar o material de campo.'],
        [{ title: 'Visita a Évora', notes: 'Linha um.\nLinha dois.\n\nLinha três.' }, 'Visita a Évora — Linha um. Linha dois. Linha três.'],
        [{ title: 'Visita\nde estudo', notes: null }, 'Visita de estudo'],
        [{ title: '  Teste  ', notes: '  Sala 3 \n' }, 'Teste — Sala 3'],
    ])('summaryLineFor(%j) = %s', (event, expected) => {
        expect(summaryLineFor(event)).toBe(expected);
    });
});

describe('splitCoinciding', () => {
    it('separa os que coincidem dos outros, mantendo a ordem de cada grupo', () => {
        const events = [
            { ulid: 'a', coincides: false },
            { ulid: 'b', coincides: true },
            { ulid: 'c' },
            { ulid: 'd', coincides: true },
        ];

        const { coinciding, others } = splitCoinciding(events);

        expect(coinciding.map((event) => event.ulid)).toEqual(['b', 'd']);
        expect(others.map((event) => event.ulid)).toEqual(['a', 'c']);
    });
});

describe('rótulos de horário e de cobertura', () => {
    it('eventTimeLabel: «Todo o dia», intervalo, ou só o início', () => {
        expect(eventTimeLabel({ all_day: true, starts_at: null, ends_at: null })).toBe('Todo o dia');
        expect(eventTimeLabel({ all_day: false, starts_at: '10:30', ends_at: '11:30' })).toBe('10:30–11:30');
        expect(eventTimeLabel({ all_day: false, starts_at: '10:30', ends_at: null })).toBe('10:30');
    });

    it('coverageLabel: toda a hora da aula, parte da aula, ou nada (o dia inteiro já diz «Todo o dia»)', () => {
        expect(coverageLabel('all_day')).toBeNull();
        expect(coverageLabel('whole_lesson')).toBe('cobre toda a hora da aula');
        expect(coverageLabel('partial')).toBe('cobre parte da aula');
        expect(coverageLabel(null)).toBeNull();
        expect(coverageLabel(undefined)).toBeNull();
    });
});

describe('incluir duas vezes não duplica', () => {
    it('depois de incluída, a linha fica bloqueada como «já no sumário»', () => {
        const event = { title: 'Visita de estudo', notes: 'Autocarro às 9h.' };
        const once = appendEventToSummary('Plano da aula.', event);

        expect(summaryAppendBlockedReason(once, event)).toBe('already_present');
        // Mesmo escrita com fim de linha do Windows.
        expect(summaryAppendBlockedReason(once.replace(/\n/g, '\r\n'), event)).toBe('already_present');
    });
});
