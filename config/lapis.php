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
