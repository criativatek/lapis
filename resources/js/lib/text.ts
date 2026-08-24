/**
 * Uppercases only the FIRST character of a string, leaving everything after
 * it exactly as it was.
 *
 * Portuguese writes weekdays, months and the linking "de" in lower case, and
 * `Intl.DateTimeFormat('pt-PT', …)` already produces exactly the right text —
 * "quarta-feira, 9 de setembro de 2026". The CSS `capitalize` class
 * (`text-transform: capitalize`) uppercases the first letter of EVERY word,
 * including both "de" and the half of the weekday after the hyphen, turning
 * that into "Quarta-Feira, 9 De Setembro De 2026". Starting a sentence needs
 * one character changed, not one per word — so it is done here, on the string,
 * rather than in CSS.
 */
export function capitalizeFirst(value: string): string {
    const [first = '', ...rest] = [...value];

    return first.toLocaleUpperCase('pt-PT') + rest.join('');
}
