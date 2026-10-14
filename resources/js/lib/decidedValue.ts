/**
 * A decision stored as a bare value is a number on the scale the decision was
 * TAKEN on — not necessarily on the scale the class is assessed on today.
 *
 * The server says which: `is_percentage` when that scale is a percentage, and
 * the scale's name when it differs from the class's current one. This only
 * PRESENTS. It never converts the number, never recalculates it and never
 * reinterprets it on another scale: 65 decided on a percentage is «65%», on any
 * screen, whatever scale the class has moved to.
 */

/** «65.000» decided on a percentage reads «65%»; on any other scale the value is left as it came. */
export function decidedValueText(value: string | null | undefined, isPercentage: boolean | null | undefined): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (isPercentage !== true) {
        return value;
    }

    return `${String(Number(value)).replace('.', ',')}%`;
}

/** The sentence that names the scale a value was decided on, when it is not the class's own. */
export function decidedScaleNote(scaleName: string | null | undefined): string | null {
    return scaleName ? `Decidida na escala «${scaleName}» (versão anterior do perfil)` : null;
}
