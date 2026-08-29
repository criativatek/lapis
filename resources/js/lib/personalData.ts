/**
 * PREVENTIVE DETECTION OF PERSONAL DATA IN TEXT A TEACHER TYPED.
 *
 * WHAT THIS IS, AND — MORE IMPORTANTLY — WHAT IT IS NOT.
 *
 * It is NOT the privacy barrier. `AiPayloadSanitizer` is, it runs on the
 * server, it runs on every payload without exception, and it removes what it
 * finds. This runs in the browser, finds the same shapes, changes nothing, and
 * asks the teacher to look again before the request leaves. The two are
 * complementary and the order matters: this one exists so a teacher gets the
 * chance to not send something, rather than sending it and having it stripped.
 *
 * IT NEVER MODIFIES THE TEXT. Not here, not in the component that shows the
 * result, not anywhere. A guard that silently rewrote what somebody wrote would
 * be worse than no guard: they would submit a sentence they never composed and
 * would never know.
 *
 * IT CALLS NOTHING. No network, no service, no model. Every rule below is a
 * regular expression evaluated locally, which is the whole of the mechanism.
 *
 * IT DOES NOT GUESS AT NAMES, and that limitation is deliberate rather than
 * unfinished. A capitalised word is not a name — «Leitura», «Português»,
 * «Escrita», «Educação Literária», «Setembro» and the first word of every
 * sentence are all capitalised, and a rule that flagged them would fire on
 * almost every legitimate sentence a teacher writes. A guard that cries wolf on
 * ordinary text is a guard that gets clicked through without reading, which
 * makes it worse than nothing. So arbitrary personal names are NOT detected,
 * the product does not claim they are, and the standing notice on every field
 * asks teachers not to write them in the first place.
 *
 * THE ONE NAME IT CAN DETECT is a name the calling screen ALREADY HAS in front
 * of it for its own reasons — the student whose page it is. That costs no
 * query, loads no roster, and exposes nothing that was not already on screen.
 * A screen with no name in hand passes none, and the Centro de Ajuda must never
 * fetch one: see `useAiTextPrivacyGuard`.
 *
 * THE RULE KEYS MIRROR `AiPayloadSanitizer::RULES`, and
 * `PersonalDataDetectorCoherenceTest` fails if the two sets drift apart. That
 * is what keeps «the warning fires on what the sanitiser would remove» true
 * rather than aspirational.
 */

/** The rule keys, mirroring `AiPayloadSanitizer::RULES` plus the local-name case. */
export type PersonalDataRule =
    | 'urls'
    | 'emails'
    | 'postal_codes'
    | 'phone_numbers'
    | 'record_numbers'
    | 'identifiers'
    | 'long_numbers'
    | 'known_name';

export type PersonalDataFinding = {
    rule: PersonalDataRule;
    /** pt-PT, for the confirmation panel. Never the matched value. */
    label: string;
};

export type DetectOptions = {
    /**
     * Names the calling screen already holds for its own reasons. Never
     * fetched, never a roster — see the module docblock.
     */
    knownNames?: string[];
};

/**
 * Pattern per rule, deliberately the same shapes the server-side sanitiser
 * looks for.
 *
 * `phone_numbers` and `long_numbers` are the two that could plausibly fire on
 * pedagogical text, and both are narrow for that reason: the first wants the
 * Portuguese nine-digit shape or an international `+` run, and the second wants
 * six or more consecutive digits — which no grade, percentage or class number
 * ever is.
 */
const RULES: { rule: PersonalDataRule; pattern: RegExp; label: string }[] = [
    { rule: 'urls', pattern: /\b(?:https?:\/\/|www\.)\S+/iu, label: 'um endereço de internet' },
    { rule: 'emails', pattern: /[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[a-z]{2,}/iu, label: 'um endereço de correio eletrónico' },
    { rule: 'postal_codes', pattern: /\b\d{4}-\d{3}\b/u, label: 'um código postal' },
    { rule: 'phone_numbers', pattern: /\B\+\d{6,15}\b|\b\d{3}[ .\-]?\d{3}[ .\-]?\d{3}\b/u, label: 'um contacto telefónico' },
    { rule: 'record_numbers', pattern: /\b(?:n\.\s*[ºo°]|n[º°]|n[uú]mero)\s*:?\s*\d+/iu, label: 'um número de aluno ou de processo' },
    {
        rule: 'identifiers',
        pattern: /\b(?:[0-7][0-9ABCDEFGHJKMNPQRSTVWXYZ]{25}|[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})\b/iu,
        label: 'um identificador interno da aplicação',
    },
    { rule: 'long_numbers', pattern: /\b\d{6,}\b/u, label: 'uma sequência longa de algarismos' },
];

/**
 * What was found, once per rule, in the order the rules are declared.
 *
 * NEVER THE MATCHED VALUE — only which kind of thing matched. The findings
 * travel into a component that renders them on screen, and «encontrámos o
 * e-mail ana@escola.pt» would put the very thing this guard exists to keep
 * out of a prompt onto the page instead.
 */
export function detectPersonalData(text: string, options: DetectOptions = {}): PersonalDataFinding[] {
    const subject = text ?? '';

    if (subject.trim() === '') {
        return [];
    }

    const findings: PersonalDataFinding[] = [];

    for (const { rule, pattern, label } of RULES) {
        if (pattern.test(subject)) {
            findings.push({ rule, label });
        }
    }

    if (containsKnownName(subject, options.knownNames ?? [])) {
        findings.push({ rule: 'known_name', label: 'o nome do aluno desta página' });
    }

    return findings;
}

/**
 * Whether one of the names the screen already holds appears in the text.
 *
 * WHOLE WORDS, NOT SUBSTRINGS, and every part of the name checked separately —
 * a display name is «Ana Marques» and a teacher writes «a Ana». Parts shorter
 * than four characters are skipped: «Ana» is a name and also a fragment of
 * ordinary Portuguese, and a two-letter particle like «de» would match every
 * sentence.
 */
function containsKnownName(text: string, names: string[]): boolean {
    const haystack = fold(text);

    for (const name of names) {
        for (const part of fold(name).split(' ')) {
            if (part.length < 4) {
                continue;
            }

            if (new RegExp(`\\b${escapeRegExp(part)}\\b`, 'u').test(haystack)) {
                return true;
            }
        }
    }

    return false;
}

/** Lowercase, accent-stripped, punctuation collapsed — so «Marques,» matches «marques». */
function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/gu, ' ')
        .trim();
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/gu, '\\$&');
}
