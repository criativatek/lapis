<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAiCredentialRequest;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiAsk;
use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiGateway;
use App\Services\Ai\Gateway\AiQuota;
use App\Services\Ai\Gateway\AiUsageSummary;
use App\Services\Ai\Gateway\AiUseCase;
use App\Services\Audit\AuditLog;
use App\Services\Diagnostics\Ai\AiCapabilityProbe;
use App\Support\Privacy\AiPayloadSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Inteligência Artificial. The SaaS operator's switch, dial and key.
 *
 * WHAT LEAVES THIS CONTROLLER TOWARDS THE BROWSER, EXHAUSTIVELY: whether a
 * credential exists, when it was stored, its last four characters, and every
 * non-secret setting. THE CREDENTIAL ITSELF NEVER DOES — not on first render,
 * not after saving it, not in a validation error, not in flashed old input
 * (`StoreAiCredentialRequest::$dontFlash`), not in an audit row. The only read
 * path for the value is `PlatformSetting::aiCredential()`, and its only caller
 * is `AppServiceProvider`, putting it into config for the request (§4).
 *
 * THE ACTION IS «SUBSTITUIR CREDENCIAL», NEVER «MOSTRAR CHAVE». There is no
 * route that returns it, so there is nothing for a compromised admin session, a
 * browser extension or a screen recording to take. An operator who has lost the
 * key gets a new one from the vendor; that is a smaller problem than a product
 * that can be asked to display secrets.
 *
 * THE STATE IS COMPUTED, NOT STORED. «IA ativa» on the screen is not the
 * `ai_enabled` column — it is `AiTextProviders::isConfigured()`, which is the
 * same answer every feature gets. An operator who ticks the box without a
 * credential is told the engine is still unavailable and why, instead of being
 * shown a green light that no teacher's screen agrees with (§4).
 *
 * FOUR WRITES, FOUR AUDIT EVENTS, ALL PLATFORM-SCOPED. Settings, credential
 * created, credential replaced, credential removed — plus the connection test.
 * They go through `AuditLog::recordPlatform()` because turning the engine on is
 * an act of the operator that affects every tenant and belongs to none (§9).
 */
class AdminAiController extends Controller
{
    public function __construct(
        protected AiTextProviders $providers,
        protected AuditLog $audit,
        protected AiUsageSummary $usage,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/Ai', [
            'settings' => $this->settingsPayload(PlatformSetting::current()),
            'status' => $this->statusPayload(),
            'providers' => $this->providerOptions(),
            'capabilities' => $this->capabilityOptions(),
            // The meter, for the whole installation, since the start of the
            // month — the same window the organization quotas are counted in,
            // so a figure here and a ceiling on the same screen are
            // comparable. Closes ADR-0006 §«Dívidas registadas» 3: the token
            // counters have been written since the first day and until now
            // nothing read them.
            'usage' => $this->usage->platform(now()->startOfMonth()),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $settings = PlatformSetting::current();

        $settings->fill($request->validated());

        // Read BEFORE save: after it, everything is clean and the trail would
        // say a change happened without saying what changed.
        $changed = array_keys($settings->getDirty());

        $settings->save();

        if ($changed !== []) {
            $this->audit->recordPlatform(
                'ai.settings_updated',
                $request->user(),
                summary: 'Configuração de IA alterada ('.implode(', ', $changed).').',
                properties: [
                    'fields' => $changed,
                    // The VALUES of these are safe and useful — a trail that
                    // records «somebody changed the model» without saying to
                    // what answers nothing. `ai_api_key` is not fillable and so
                    // can never be among them; the assertion is structural, not
                    // a filter somebody has to remember to keep correct.
                    'values' => $settings->only($changed),
                ],
            );
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Definições de IA guardadas.')]);

        return back();
    }

    /**
     * Store a credential — the first one, or a replacement.
     *
     * WHICH OF THE TWO IT WAS IS DECIDED BEFORE THE WRITE, because afterwards
     * the two states are indistinguishable and the distinction is the one thing
     * a reader of the trail actually wants («when did this key change?»).
     */
    public function storeCredential(StoreAiCredentialRequest $request): RedirectResponse
    {
        $settings = PlatformSetting::current();

        $replacing = $settings->aiCredentialConfigured();

        $settings->storeAiCredential($request->credential());

        $this->audit->recordPlatform(
            $replacing ? 'ai.credential_replaced' : 'ai.credential_created',
            $request->user(),
            summary: $replacing
                ? 'Credencial de IA substituída.'
                : 'Credencial de IA configurada.',
            // NO `properties`. Not the key, not its length, not its first or
            // last characters, not a hash of it. A hash of a credential is a
            // credential an attacker can confirm a guess against, and there is
            // no question worth answering that needs one.
            properties: ['provider' => $settings->ai_provider],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credencial guardada.')]);

        return back();
    }

    public function destroyCredential(Request $request): RedirectResponse
    {
        $settings = PlatformSetting::current();

        if (! $settings->aiCredentialConfigured()) {
            return back();
        }

        $settings->storeAiCredential(null);

        $this->audit->recordPlatform(
            'ai.credential_removed',
            $request->user(),
            summary: 'Credencial de IA removida.',
            properties: ['provider' => $settings->ai_provider],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Credencial removida. A IA fica indisponível até configurar outra.')]);

        return back();
    }

    /**
     * Prove the credential works, against the real endpoint.
     *
     * IT GOES THROUGH `AiGateway` LIKE EVERYTHING ELSE, with the one use case
     * that has no capability behind it — so the connection test is measured in
     * `ai_usage_events` (it costs real tokens), is subject to the same privacy
     * check as any other payload, and cannot become a back door that talks to a
     * provider directly. Its authorization is this route's `platform-admin`
     * middleware, because the person running the SaaS is not a customer of it.
     *
     * IT COSTS NO TENANT ANYTHING. The row it writes has no organization, so it
     * counts against no school's quota.
     *
     * THE OPERATOR IS TOLD WHICH FAILURE IT WAS — this is the one screen where
     * `AiRequestFailed::category()` is surfaced. «unauthorized» is exactly what
     * somebody debugging a key needs and exactly what a teacher must never see,
     * which is why the category exists separately from the public message.
     */
    public function test(Request $request, AiGateway $gateway, AiPayloadSanitizer $sanitizer): RedirectResponse
    {
        $ask = new AiAsk(
            useCase: AiUseCase::AdminConnectionTest,
            instruction: 'Responde exclusivamente com a palavra OK, sem pontuação e sem qualquer outro texto.',
            // Sanitised like any other payload even though this application
            // wrote every word of it. The exception would be the precedent.
            content: $sanitizer->sanitise('Teste de ligação.'),
            promptVersion: 'lapis-connection-test/1',
        );

        try {
            $answer = $gateway->ask($ask, $request->user());
        } catch (AiUnavailable $exception) {
            return $this->testFailed($request, $exception->reason(), $exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            // The technical reason goes to the log where it is useful; neither
            // it nor the log line carries the endpoint or the key.
            report($exception);

            return $this->testFailed($request, $exception->category(), $this->explain($exception->category()));
        }

        $this->audit->recordPlatform(
            'ai.connection_tested',
            $request->user(),
            summary: 'Teste de ligação à IA bem-sucedido.',
            properties: [
                'outcome' => 'succeeded',
                'provider' => $answer->provider,
                'model' => $answer->model,
                'input_tokens' => $answer->inputTokens,
                'output_tokens' => $answer->outputTokens,
                'duration_ms' => $answer->durationMilliseconds,
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Ligação estabelecida com :provider (:model).', [
                'provider' => $answer->provider,
                'model' => $answer->model,
            ]),
        ]);

        return back();
    }

    /**
     * Prove the configured MODEL can do the work, not merely that the key opens
     * the door.
     *
     * THE SECOND BUTTON, AND THE REASON IT EXISTS. «Testar ligação» asks for the
     * word «OK» and passed continuously through an outage in which every
     * síntese de acompanhamento failed — because a three-token answer proves a
     * credential and nothing else. This sends the feature's real instruction
     * over a synthetic record and requires a genuinely usable six-section answer
     * back, which is the shape of the work and the shape that runs out of
     * budget. See `AiCapabilityProbe`.
     *
     * SYNTHETIC, FIXED, AND CHECKED IN. There is no student, no teacher and no
     * organization anywhere in what it sends — the operator running it is not
     * inside a tenant, and there is nothing here that would need to be.
     *
     * ITS OWN USE CASE, so the meter can tell the two diagnostics apart: this
     * one costs real output tokens where «OK» costs three, and an operator
     * reading `ai_usage_events` should not have to guess which was which.
     */
    public function probe(Request $request, AiGateway $gateway, AiPayloadSanitizer $sanitizer): RedirectResponse
    {
        $ask = new AiAsk(
            useCase: AiUseCase::AdminCapabilityProbe,
            instruction: AiCapabilityProbe::instruction(),
            // Sanitised like every other payload. It has nothing in it to
            // remove, and it goes through anyway: the exception would be the
            // precedent.
            content: $sanitizer->sanitise(AiCapabilityProbe::content()),
            promptVersion: AiCapabilityProbe::VERSION,
        );

        try {
            $answer = $gateway->ask($ask, $request->user());
        } catch (AiUnavailable $exception) {
            return $this->probeFailed($request, $exception->reason(), $exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            return $this->probeFailed($request, $exception->category(), $this->explain($exception->category()));
        }

        // THE ANSWER ARRIVED AND STILL MAY NOT BE USABLE, which is the whole
        // point of running the real parser rather than checking for a 200. A
        // model that answers fluently in the wrong shape fails here, and the
        // operator is told that rather than being told everything is fine.
        if (! AiCapabilityProbe::isUsable($answer->text)) {
            return $this->probeFailed(
                $request,
                'unparsable_answer',
                $this->explain('unparsable_answer'),
            );
        }

        $this->audit->recordPlatform(
            'ai.capability_probed',
            $request->user(),
            summary: 'Teste de capacidade da IA bem-sucedido.',
            properties: [
                'outcome' => 'succeeded',
                'provider' => $answer->provider,
                'model' => $answer->model,
                'prompt_version' => AiCapabilityProbe::VERSION,
                // Dimensions, never the answer. See `AiCapabilityProbe`.
                'sections' => AiCapabilityProbe::sectionsFound($answer->text),
                'input_tokens' => $answer->inputTokens,
                'output_tokens' => $answer->outputTokens,
                'duration_ms' => $answer->durationMilliseconds,
            ],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('O modelo :model produziu uma resposta utilizável, com :sections de 6 secções.', [
                'model' => $answer->model,
                'sections' => AiCapabilityProbe::sectionsFound($answer->text),
            ]),
        ]);

        return back();
    }

    protected function probeFailed(Request $request, string $category, string $message): RedirectResponse
    {
        $this->audit->recordPlatform(
            'ai.capability_probed',
            $request->user(),
            summary: 'Teste de capacidade da IA falhou ('.$category.').',
            properties: ['outcome' => 'failed', 'error_category' => $category],
        );

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }

    protected function testFailed(Request $request, string $category, string $message): RedirectResponse
    {
        $this->audit->recordPlatform(
            'ai.connection_tested',
            $request->user(),
            summary: 'Teste de ligação à IA falhou ('.$category.').',
            properties: ['outcome' => 'failed', 'error_category' => $category],
        );

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }

    /**
     * An operator-facing sentence for a failure category.
     *
     * DELIBERATELY MORE SPECIFIC THAN `AiRequestFailed::publicMessage()`, which
     * is written for a teacher. This screen is behind `platform-admin` and its
     * whole purpose is to say what is wrong.
     */
    protected function explain(string $category): string
    {
        return match ($category) {
            'unauthorized' => __('O fornecedor rejeitou a credencial. Verifique a chave e as permissões do projeto.'),
            'rate_limited' => __('O fornecedor está a limitar os pedidos. Tente novamente dentro de instantes.'),
            'timeout' => __('O fornecedor não respondeu dentro do tempo configurado.'),
            'unreachable' => __('Não foi possível contactar o fornecedor. Verifique a rede e o endereço configurado.'),
            'provider_error' => __('O fornecedor devolveu um erro interno. O problema é do lado dele.'),
            'unusable_answer' => __('O fornecedor respondeu, mas a resposta não era utilizável. A ligação funciona; o modelo ou os limites podem não servir.'),
            // THE ONE CATEGORY ON THIS LIST WITH A SETTING BEHIND IT. Worded so
            // that the operator is pointed at the number, because on the Gemini
            // 2.5 line the budget is spent on the model's own reasoning before
            // any of it reaches the answer — which is how a working credential
            // and a working endpoint still produce nothing.
            'truncated_answer' => __('O fornecedor ficou sem orçamento de resposta antes de terminar. A ligação e a credencial funcionam; aumente o limite de tokens de saída ou escolha um modelo com menos raciocínio interno.'),
            'unparsable_answer' => __('O fornecedor respondeu por inteiro, mas a resposta não tinha o formato que a aplicação precisa. A ligação funciona; o modelo pode não ser adequado a esta funcionalidade.'),
            default => __('Não foi possível concluir o teste de ligação.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(PlatformSetting $settings): array
    {
        return [
            // Cast, because `PlatformSetting::current()` may have just INSERTed
            // this row and Laravel does not read a column default back into the
            // model it created. The screen must be told false, not null: a
            // tri-state checkbox is a bug report waiting to be written.
            'ai_enabled' => (bool) $settings->ai_enabled,
            'ai_provider' => $settings->ai_provider,
            'ai_model' => $settings->ai_model,
            'ai_timeout_seconds' => $settings->ai_timeout_seconds,
            'ai_max_output_tokens' => $settings->ai_max_output_tokens,
            'ai_per_minute' => $settings->ai_per_minute,
            'ai_organization_per_minute' => $settings->ai_organization_per_minute,
            'ai_quotas' => $this->quotasPayload($settings),

            // The three facts about the credential that may be known outside
            // this process. Never a fourth.
            'credential_set' => $settings->aiCredentialConfigured(),
            'credential_hint' => $settings->aiCredentialHint(),
            'credential_set_at' => $settings->ai_credential_set_at?->toIso8601String(),
        ];
    }

    /**
     * What is ACTUALLY in force, which is not always what is stored: a null
     * column falls through to config, which falls through to the environment.
     * An operator looking at a blank «timeout» field needs to see the 20 that is
     * really being used, or they will assume there is none.
     *
     * @return array<string, mixed>
     */
    protected function statusPayload(): array
    {
        return [
            'available' => $this->providers->isConfigured(),
            'unavailable_reason' => $this->providers->unavailableReason(),
            'effective' => [
                'driver' => config('lapis.ai.driver'),
                'model' => config('lapis.ai.model'),
                'timeout' => (int) config('lapis.ai.timeout'),
                // TWO NUMBERS, BECAUSE THEY MEAN DIFFERENT THINGS AND THE
                // OPERATOR IS ENTITLED TO BOTH. `max_output_tokens` is the
                // DEFAULT budget a call gets; a use case that declares it needs
                // more may raise it (`AiUseCase::minimumOutputTokens()`).
                // `max_output_tokens_ceiling` is the number nothing may exceed.
                // Showing only the first and calling it «máximo» was untrue on
                // exactly the screen where an operator goes to find out what the
                // limit is — see the field labels in admin/Ai.vue.
                'max_output_tokens' => (int) config('lapis.ai.max_output_tokens'),
                'max_output_tokens_ceiling' => (int) config('lapis.ai.max_output_tokens_ceiling'),
                'per_minute' => (int) config('lapis.ai.per_minute'),
                'organization_per_minute' => (int) config('lapis.ai.organization_per_minute'),
                // NOT `key`. There is no shape of this payload that carries it.
            ],
        ];
    }

    /**
     * Stored quotas, filled out for every METERED capability so the form has a
     * field for each — an absent capability would silently be unconfigurable.
     *
     * NOT `AiCapability::cases()`. `ai_governance` and `ai_institutional_pool`
     * never reach an engine, so a ceiling on either would be a control that
     * does nothing (`AiCapability::isMetered()`).
     *
     * A FIELD LEFT BLANK BY THE OPERATOR SHOWS WHAT CONFIG IS REALLY APPLYING,
     * not an empty box. A blank that silently means «60» reads as «no ceiling»,
     * which is the opposite of the truth and the kind of thing somebody finds
     * out from an invoice.
     *
     * THE POOL RIDES IN THE SAME MAP, under `AiQuota::POOL_LIMIT_KEY`. It is
     * not a capability — it is a ceiling ACROSS capabilities — but it is stored
     * in the same JSON column and read back from the same place, so the form
     * that edits one edits the other without a second endpoint. See
     * `AppServiceProvider::applyPlatformAiSettings()` for the reserved key.
     *
     * @return array<string, array{user_daily: int|null, organization_monthly: int|null, user_monthly: int|null}>
     */
    protected function quotasPayload(PlatformSetting $settings): array
    {
        $stored = $settings->ai_quotas ?? [];
        $payload = [];

        foreach (AiCapability::metered() as $capability) {
            $payload[$capability->value] = [
                'user_daily' => $this->effectiveQuota($stored, $capability->value, 'user_daily', 'lapis.ai.quotas.'.$capability->value.'.user_daily'),
                'organization_monthly' => $this->effectiveQuota($stored, $capability->value, 'organization_monthly', 'lapis.ai.quotas.'.$capability->value.'.organization_monthly'),
                // Not meaningful for a capability; present so the form's shape
                // is uniform and the pool's own row is not a special case in
                // the template.
                'user_monthly' => null,
            ];
        }

        $payload[AiQuota::POOL_LIMIT_KEY] = [
            'user_daily' => null,
            'organization_monthly' => $this->effectiveQuota($stored, AiQuota::POOL_LIMIT_KEY, 'organization_monthly', 'lapis.ai.pool.organization_monthly'),
            'user_monthly' => $this->effectiveQuota($stored, AiQuota::POOL_LIMIT_KEY, 'user_monthly', 'lapis.ai.pool.user_monthly'),
        ];

        return $payload;
    }

    /**
     * What is stored, or what config is actually applying when nothing is.
     *
     * @param  array<string, mixed>  $stored
     */
    protected function effectiveQuota(array $stored, string $key, string $window, string $configKey): ?int
    {
        $value = data_get($stored, $key.'.'.$window);

        if (! is_int($value)) {
            $value = config($configKey);
        }

        return is_int($value) ? $value : null;
    }

    /**
     * The capability catalogue: what each one is, where a teacher meets it, and
     * which plans include it.
     *
     * `plans` REPLACED `granted_by_no_plan`, AND THE REPLACEMENT IS THE POINT.
     * The old flag existed because the commercial composition was undecided and
     * every AI capability belonged to no plan — so the only useful thing to say
     * was «nobody has this». The Matriz Mestre has since decided, every
     * capability is in a plan, and the flag would now be permanently false: a
     * warning that can never fire, occupying the space where the actual answer
     * belongs. What an operator needs on this screen is «Base · Pro ·
     * Institucional», read from the database rather than transcribed, so a
     * seeder change shows up here without anybody editing a template.
     *
     * `where` IS FOR THE SAME READER. «IA no acompanhamento do aluno» is not a
     * page name, and an operator should not have to grep the source to find out
     * which screen they just capped.
     *
     * EVERY CAPABILITY IS LISTED, metered or not. Governance and the pool have
     * no quota fields, but an operator still needs to see which plans include
     * them — they are commercial facts like any other.
     *
     * @return list<array{value: string, label: string, where: string, metered: bool, plans: list<string>}>
     */
    protected function capabilityOptions(): array
    {
        // WHICH PLANS SELL IT TODAY — the CURRENT published version of each,
        // never a historical one (ADR-0008). An operator setting a quota is
        // looking at the catalogue as it stands, not at what Pro v1 carried.
        // One query for the three plans rather than one per capability.
        /** @var array<string, list<string>> $plansByModule */
        $plansByModule = [];

        foreach (Plan::with('currentVersion.modules')->orderBy('sort_order')->get() as $plan) {
            $version = $plan->currentVersionOrNull();

            if ($version === null) {
                continue;
            }

            foreach ($version->modules as $module) {
                $plansByModule[$module->key][] = (string) $plan->name;
            }
        }

        return array_map(fn (AiCapability $capability): array => [
            'value' => $capability->value,
            'label' => $capability->label(),
            'where' => $capability->whereItLives(),
            'metered' => $capability->isMetered(),
            'plans' => $plansByModule[$capability->value] ?? [],
        ], AiCapability::cases());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    protected function providerOptions(): array
    {
        $options = [
            ['value' => 'gemini', 'label' => 'Google Gemini'],
            ['value' => 'chat-completions', 'label' => 'Compatível com /chat/completions'],
        ];

        if (! app()->isProduction()) {
            $options[] = ['value' => 'fake', 'label' => 'Simulado (apenas desenvolvimento)'];
        }

        return $options;
    }
}
