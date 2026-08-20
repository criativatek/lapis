/**
 * The password requirements, in words, before the teacher fails one.
 *
 * THE SERVER IS THE ONLY SOURCE. `Password::defaults()->toPasswordRulesString()`
 * already travels to these pages — it exists for Safari's `passwordrules`
 * attribute, which generates a conforming password and shows the person
 * nothing. Reading the same string here means the list on screen cannot drift
 * from the rule that will actually judge the input: production requires twelve
 * characters and four character classes, development requires eight and nothing
 * else, and both descriptions come out of this function without either being
 * written down twice.
 *
 * Not exhaustive by design. `uncompromised()` — the rule that rejects a
 * password for appearing in a public breach — has no representation in the
 * string, and could not be checked in the browser anyway. It stays where it
 * belongs: in the message returned when it fails, which now says what to do
 * about it (§23.4).
 */
export function describePasswordRules(rules: string): string[] {
    const requirements: string[] = [];

    const minimumLength = rules.match(/minlength:\s*(\d+)/);

    if (minimumLength) {
        requirements.push(`Pelo menos ${minimumLength[1]} caracteres`);
    }

    const needs = (token: string): boolean =>
        new RegExp(`required:\\s*${token}\\b`).test(rules);


    // Upper and lower arrive as two tokens and read as one sentence: a person
    // reading a checklist does not think "lowercase" and "uppercase" as
    // separate chores.
    if (needs('lower') && needs('upper')) {
        requirements.push('Letras maiúsculas e minúsculas');
    } else if (needs('lower')) {
        requirements.push('Pelo menos uma letra minúscula');
    } else if (needs('upper')) {
        requirements.push('Pelo menos uma letra maiúscula');
    }

    if (needs('digit')) {
        requirements.push('Pelo menos um algarismo');
    }

    if (needs('special')) {
        requirements.push('Pelo menos um símbolo (! ? @ #)');
    }

    return requirements;
}
