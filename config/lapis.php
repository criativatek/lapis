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

        'privacy_effective_from' => env('LAPIS_LEGAL_PRIVACY_DATE', '2026-08-30'),

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

    /*
    |--------------------------------------------------------------------------
    | Central de Suporte — configuração operacional
    |--------------------------------------------------------------------------
    |
    | Para onde a Central encaminha as respostas e os avisos à equipa. É o
    | `Reply-To` de cada email que sai e o destinatário do aviso «entrou um
    | pedido novo».
    |
    | DISTINTO DE `legal.support_email`, mesmo apontando hoje para o mesmo
    | endereço — e um teste afirma que apontam. Um é OPERACIONAL: para onde a
    | aplicação encaminha. O outro é o canal que os Termos declaram. Fundi-los
    | faria com que trocar de ferramenta de suporte obrigasse a reescrever um
    | documento legal, que é a única coisa neste produto que não se muda com um
    | deploy.
    |
    | `platform_settings.support_email` NÃO é fonte desta funcionalidade e não é
    | lida em lado nenhum da Central. Sem fallback automático: um fallback
    | silencioso entre duas fontes é como se descobre, um ano depois, que os
    | emails saíam com a marca errada.
    |
    */

    'support' => [

        'inbox' => env('LAPIS_SUPPORT_INBOX', 'suporte@lapispro.com'),

    ],

    // Local e demonstração apenas: de quem é a conta que o `DemoDataSeeder`
    // enche. É um parâmetro porque a conta que precisa de dados para
    // experimentar nem sempre é a da professora fictícia — e vive aqui
    // porque `env()` fora de `config/` devolve null com a config em cache.
    'demo_teacher_email' => env('DEMO_TEACHER_EMAIL', 'ana.martins@lapis.test'),

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
         | every engine's API actually accepts.
         |
         | THE ONLY OUTPUT CEILING THERE IS, and every real driver reads it —
         | `maxOutputTokens` for Gemini, `max_tokens` on the `/chat/completions`
         | wire. `AiTextRequest` deliberately carries no ceiling of its own: one
         | a caller could express is one a caller could raise.
         */
        'max_output_tokens' => (int) env('LAPIS_AI_MAX_OUTPUT_TOKENS', 2048),

        /*
         | THE HARD CEILING ABOVE THE CEILING.
         |
         | `max_output_tokens` is the DEFAULT budget; this is the absolute
         | maximum any single call may be given, whatever else asks for more.
         | It exists because `AiUseCase::minimumOutputTokens()` may raise the
         | budget for a use case whose answer does not fit in the default — and
         | a raise with nothing above it is not a ceiling, which is the same
         | objection this file has always made to a caller-supplied one.
         |
         | So the arithmetic `AiGateway` does is closed on both sides:
         | `min(ceiling, max(default, use-case floor))`. An installation that
         | lowers this below `max_output_tokens` gets this number, because the
         | hard ceiling is the one that wins — that is what makes it hard.
         |
         | AND WHEN THIS IS SET BELOW A DECLARED MINIMUM, THE CALL REFUSES.
         | A use-case minimum is not a preference — it is the application
         | stating that below that number the answer cannot be produced at all.
         | Clamping it down therefore does not buy a cheaper answer; it buys
         | `truncated_answer`, deterministically, while the meter blames the
         | model for a setting on this line. Since 0.101.5 the gateway raises
         | `AiRequestFailed::misconfiguredBudget()` before the request is made,
         | so the contradiction is named where it was created. Setting this
         | below 3072 therefore turns OFF the síntese de acompanhamento — which
         | is a legitimate thing to want, and now a visible one.
         */
        'max_output_tokens_ceiling' => (int) env('LAPIS_AI_MAX_OUTPUT_TOKENS_CEILING', 8192),

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

            /*
             * Models to try, in order, when the configured one answers that it
             * cannot serve this request — 404 (retired), 429 (quota) or 503
             * (capacity). Comma-separated; empty means no fallback and the
             * failure is reported as it always was.
             *
             * WHY THIS EXISTS. On 2026-09-01 Google retired `gemini-2.5-flash`
             * mid-service and the whole `flash` tier then spent the evening on
             * 503. Every one of those answers came back in under a second and
             * said, in effect, «not me, not now» — which is a statement about
             * one model, not about the request. The application had one model
             * and no second thought, so a teacher pressing the button got
             * nothing for hours.
             *
             * WHY IT IS EMPTY BY DEFAULT, and not a helpful list. A default
             * here is a model name frozen into the repository, and the whole
             * lesson of that day is that model names rot without warning. An
             * installation states what it has verified; nothing is inherited
             * from whoever wrote this file.
             */
            'fallback_models' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('LAPIS_AI_GEMINI_FALLBACK_MODELS', '')),
            ), static fn (string $model): bool => $model !== '')),
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
        | INCLUDES is a commercial decision that belongs in `plans.limits`,
        | where it can change without a deploy; a number written here would be
        | a product rule frozen in a config file (CLAUDE.md §31 — «change the
        | commercial composition of the plans»).
        |
        | `AiQuota` therefore reads a per-plan override FIRST
        | (`plans.limits['ai_quota'][capability][window]`) and falls back to
        | these. The override is unset on every seeded plan today, which is the
        | honest expression of «the technical ceiling is all that applies so
        | far». See docs/ai-core-contract.md.
        |
        | A NON-NUMERIC VALUE MEANS NO CEILING OF THAT KIND — an empty
        | environment variable is how an installation says «do not cap this»,
        | which is why these are not written as `(int) env(...)` like everything
        | above: `(int) ''` is 0, and 0 is a real, opposite instruction. Zero
        | means the capability is ceilinged shut, which is a valid way to turn
        | one off without touching the plans.
        |
        | THE NUMBERS BELOW ARE ORDERS OF MAGNITUDE, NOT PRODUCT FIGURES. They
        | are set relative to how expensive and how repeatable each capability
        | is: the help assistant is cheap and asked often, an analysis is dearer
        | and asked once per class per period, a rewrite is per paragraph. Any
        | of them can be changed in the backoffice, in `.env`, or overridden by
        | a plan, and none of them should ever be quoted as what a plan includes.
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

            'ai_assessment' => [
                'user_daily' => is_numeric($assessmentDaily = env('LAPIS_AI_ASSESSMENT_USER_DAILY', 40))
                    ? (int) $assessmentDaily
                    : null,
                'organization_monthly' => is_numeric($assessmentMonthly = env('LAPIS_AI_ASSESSMENT_ORGANIZATION_MONTHLY', 1500))
                    ? (int) $assessmentMonthly
                    : null,
            ],

            'ai_followup' => [
                'user_daily' => is_numeric($followupDaily = env('LAPIS_AI_FOLLOWUP_USER_DAILY', 40))
                    ? (int) $followupDaily
                    : null,
                'organization_monthly' => is_numeric($followupMonthly = env('LAPIS_AI_FOLLOWUP_ORGANIZATION_MONTHLY', 1500))
                    ? (int) $followupMonthly
                    : null,
            ],

            'ai_strategies' => [
                'user_daily' => is_numeric($strategiesDaily = env('LAPIS_AI_STRATEGIES_USER_DAILY', 40))
                    ? (int) $strategiesDaily
                    : null,
                'organization_monthly' => is_numeric($strategiesMonthly = env('LAPIS_AI_STRATEGIES_ORGANIZATION_MONTHLY', 1500))
                    ? (int) $strategiesMonthly
                    : null,
            ],

            'ai_reports' => [
                'user_daily' => is_numeric($reportsDaily = env('LAPIS_AI_REPORTS_USER_DAILY', 60))
                    ? (int) $reportsDaily
                    : null,
                'organization_monthly' => is_numeric($reportsMonthly = env('LAPIS_AI_REPORTS_ORGANIZATION_MONTHLY', 2000))
                    ? (int) $reportsMonthly
                    : null,
            ],

        ],

        /*
        |----------------------------------------------------------------------
        | The institutional pool
        |----------------------------------------------------------------------
        |
        | A SECOND, WIDER CEILING FOR ONE KIND OF CUSTOMER. Where `quotas` above
        | are per capability, this is the organization's TOTAL across all of
        | them, plus an optional per-member share of that total. It applies only
        | to an organization holding `ai_institutional_pool` — see
        | `AiQuota::poolApplies()`.
        |
        | BOTH DEFAULTS ARE NULL, AND THAT IS THE DECISION. An institutional
        | contract's plafond is a commercial figure that belongs in that plan's
        | `limits['ai_pool']`, negotiated per contract; a default here would
        | invent one and would silently apply it to every Institucional
        | organization. Null means the mechanism is built, tested and inert
        | until a contract sets a number — «preparado para», which is what §18
        | and §20 of the brief ask for, rather than «imposto».
        |
        | The shape a contract writes into `plans.limits` is:
        |
        |     {"ai_pool": {"organization_monthly": 10000, "user_monthly": 400}}
        |
        | `user_monthly` may be omitted, and usually should be: a pool with no
        | individual ceiling is the simpler instrument, and the individual one
        | exists for the school that has been asked for it.
        |
        */
        'pool' => [
            'organization_monthly' => is_numeric($poolMonthly = env('LAPIS_AI_POOL_ORGANIZATION_MONTHLY', ''))
                ? (int) $poolMonthly
                : null,
            'user_monthly' => is_numeric($poolUserMonthly = env('LAPIS_AI_POOL_USER_MONTHLY', ''))
                ? (int) $poolUserMonthly
                : null,
        ],

    ],

];
