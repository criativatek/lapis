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
    | Public site address
    |--------------------------------------------------------------------------
    |
    | The ONE address the public site declares as its own — the canonical, the
    | Open Graph url, the sitemap's <loc> and the Sitemap: line in robots.txt.
    |
    | IT EXISTS BECAUSE `url('/')` FOLLOWS THE REQUEST, NOT APP_URL. In an HTTP
    | request Laravel roots its urls at `$request->root()`; APP_URL only seeds
    | the generator when there is no request (console, queues, mail). This
    | installation is served on more than one domain, and it was proved live:
    | the same server answered `<link rel="canonical" href="https://lapispro.com">`
    | on one host and `…href="https://lapis.criativatek.com">` on the other.
    | Two domains each declaring themselves canonical is duplicate content with
    | the ranking signal split between them.
    |
    | NOT APP_URL, deliberately. APP_URL is where transactional mail sends
    | people — verification, password resets, invitations — and that is a
    | different question with different consequences. They may hold the same
    | value; they must not be the same setting.
    |
    | Unset falls back to the request, which is the right answer for a local
    | install and for anything served on exactly one domain.
    |
    */

    'public_url' => env('LAPIS_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Responsável pelo tratamento (RGPD)
    |--------------------------------------------------------------------------
    |
    | Quem responde legalmente pelo tratamento de dados pessoais no Lapispro, e
    | para onde um titular escreve a exercer os seus direitos.
    |
    | SÃO DEFAULTS DO CONFIG, NÃO APENAS VARIÁVEIS DE AMBIENTE. Estiveram a
    | `null` enquanto ninguém os tinha confirmado, e a página mostrava «Por
    | definir» em vez de inventar um nome. Confirmados (2026-08-27), passam a
    | valores por omissão: são um facto sobre o produto, idêntico em todas as
    | instalações, e deixá-los só no `.env` significaria que produção mostraria
    | «Por definir» até alguém se lembrar de definir quatro variáveis — que é
    | exatamente a falha que isto existe para fechar. O `env()` mantém-se para
    | quem precise de os sobrepor.
    |
    | `LegalPagesTest` verifica que os valores em vigor são estes e não um
    | exemplo, e que a página nunca apresenta um placeholder como facto.
    |
    */

    'legal' => [

        'controller_name' => env('LAPIS_LEGAL_CONTROLLER_NAME', 'HORIZONLEVEL, LDA'),

        'controller_vat' => env('LAPIS_LEGAL_CONTROLLER_VAT', '513354166'),

        'controller_address' => env(
            'LAPIS_LEGAL_CONTROLLER_ADDRESS',
            'Rua do Verde Pinho, n.º 133, 2415-609 Leiria, Portugal',
        ),

        // Onde se exercem os direitos do titular. Distinto do contacto
        // comercial em `platform_settings.contact_email`, e distinto do
        // suporte: quem escreve sobre os seus dados não deve ter de passar
        // pela caixa de entrada geral.
        'privacy_email' => env('LAPIS_LEGAL_PRIVACY_EMAIL', 'privacidade@lapispro.com'),

        // Apoio à utilização do serviço.
        'support_email' => env('LAPIS_LEGAL_SUPPORT_EMAIL', 'suporte@lapispro.com'),

        // Conta e autenticação. Referido apenas onde há motivo real.
        'accounts_email' => env('LAPIS_LEGAL_ACCOUNTS_EMAIL', 'contas@lapispro.com'),

        // Datas de entrada em vigor de cada documento (YYYY-MM-DD). Escritas à
        // mão porque uma alteração ao texto legal é um ato deliberado, não algo
        // que deva mover-se sozinho a cada deploy.
        'terms_effective_from' => env('LAPIS_LEGAL_TERMS_DATE', '2026-08-27'),

        'privacy_effective_from' => env('LAPIS_LEGAL_PRIVACY_DATE', '2026-08-27'),

        // O Acordo de Tratamento de Dados — o professor como responsável, o
        // Lapispro como subcontratante dos dados dos alunos.
        'processing_effective_from' => env('LAPIS_LEGAL_PROCESSING_DATE', '2026-08-27'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Writing assistant
    |--------------------------------------------------------------------------
    |
    | The optional layer that REPHRASES text Lapispro already wrote. It is never a
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
    | `gemini` DOES name a company, and it is the first one that does. The
    | operator asked for it explicitly, which is what CLAUDE.md §31 requires
    | before a vendor may be picked at all. It is still not a DEFAULT: `driver`
    | below is null until somebody chooses, and everything the driver needs —
    | model, timeout, ceiling, key — is configuration, so switching to another
    | engine is a settings change and not a rewrite.
    |
    | THE KEY HAS TWO POSSIBLE HOMES AND ONLY TWO. The environment (here), and
    | `platform_settings.ai_api_key`, encrypted at the model layer and written
    | over this config at boot by AppServiceProvider — the same arrangement the
    | platform's SMTP password has used since it existed. It is never in the
    | frontend, never in a log line, never in an exception message, and never in
    | an audit row (§47 of the Relatórios brief; §4 and §9 of the AI Core brief).
    |
    | A SECRET MANAGER IS BETTER THAN EITHER, and where one is available in
    | production it should hold the key with `LAPIS_AI_KEY` injected from it at
    | deploy time — see docs/ai-core-contract.md. The encrypted column exists
    | because this installation has no secret manager today, not because a
    | database is the right place for a credential.
    |
    */

    'ai' => [

        // null | 'gemini' | 'chat-completions' | 'fake'. Null means the feature is off.
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

        /*
         | A HARD CEILING ON THE ANSWER, IN TOKENS.
         |
         | Distinct from `max_characters`, which limits what goes OUT. This
         | limits what may come back, and it is the only setting on this list
         | that is directly a bill: an engine with no output ceiling will
         | cheerfully answer a two-line question with two thousand lines.
         |
         | Expressed in tokens rather than characters because that is the unit
         | every engine's API actually accepts. `AiTextRequest::maxOutputCharacters`
         | stays as the caller's own preference for drivers that can express one;
         | this is the installation-wide floor under it that no caller can raise.
         */
        'max_output_tokens' => (int) env('LAPIS_AI_MAX_OUTPUT_TOKENS', 2048),

        /*
         | Where the Gemini driver posts.
         |
         | A BASE, NOT AN ENDPOINT: the model is part of the path
         | (`/models/{model}:generateContent`), so the URL cannot be a fixed
         | string the way `chat-completions` allows. Configurable so a regional
         | or proxied deployment can be pointed elsewhere without a code change.
         */
        'gemini' => [
            'base_url' => rtrim((string) env(
                'LAPIS_AI_GEMINI_BASE_URL',
                'https://generativelanguage.googleapis.com/v1beta',
            ), '/'),
        ],

        /*
        |----------------------------------------------------------------------
        | Technical spend ceilings, per capability
        |----------------------------------------------------------------------
        |
        | THESE ARE NOT THE COMMERCIAL QUOTA. They are the ceiling that stops a
        | loop, a stuck client or a bad afternoon from turning into an invoice —
        | the same job the rate limiter does per minute, done per day and per
        | month. How many AI requests a Base/Pro/Institucional subscription
        | INCLUDES is a commercial decision nobody has taken yet, and inventing a
        | number here would be taking it (CLAUDE.md §31 — «change the commercial
        | composition of the plans»).
        |
        | `AiQuota` therefore reads a per-plan override FIRST and falls back to
        | these; the override is unset on every seeded plan today, which is the
        | honest expression of «not yet decided». See docs/ai-core-contract.md.
        |
        | A NON-NUMERIC VALUE MEANS NO CEILING OF THAT KIND — an empty
        | environment variable is how an installation says «do not cap this»,
        | which is why these are not written as `(int) env(...)` like everything
        | above: `(int) ''` is 0, and 0 is a real, opposite instruction. Zero
        | means the capability is ceilinged shut, which is a valid way to turn
        | one off without touching the plans.
        |
        */
        'quotas' => [

            'help_assistant' => [
                'user_daily' => is_numeric($helpDaily = env('LAPIS_AI_HELP_USER_DAILY', 60))
                    ? (int) $helpDaily
                    : null,
                'organization_monthly' => is_numeric($helpMonthly = env('LAPIS_AI_HELP_ORGANIZATION_MONTHLY', 3000))
                    ? (int) $helpMonthly
                    : null,
            ],

            'ai_pedagogical_analysis' => [
                'user_daily' => is_numeric($analysisDaily = env('LAPIS_AI_PEDAGOGICAL_USER_DAILY', 40))
                    ? (int) $analysisDaily
                    : null,
                'organization_monthly' => is_numeric($analysisMonthly = env('LAPIS_AI_PEDAGOGICAL_ORGANIZATION_MONTHLY', 1500))
                    ? (int) $analysisMonthly
                    : null,
            ],

        ],

    ],

];
