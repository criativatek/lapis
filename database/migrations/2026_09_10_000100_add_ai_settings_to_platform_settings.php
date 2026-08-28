<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI engine becomes something an operator configures, not something a
 * deploy configures.
 *
 * WHY THE DATABASE AND NOT JUST THE `.env`. Until now the whole AI layer was
 * environment-only, which means «turn the assistant off because it is costing
 * too much» was a server login and a restart. The person who needs to do that
 * is the SaaS operator, who already administers system email from
 * /admin/settings; this is the same kind of value in the same kind of place, and
 * `platform_settings` is where the application already keeps exactly one row of
 * exactly this sort (§4 of the AI Core brief).
 *
 * EVERY COLUMN IS NULLABLE AND NULL MEANS «NOT DECIDED HERE». A null falls back
 * to `config/lapis.php`, which falls back to the environment — the identical
 * arrangement `mail_host` has had since it existed. An installation that
 * configures the engine through the environment keeps working untouched, and one
 * that has never opened the screen behaves exactly as it did before this
 * migration ran. `ai_enabled` is the one exception: it defaults to FALSE, which
 * is the state the brief asks a fresh installation to be in, and it is read as a
 * master switch only once anything else has been stored (see
 * `PlatformSetting::aiConfigured()`).
 *
 * THE CREDENTIAL IS ENCRYPTED AT THE MODEL LAYER (`encrypted` cast), the same
 * way `mail_password` already is, and `text` rather than `string` because
 * Laravel's ciphertext is a base64 envelope several times longer than the value
 * inside it. There is no plaintext copy anywhere in this schema — not a prefix
 * column, not a hint column, not a hash. What the backoffice shows as «••••1a2b»
 * is derived at read time from the decrypted value and never stored (§4).
 *
 * A SECRET MANAGER WOULD BE BETTER AND THIS IS NOT PRETENDING OTHERWISE. An
 * encrypted column is only as good as APP_KEY, which lives in the same `.env`
 * the operator is trying not to edit, on the same machine. Where a production
 * deployment has a real secret manager, the key belongs there with
 * `LAPIS_AI_KEY` injected at deploy time and this column left null — the
 * fallback order above already makes that the supported arrangement, and
 * docs/ai-core-contract.md says so out loud.
 *
 * `ai_quotas` IS JSON, and that is a deliberate exception to this project's
 * preference for columns. The shape is per-capability and the capability
 * catalogue grows; four scalar columns today would be six the moment a third
 * capability exists, each needing its own migration. `plans.limits` already
 * establishes JSON-for-quantitative-caps in this codebase, and this is the
 * platform-wide sibling of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            // The master switch. False on a fresh installation, and false is
            // also what an operator sets when the bill arrives.
            $table->boolean('ai_enabled')->default(false)->after('contact_email');

            // 'gemini' | 'chat-completions' | 'fake'. Deliberately a string and
            // not an enum column: which drivers exist is a fact about the code,
            // and AiTextProviders is the one place allowed to know it — an enum
            // here would make adding a driver a migration.
            $table->string('ai_provider', 32)->nullable()->after('ai_enabled');
            $table->string('ai_model', 64)->nullable()->after('ai_provider');

            $table->unsignedSmallInteger('ai_timeout_seconds')->nullable()->after('ai_model');
            $table->unsignedInteger('ai_max_output_tokens')->nullable()->after('ai_timeout_seconds');

            // Requests per minute. Two ceilings, both nullable, both falling
            // back to config — the rate limiter that reads them already exists.
            $table->unsignedSmallInteger('ai_per_minute')->nullable()->after('ai_max_output_tokens');
            $table->unsignedSmallInteger('ai_organization_per_minute')->nullable()->after('ai_per_minute');

            // {capability: {user_daily: int|null, organization_monthly: int|null}}
            $table->json('ai_quotas')->nullable()->after('ai_organization_per_minute');

            // Encrypted at the model layer. Never selected into an Inertia
            // payload, never logged, never written to an audit row.
            $table->text('ai_api_key')->nullable()->after('ai_quotas');

            // WHEN, never WHAT. Lets the backoffice say «configurada a 3 de
            // março» without the audit trail having to be joined, and gives
            // «substituir credencial» something truthful to show afterwards.
            $table->dateTime('ai_credential_set_at')->nullable()->after('ai_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ai_enabled',
                'ai_provider',
                'ai_model',
                'ai_timeout_seconds',
                'ai_max_output_tokens',
                'ai_per_minute',
                'ai_organization_per_minute',
                'ai_quotas',
                'ai_api_key',
                'ai_credential_set_at',
            ]);
        });
    }
};
