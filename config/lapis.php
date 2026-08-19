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

    /*
    |--------------------------------------------------------------------------
    | Writing assistant
    |--------------------------------------------------------------------------
    |
    | The optional layer that REPHRASES text LÁPIS already wrote. It is never a
    | source of fact: the deterministic composers produce the sentences, and
    | this can only make them read better. Everything about it is off until an
    | operator turns it on.
    |
    | NO VENDOR IS CHOSEN HERE, deliberately. Picking an AI provider is on the
    | «do not do without asking» list in CLAUDE.md §31, so there is no default
    | driver, no default endpoint and no default model — `driver` is null and
    | the feature reports itself as unavailable until somebody decides.
    |
    | `chat-completions` names a WIRE FORMAT, not a company: the
    | POST /chat/completions shape that most hosted and self-hosted engines
    | implement. Whoever sets LAPIS_AI_ENDPOINT decides where the text goes,
    | including to a model running inside the school.
    |
    | The key is read from the environment and nowhere else — never the
    | database, never the frontend, never a log line, never an exception
    | message (§47 of the brief).
    |
    */

    'ai' => [

        // null | 'chat-completions' | 'fake'. Null means the feature is off.
        'driver' => env('LAPIS_AI_DRIVER'),

        'endpoint' => env('LAPIS_AI_ENDPOINT'),

        'key' => env('LAPIS_AI_KEY'),

        'model' => env('LAPIS_AI_MODEL'),

        // Seconds. Short on purpose: a teacher waiting on a rephrase would
        // rather be told it failed than watch a spinner (§27).
        'timeout' => (int) env('LAPIS_AI_TIMEOUT', 20),

        // How much text may be sent in one request. A section far longer than
        // this is refused rather than truncated — half a section rephrased is
        // worse than none.
        'max_characters' => (int) env('LAPIS_AI_MAX_CHARACTERS', 6000),

        // Requests per minute, per user. Rate limiting is applied per user AND
        // per organization, whichever runs out first.
        'per_minute' => (int) env('LAPIS_AI_PER_MINUTE', 10),

        'organization_per_minute' => (int) env('LAPIS_AI_ORGANIZATION_PER_MINUTE', 40),

    ],

];
