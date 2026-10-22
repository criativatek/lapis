import { describe, expect, it } from 'vitest';
import { levelChangeText, levelReasonWord, movementWord, percent, points, reasonWord, studentsCount } from './assessmentComparison';
import type { LevelChange } from './assessmentComparison';

const level = (code: string, label: string, sequence: number, negative: boolean) => ({ code, label, sequence, is_negative: negative });

describe('assessmentComparison', () => {
    it('formats percentages with one decimal and a comma, and an absence as a dash — never a zero', () => {
        expect(percent('62.04')).toBe('62,0%');
        expect(percent('70.55')).toBe('70,5%'); // toFixed: the server already rounded; the page only trims
        expect(percent(null)).toBe('—');
        expect(percent(undefined)).toBe('—');
        expect(percent('')).toBe('—');
    });

    it('says a difference of percentages in percentage points, signed', () => {
        expect(points('8.5')).toBe('+8,5 p.p.');
        expect(points('-3.2')).toBe('-3,2 p.p.');
        expect(points('0.0')).toBe('0,0 p.p.');
        expect(points(null)).toBe('—');
    });

    it('names each movement in the words the product already uses', () => {
        expect(movementWord('up')).toBe('Progrediu');
        expect(movementWord('down')).toBe('Regrediu');
        expect(movementWord('flat')).toBe('Manteve-se');
        expect(movementWord('unavailable')).toBe('Sem comparação');
    });

    it('explains why a student has no movement', () => {
        expect(reasonWord('missing_from')).toContain('primeiro momento');
        expect(reasonWord('missing_to')).toContain('segundo momento');
        expect(reasonWord('missing_both')).toContain('nenhum');
        expect(reasonWord('not_comparable')).toContain('critérios');
        expect(reasonWord(null)).toBe('');
    });

    it('explains why a level change is not given', () => {
        expect(levelReasonWord('not_comparable')).toContain('escala');
        expect(levelReasonWord('decided_on_another_scale')).toContain('outra escala');
        expect(levelReasonWord('missing')).toContain('Sem nível');
        expect(levelReasonWord('no_sequence')).toContain('lugar na escala');
        expect(levelReasonWord('???')).toBe('');
    });

    it('writes a change of level, and the crossing of the scale\'s own line apart', () => {
        const change = (state: LevelChange['state'], crossed: LevelChange['crossed_line'], from = level('2', 'Insuficiente', 2, true), to = level('3', 'Suficiente', 3, false)): LevelChange => ({
            state, from, to, crossed_line: crossed, reason: null,
        });

        expect(levelChangeText(change('up', 'to_positive'))).toBe('Insuficiente → Suficiente — passou a nível positivo');
        expect(levelChangeText(change('down', 'to_negative', level('3', 'Suficiente', 3, false), level('2', 'Insuficiente', 2, true)))).toBe('Suficiente → Insuficiente — passou a nível negativo');
        expect(levelChangeText(change('up', null, level('3', 'Suficiente', 3, false), level('4', 'Bom', 4, false)))).toBe('Suficiente → Bom');
        expect(levelChangeText(change('same', null, level('4', 'Bom', 4, false), level('4', 'Bom', 4, false)))).toBe('Manteve «Bom»');
        expect(levelChangeText({ state: 'unavailable', from: null, to: null, crossed_line: null, reason: 'missing' })).toBe('Sem nível num dos momentos.');
        expect(levelChangeText({ state: 'unavailable', from: null, to: null, crossed_line: null, reason: null })).toBe('—');
    });

    it('counts students in the singular and the plural', () => {
        expect(studentsCount(1)).toBe('1 aluno');
        expect(studentsCount(0)).toBe('0 alunos');
        expect(studentsCount(6)).toBe('6 alunos');
    });
});
