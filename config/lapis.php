<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default jurisdiction
    |--------------------------------------------------------------------------
    |
    | The jurisdiction used when an organization has none of its own — an
    | ISO 3166-1 alpha-2 code. It decides which legal framework the
    | Interventions module resolves, and nothing else.
    |
    | This is a COMPATIBILITY BRIDGE, not the definitive model. It exists so
    | that installations predating organizations.jurisdiction keep behaving
    | exactly as before. Once institutional onboarding lets an organization
    | state its own jurisdiction, that becomes the real source and this
    | fallback can be turned off in new installations by setting it to null.
    |
    | It applies ONLY to an organization that has never had a jurisdiction
    | set. An organization that explicitly names one nobody supports resolves
    | to no framework at all — never to this default. Silently applying one
    | country's law to another country's school would be worse than applying
    | none.
    |
    | Never derived from locale, timezone, domain or language: a school in
    | Portugal may work in English, and a school abroad may work in Portuguese.
    |
    */

    'default_jurisdiction' => env('LAPIS_DEFAULT_JURISDICTION', 'PT'),

];
