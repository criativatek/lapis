import { describe, expect, it } from 'vitest';
import { gapSentence, persistentSentence, stepsSentence, stoppedSentence, TREND_WORD } from './trajectories';
import type { Persistent, Trend } from './trajectories';

const trend = (over: Partial<Trend> = {}): Trend => ({
    state: 'consistent_up', with_value: 4, counts: { up: 3, flat: 0, down: 0 }, gaps: [],
    steps: [
        { from: 'A', to: 'B', direction: 'up', points: '5.0', previous: '60.0', current: '65.0' },
        { from: 'B', to: 'C', direction: 'up', points: '5.0', previous: '65.0', current: '70.0' },
        { from: 'C', to: 'D', direction: 'up', points: '5.0', previous: '70.0', current: '75.0' },
    ],
    ...over,
});

const persistent = (over: Partial<Persistent> = {}): Persistent => ({ state: 'yes', run: 2, points: [], stopped_by: 'start', ...over });

describe('trajectories', () => {
    it('names the four trends and the lack of them', () => {
        expect(TREND_WORD.consistent_up).toBe('Progressão consistente');
        expect(TREND_WORD.consistent_down).toBe('Regressão consistente');
        expect(TREND_WORD.stable).toBe('Estável');
        expect(TREND_WORD.variable).toBe('Evolução variável');
        expect(TREND_WORD.insufficient).toBe('Sem momentos suficientes');
    });

    it('says the evidence of a trend as counts of steps', () => {
        expect(stepsSentence(trend())).toBe('Subiu em 3 de 3 passos');
        expect(stepsSentence(trend({ counts: { up: 1, flat: 1, down: 1 }, steps: trend().steps }))).toBe('Subiu em 1, ficou igual em 1, desceu em 1 de 3 passos');
    });

    it('says why there is no trend when there are fewer than three values', () => {
        expect(stepsSentence(trend({ state: 'insufficient', with_value: 2, steps: [], counts: { up: 0, flat: 0, down: 0 } }))).toBe('2 momentos com valor: uma tendência precisa de 3');
        expect(stepsSentence(trend({ state: 'insufficient', with_value: 1, steps: [], counts: { up: 0, flat: 0, down: 0 } }))).toBe('1 momento com valor: uma tendência precisa de 3');
        expect(stepsSentence(trend({ state: 'insufficient', with_value: 0, steps: [], counts: { up: 0, flat: 0, down: 0 } }))).toBe('Sem valores');
    });

    it('names the gaps and the moments left out, and says the trend rests on the moments with a value', () => {
        expect(gapSentence(trend())).toBeNull();

        const sentence = gapSentence(trend({ gaps: [{ label: 'B', reason: 'no_value' }, { label: 'C', reason: 'left_out' }] }));

        expect(sentence).toContain('sem valor em B');
        expect(sentence).toContain('C fora da linha por critérios ou escala diferentes');
        expect(sentence).toContain('A tendência assenta só nos momentos com valor.');
    });

    it('says a persistent negative in plain words, with the number of consecutive moments', () => {
        expect(persistentSentence(persistent())).toBe('Abaixo do nível positivo em 2 momentos seguidos, incluindo o mais recente.');
        expect(persistentSentence(persistent({ run: 3 }))).toBe('Abaixo do nível positivo em 3 momentos seguidos, incluindo o mais recente.');
    });

    it('does not say «still negative» when the latest moment has no value or no line', () => {
        expect(persistentSentence(persistent({ state: 'no_recent', run: 0 }))).toContain('não se pode dizer que continua abaixo do nível positivo');
        expect(persistentSentence(persistent({ state: 'unknown', run: 0 }))).toContain('não se sabe se está abaixo do nível positivo');
        expect(persistentSentence(persistent({ state: 'no', run: 0 }))).toBeNull();
        // Nothing negative to confirm, or no value at all: nothing to say.
        expect(persistentSentence(persistent({ state: 'no_value', run: 0 }))).toBeNull();
        expect(persistentSentence(persistent({ state: 'insufficient', run: 0 }))).toBeNull();
    });

    it('says why a run stopped at a hole or at a change of rule, and not when it simply started', () => {
        expect(stoppedSentence(persistent({ stopped_by: 'start' }))).toBeNull();
        expect(stoppedSentence(persistent({ stopped_by: 'positive' }))).toBeNull();
        expect(stoppedSentence(persistent({ stopped_by: 'no_value' }))).toContain('momento sem valor');
        expect(stoppedSentence(persistent({ stopped_by: 'left_out' }))).toContain('critérios ou escala diferentes');
        expect(stoppedSentence(persistent({ stopped_by: 'unknown_band' }))).toContain('a escala não permite');

        // A single negative moment stopped by a hole is worth saying; one that is simply not negative is not.
        expect(stoppedSentence(persistent({ state: 'no', run: 1, stopped_by: 'no_value' }))).toContain('momento sem valor');
        expect(stoppedSentence(persistent({ state: 'no', run: 0, stopped_by: 'no_value' }))).toBeNull();
    });
});
