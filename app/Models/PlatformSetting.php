<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * The single platform settings row. Not tenant-scoped — it is the SaaS itself.
 *
 * @property int $id
 * @property string $mail_mailer
 * @property string|null $mail_host
 * @property int $mail_port
 * @property string|null $mail_username
 * @property string|null $mail_password
 * @property string|null $mail_encryption
 * @property string|null $mail_from_address
 * @property string|null $mail_from_name
 * @property string|null $contact_email
 * @property bool $ai_enabled
 * @property string|null $ai_provider
 * @property string|null $ai_model
 * @property int|null $ai_timeout_seconds
 * @property int|null $ai_max_output_tokens
 * @property int|null $ai_per_minute
 * @property int|null $ai_organization_per_minute
 * @property array<array-key, mixed>|null $ai_quotas The shape callers WRITE is
 *                                                   {capability: {window: int|null}}. This is a JSON
 *                                                   column, so what a reader gets back is whatever was
 *                                                   last stored — and `json_decode` turns a numeric
 *                                                   key into an int. Typed honestly so readers keep
 *                                                   having to check, rather than typed aspirationally
 *                                                   so the checks look redundant.
 * @property string|null $ai_api_key
 * @property Carbon|null $ai_credential_set_at
 */
#[Fillable([
    'mail_mailer', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
    'mail_encryption', 'mail_from_address', 'mail_from_name', 'contact_email',
    // `ai_api_key` is DELIBERATELY ABSENT from this list. It is only ever
    // written through `storeAiCredential()` below, which is the one place that
    // also stamps `ai_credential_set_at` — so the two can never disagree, and a
    // stray `fill()` from a validated request can never touch a credential.
    'ai_enabled', 'ai_provider', 'ai_model', 'ai_timeout_seconds', 'ai_max_output_tokens',
    'ai_per_minute', 'ai_organization_per_minute', 'ai_quotas',
])]
class PlatformSetting extends Model
{
    /**
     * How much of the credential the backoffice is allowed to show, and the
     * shortest key for which showing it is defensible.
     *
     * FOUR CHARACTERS OF A KEY THAT IS AT LEAST TWENTY. The operator who set it
     * needs to tell «the key I pasted» from «some other key», and a stored
     * secret nobody can identify gets replaced blindly at the first sign of
     * trouble. Four trailing characters of a forty-character key does not
     * meaningfully narrow anybody's search; four of an eight-character one
     * does, so short values show nothing at all rather than half of themselves.
     */
    private const HINT_LENGTH = 4;

    private const HINT_MINIMUM_KEY_LENGTH = 20;

    protected function casts(): array
    {
        return [
            'mail_password' => 'encrypted',
            'mail_port' => 'integer',
            'ai_api_key' => 'encrypted',
            'ai_enabled' => 'boolean',
            'ai_quotas' => 'array',
            'ai_timeout_seconds' => 'integer',
            'ai_max_output_tokens' => 'integer',
            'ai_per_minute' => 'integer',
            'ai_organization_per_minute' => 'integer',
            'ai_credential_set_at' => 'datetime',
        ];
    }

    /** The one-and-only settings row, created (with DB defaults) on first read. */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }

    public function mailConfigured(): bool
    {
        return filled($this->mail_host);
    }

    /**
     * The address the public site invites people to write to, or null.
     *
     * NEVER FALLS BACK TO `mail_from_address`: that one is the system sender,
     * usually a no-reply nobody reads. An unanswered mailbox is worse than no
     * call to action, so the landing page is told the truth — nothing — and
     * renders accordingly.
     */
    public function publicContactEmail(): ?string
    {
        return filled($this->contact_email) ? $this->contact_email : null;
    }

    /**
     * Whether a credential is stored — WITHOUT decrypting it.
     *
     * `getRawOriginal`, not `filled($this->ai_api_key)`, for the same reason
     * AppServiceProvider decrypts the SMTP password by hand: reading through the
     * cast throws when the ciphertext no longer matches APP_KEY (rotated key, or
     * a row restored from another environment's database), and «is there a
     * credential» must be answerable on a screen that is about to tell the
     * operator to replace it.
     */
    public function aiCredentialConfigured(): bool
    {
        return filled($this->getRawOriginal('ai_api_key'));
    }

    /**
     * The last few characters of the credential, or null.
     *
     * NULL IS RETURNED RATHER THAN A GUESS in all three of the cases where the
     * honest answer is nothing: no credential, a credential too short to reveal
     * any part of safely, and a credential that no longer decrypts. The last one
     * is the interesting one — the screen then shows «configurada» with no hint,
     * which is exactly what is true, and «substituir credencial» is the action
     * that fixes it.
     */
    public function aiCredentialHint(): ?string
    {
        $stored = $this->getRawOriginal('ai_api_key');

        if (! filled($stored)) {
            return null;
        }

        try {
            $key = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }

        return mb_strlen($key) >= self::HINT_MINIMUM_KEY_LENGTH
            ? mb_substr($key, -self::HINT_LENGTH)
            : null;
    }

    /**
     * The stored credential in the clear, or null when there is none or it no
     * longer decrypts.
     *
     * THE ONLY READ PATH, and it exists so there is exactly one. It is called
     * from AppServiceProvider (to put the key into config for the request) and
     * from nowhere else — no controller, no Inertia payload, no log line, no
     * audit row. A caller that wants to know whether a credential EXISTS calls
     * `aiCredentialConfigured()` instead and never touches the value.
     */
    public function aiCredential(): ?string
    {
        $stored = $this->getRawOriginal('ai_api_key');

        if (! filled($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $exception) {
            // Reported, not thrown: an unreadable credential turns the engine
            // off, it does not take the application down. The operator has to
            // re-enter it from /admin/ai, and this is how they find out.
            report($exception);

            return null;
        }
    }

    /**
     * Store a credential, stamping when.
     *
     * THE ONLY WRITE PATH. `ai_api_key` is not fillable, so this method and the
     * `encrypted` cast behind it are the whole surface — which is what makes
     * «the credential is always encrypted» a property of the model rather than a
     * habit of its callers.
     *
     * An empty value REMOVES the credential rather than storing an empty string,
     * because an empty string is a credential that fails 401 rather than a state
     * the engine reports as unconfigured.
     */
    public function storeAiCredential(?string $credential): void
    {
        $credential = $credential === null ? null : trim($credential);

        $this->forceFill([
            'ai_api_key' => $credential === '' || $credential === null ? null : $credential,
            'ai_credential_set_at' => $credential === '' || $credential === null ? null : now(),
        ])->save();
    }

    /**
     * Whether the operator has configured the engine HERE, as opposed to in the
     * environment.
     *
     * The AI settings follow the same fallback rule as the SMTP block above:
     * what is stored wins, what is not stored falls through to `config`, which
     * falls through to the `.env`. This answers «is there anything stored at
     * all», which is what AppServiceProvider needs before it starts overriding.
     *
     * `ai_enabled` alone is not enough to count as configured — it defaults to
     * false on every row that has ever existed, and a default is not a decision.
     */
    public function aiConfigured(): bool
    {
        return $this->ai_enabled
            || filled($this->ai_provider)
            || filled($this->ai_model)
            || $this->aiCredentialConfigured();
    }
}
