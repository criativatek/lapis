import { computed, ref } from 'vue';
import { detectPersonalData } from '@/lib/personalData';
import type { DetectOptions, PersonalDataFinding } from '@/lib/personalData';

/**
 * ONE GUARD, THREE SCREENS.
 *
 * Every place in the product where a teacher types text that will reach an AI
 * engine goes through this: the Centro de Ajuda's question box, the objective
 * beside a strategy suggestion, and the body of a report section before it is
 * sent to be reworded. Implementing the same «detect → warn → confirm» dance
 * three times would have produced three slightly different ideas of what a
 * warning looks like within one release, which is the same reason
 * `AiReadingPanel` exists.
 *
 * WHAT IT DOES, IN ORDER:
 *
 *   1. `run(text, submit)` looks for shapes that are objectively personal data.
 *   2. Nothing found → `submit()` is called immediately. The common case costs
 *      one regex pass and no extra click.
 *   3. Something found → `submit()` is NOT called, and `findings` fills. The
 *      screen renders the confirmation, and nothing has left the browser.
 *   4. `proceed()` runs the same `submit`. `edit()` throws it away.
 *
 * CONTINUING IS AN EXPLICIT ACT AND NOT A BLOCK. A teacher may have a good
 * reason to send something that looks like a telephone number — it may be a
 * page reference, a code, a year range that the pattern misread. Refusing
 * outright would make them retype it as words to get past the guard, which is
 * worse for everybody. The product asks; it does not forbid.
 *
 * IT NEVER TOUCHES THE TEXT. It has no way to: `run` takes a string and returns
 * nothing, and the only thing it stores is which KINDS of pattern matched.
 * Silently rewriting what somebody wrote would mean submitting a sentence they
 * never composed.
 *
 * IT CALLS NOTHING AND FETCHES NOTHING. `knownNames` is for the one case where
 * a screen already has a student's name on it for its own reasons — the
 * student's own Evolução page. The Centro de Ajuda passes none and must never
 * load a roster to find one: fetching student data in order to avoid sending
 * student data is a worse trade than the one it solves.
 */
export function useAiTextPrivacyGuard() {
    const findings = ref<PersonalDataFinding[]>([]);

    /**
     * The submit that was held back. Deliberately NOT a `ref`: it is a callback
     * and not state, nothing renders from it, and making it reactive would
     * invite a template to reach for it.
     */
    let pending: (() => void) | null = null;

    /** True while the teacher is being asked to look again. */
    const awaitingConfirmation = computed(() => findings.value.length > 0);

    /**
     * Check, then either submit or hold.
     *
     * Returns whether it submitted, for a caller that needs to know whether to
     * set its own «a pedir…» flag.
     */
    function run(text: string, submit: () => void, options: DetectOptions = {}): boolean {
        const found = detectPersonalData(text, options);

        if (found.length === 0) {
            reset();
            submit();

            return true;
        }

        findings.value = found;
        pending = submit;

        return false;
    }

    /** «Continuar mesmo assim» — the explicit second act. */
    function proceed(): void {
        const submit = pending;

        reset();
        submit?.();
    }

    /** «Voltar e editar» — the held request is dropped, the text is untouched. */
    function edit(): void {
        reset();
    }

    function reset(): void {
        findings.value = [];
        pending = null;
    }

    return { findings, awaitingConfirmation, run, proceed, edit, reset };
}
