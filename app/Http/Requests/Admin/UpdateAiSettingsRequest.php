<?php

namespace App\Http\Requests\Admin;

use App\Services\Ai\Gateway\AiCapability;
use App\Services\Ai\Gateway\AiQuota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The AI engine's configuration, as an operator types it.
 *
 * THE CREDENTIAL IS NOT IN THIS REQUEST AT ALL, and that is the design rather
 * than an omission (§4 of the AI Core brief). Storing a key is a separate route,
 * a separate action and a separate audit event, because it is a separate kind of
 * act: changing a model is a settings change an operator makes casually and
 * often; putting a credential into the system is a thing that happens twice in
 * the life of an installation and has to be visible in the trail both times.
 * Keeping them apart also means the ordinary «guardar» round-trip has no field
 * anywhere in it that could carry a secret — not in the request, not in
 * validation errors, not in old input flashed back to the session.
 *
 * `provider` IS VALIDATED AGAINST A LIST, not accepted as free text. An unknown
 * driver resolves to «unavailable» anyway (AiTextProviders), so this is not the
 * security boundary — it is so an operator gets a field error instead of a
 * screen that says the engine is off for no visible reason.
 *
 * `fake` IS OFFERABLE OUTSIDE PRODUCTION ONLY. It is how a developer gets the
 * whole flow without an account anywhere; on a live installation it would look
 * exactly like a feature that works, so it is not even a valid value there.
 *
 * NULLABLE MEANS «FALL BACK TO THE ENVIRONMENT». A blank timeout is not zero: it
 * is the operator declining to decide here, and the stored null lets
 * config/lapis.php answer instead.
 */
class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ai_enabled' => ['required', 'boolean'],

            'ai_provider' => ['nullable', Rule::in($this->availableProviders())],

            // Long enough for any vendor's naming scheme, short enough that the
            // column holds it. Never used to build a shell command; it does
            // become a URL path segment, and GeminiProvider escapes it there.
            'ai_model' => ['nullable', 'string', 'max:64'],

            // Seconds. Floor of 1 because zero means «no timeout» to most HTTP
            // clients, which is the opposite of what an operator typing 0 wants.
            // Ceiling of 120 because a teacher waiting two minutes on a
            // suggestion has already given up, and PHP's own limits are near.
            'ai_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],

            // The one setting that is directly a bill. The ceiling is generous
            // and deliberate: it is a guard against a typed zero-too-many, not a
            // product decision.
            'ai_max_output_tokens' => ['nullable', 'integer', 'min:64', 'max:32768'],

            'ai_per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
            'ai_organization_per_minute' => ['nullable', 'integer', 'min:1', 'max:6000'],

            // Per capability, per window. Null is a real value here and means
            // «no ceiling of this kind» — see config/lapis.php — so `nullable`
            // is load-bearing rather than lenient.
            //
            // THE KEYS ARE CLOSED, and were not before. This column is written
            // straight over `config('lapis.ai.quotas')` at boot, so an
            // unrecognised key used to become a config entry nothing reads —
            // silent, permanent, and invisible on the screen. The list is
            // derived from the enum rather than typed out, so a capability
            // added to `AiCapability` becomes configurable the same day.
            'ai_quotas' => ['nullable', 'array:'.implode(',', $this->configurableQuotaKeys())],
            'ai_quotas.*.user_daily' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'ai_quotas.*.organization_monthly' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            // The pool's own second window — the ceiling one member may take out
            // of the organization's plafond. Only meaningful under `ai_pool`;
            // harmless elsewhere, because `AppServiceProvider` reads the pool
            // windows only out of the pool key.
            'ai_quotas.*.user_monthly' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * The top-level keys `ai_quotas` may contain: every METERED capability,
     * plus the reserved pool key.
     *
     * NOT `AiCapability::cases()`. `ai_governance` and `ai_institutional_pool`
     * never reach an engine, so a ceiling on either would be a number nothing
     * could ever be compared against — see `AiCapability::isMetered()`.
     *
     * @return list<string>
     */
    protected function configurableQuotaKeys(): array
    {
        return [
            ...array_map(fn (AiCapability $capability): string => $capability->value, AiCapability::metered()),
            AiQuota::POOL_LIMIT_KEY,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_provider.in' => 'O fornecedor indicado não existe nesta instalação.',
        ];
    }

    /**
     * @return list<string>
     */
    protected function availableProviders(): array
    {
        $providers = ['gemini', 'chat-completions'];

        if (! app()->isProduction()) {
            $providers[] = 'fake';
        }

        return $providers;
    }
}
