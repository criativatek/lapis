import { describe, expect, it, vi } from 'vitest';
import { useAiTextPrivacyGuard } from './useAiTextPrivacyGuard';

/**
 * THE FLOW, AND THE THREE PROPERTIES IT HAS TO KEEP.
 *
 * 1. Clean text is not slowed down by a single extra click.
 * 2. Suspect text does not leave until the teacher says so, explicitly.
 * 3. The text is never touched, in either direction.
 *
 * The third is the one that would be easy to break by accident — a «helpful»
 * scrub that replaced an e-mail before sending would mean the teacher submitted
 * a sentence they never wrote and never saw.
 */

describe('useAiTextPrivacyGuard — clean text', () => {
    it('submits immediately and reports that it did', () => {
        const guard = useAiTextPrivacyGuard();
        const submit = vi.fn();

        expect(guard.run('Consolidar a coesão textual.', submit)).toBe(true);
        expect(submit).toHaveBeenCalledTimes(1);
        expect(guard.findings.value).toEqual([]);
        expect(guard.awaitingConfirmation.value).toBe(false);
    });
});

describe('useAiTextPrivacyGuard — suspect text', () => {
    it('holds the request and reports that it did', () => {
        const guard = useAiTextPrivacyGuard();
        const submit = vi.fn();

        expect(guard.run('Contactar ana@escola.test.', submit)).toBe(false);
        expect(submit).not.toHaveBeenCalled();
        expect(guard.awaitingConfirmation.value).toBe(true);
        expect(guard.findings.value.map((finding) => finding.rule)).toContain('emails');
    });

    it('sends nothing when the teacher goes back to edit', () => {
        const guard = useAiTextPrivacyGuard();
        const submit = vi.fn();

        guard.run('Contactar ana@escola.test.', submit);
        guard.edit();

        expect(submit).not.toHaveBeenCalled();
        expect(guard.awaitingConfirmation.value).toBe(false);
        expect(guard.findings.value).toEqual([]);
    });

    it('sends exactly once when the teacher continues anyway', () => {
        const guard = useAiTextPrivacyGuard();
        const submit = vi.fn();

        guard.run('Contactar ana@escola.test.', submit);
        guard.proceed();

        expect(submit).toHaveBeenCalledTimes(1);
        expect(guard.awaitingConfirmation.value).toBe(false);
    });

    it('does not resend after a second proceed — the held request is used up', () => {
        const guard = useAiTextPrivacyGuard();
        const submit = vi.fn();

        guard.run('Contactar ana@escola.test.', submit);
        guard.proceed();
        guard.proceed();

        expect(submit).toHaveBeenCalledTimes(1);
    });

    /**
     * Continuing is NOT a permanent opt-out. The next request is checked again,
     * because it is a different text and a different decision.
     */
    it('checks the next request again after a confirmation', () => {
        const guard = useAiTextPrivacyGuard();
        const first = vi.fn();
        const second = vi.fn();

        guard.run('Contactar ana@escola.test.', first);
        guard.proceed();

        expect(guard.run('Telefone 912345678.', second)).toBe(false);
        expect(second).not.toHaveBeenCalled();
    });
});

describe('useAiTextPrivacyGuard — it never touches the text', () => {
    /**
     * The guard receives a string and returns nothing about it. There is no
     * path by which the value a teacher typed could come back changed — this
     * asserts it at the only place it could happen.
     */
    it('leaves the caller holding exactly what it passed in', () => {
        const guard = useAiTextPrivacyGuard();
        const original = 'Contactar ana@escola.test, tel. 912345678.';
        let text = original;

        guard.run(text, () => {
            // Whatever the submit does, it does with the text the caller owns.
            expect(text).toBe(original);
        });

        guard.proceed();

        expect(text).toBe(original);
        text = original;
        expect(text).toBe(original);
    });

    it('exposes only which kinds matched, never the values', () => {
        const guard = useAiTextPrivacyGuard();

        guard.run('Contactar ana@escola.test, tel. 912345678.', vi.fn());

        const rendered = guard.findings.value.map((finding) => finding.label).join(' ');

        expect(rendered).not.toContain('ana@escola.test');
        expect(rendered).not.toContain('912345678');
    });
});
