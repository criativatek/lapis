<?php

namespace App\Services\Ai\Gateway;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProviders;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiUnavailable;
use App\Support\Entitlements\Entitlements;
use App\Support\Privacy\AiPayloadSanitizer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\RateLimiter;

/**
 * THE ONLY DOOR OUT OF THE BUILDING.
 *
 * Every AI capability built from here on calls `ask()` and nothing else. It does
 * not resolve a provider, it does not read a credential, it does not decide
 * whether a plan allows it, it does not count anything and it does not write an
 * audit row — because if each feature did those things for itself, then «does
 * Lapispro send student data to Google» would be a question with as many answers
 * as there are features, and the honest answer would be «read all of them and
 * hope» (§5, §7 of the AI Core brief).
 *
 * WHAT ONE CALL PASSES THROUGH, IN THIS ORDER AND NOT ANOTHER:
 *
 *   1. entitlement   does this organization's plan include the capability
 *   2. engine        is there one configured at all
 *   3. rate limit    per user and per organization, per minute
 *   4. quota         per user per day, per organization per month, and — for an
 *                    organization that holds `ai_institutional_pool` — the
 *                    shared pool and the member's share of it
 *   5. privacy       the payload is re-checked, not trusted
 *   6. the call      instruction and content as separate roles
 *   7. the meter     one row, whatever happened, with no content in it
 *
 * The order is load-bearing at both ends. Entitlement first because it is the
 * cheapest and the most likely «no», and because a school without the capability
 * should never consume a rate-limit bucket. Rate limit before quota because a
 * bucket is a cache read and a quota is two `count(*)`s. And the privacy check
 * last, immediately before the wire, so that nothing between the sanitiser and
 * the socket can have added anything.
 *
 * EVERY REFUSAL IS RECORDED. A blocked call writes a row with `status =
 * blocked` and the reason, and only then throws. «We refused four hundred
 * requests last month» is the number that says a ceiling is set wrong, and it is
 * invisible if refusals are silent.
 *
 * IT DOES NOT VALIDATE THE ANSWER, ON PURPOSE. `AiAnswer::text` comes back
 * rehydrated and otherwise untouched. What a good answer looks like is a
 * question only the calling feature can answer — Relatórios has `RewriteGuard`
 * for its own version of it — and a gateway that guessed would be either useless
 * or wrong. The contract says this out loud so the AI Experiences branch builds
 * its own guard rather than assuming there is one.
 *
 * IT NEVER ACTS. No tools, no function calling, no writes, no reads on the
 * model's behalf. An answer is text returned to a caller, and the caller decides
 * what a human is shown (§6, §13).
 */
class AiGateway
{
    public function __construct(
        protected AiTextProviders $providers,
        protected Entitlements $entitlements,
        protected CurrentOrganization $currentOrganization,
        protected AiQuota $quota,
        protected AiUsageRecorder $usage,
        protected AiPayloadSanitizer $sanitizer,
    ) {}

    /**
     * Whether this capability can be offered at all right now.
     *
     * Screens ask this before drawing a button, so nobody is shown a control
     * that can only fail (§41). It is NOT the access check — `ask()` re-asks
     * every question below on the server, because hiding a control is
     * presentation and this is the answer (CLAUDE.md §8.2).
     */
    public function isAvailable(AiCapability $capability): bool
    {
        return $this->unavailableReason($capability) === null;
    }

    /**
     * Why it cannot be offered, as a slug a screen turns into a sentence.
     *
     * `plan` and everything else are different problems for different people. A
     * school without the capability is being offered an upgrade; an installation
     * with no credential is waiting on whoever administers it, and clicking will
     * never help either of them. Telling one that it is the other wastes an
     * afternoon (§41).
     */
    public function unavailableReason(AiCapability $capability): ?string
    {
        $organization = $this->currentOrganization->isResolved()
            ? $this->currentOrganization->get()
            : null;

        if ($organization === null || ! $this->isEntitled($organization, $capability)) {
            return 'plan';
        }

        return $this->providers->unavailableReason();
    }

    /**
     * Whether this organization holds the capability, under any of the keys
     * that grant it.
     *
     * ONE PLACE, USED BY BOTH THE PREVIEW AND THE ENFORCEMENT, so «why does the
     * button show when the request is refused» cannot happen: `isAvailable()`
     * and `ask()` ask this exact method the exact same question.
     *
     * ANY OF THE KEYS, because a capability can have a historical name that
     * some organizations still hold through an override —
     * `AiCapability::legacyModuleKeys()` explains which and why. It only ever
     * widens: an organization holding the current key is granted regardless of
     * what the legacy key says.
     */
    protected function isEntitled(Organization $organization, AiCapability $capability): bool
    {
        foreach ($capability->moduleKeys() as $moduleKey) {
            if ($this->entitlements->allowsFor($organization, $moduleKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask an engine something.
     *
     * @throws AiUnavailable when the plan does not include it, or nothing is configured
     * @throws AiQuotaExceeded when a ceiling has been reached
     * @throws AiRequestFailed when the engine refuses, errors, times out or answers with nothing usable
     */
    public function ask(AiAsk $ask, User $user): AiAnswer
    {
        $capability = $ask->capability();

        // The operator's own traffic — the backoffice connection test. It has no
        // tenant, no entitlement and no quota; its authorization is the
        // `platform-admin` middleware on the route that reaches it, because the
        // person running the SaaS is not a customer of the SaaS.
        if ($capability === null) {
            return $this->send($ask, null, $user);
        }

        $organization = $this->currentOrganization->isResolved()
            ? $this->currentOrganization->get()
            : null;

        if ($organization === null || ! $this->isEntitled($organization, $capability)) {
            $this->usage->blocked($ask, $organization, $user, 'not_entitled');

            throw AiUnavailable::notEntitled($capability->value);
        }

        if (! $this->providers->isConfigured()) {
            $this->usage->blocked($ask, $organization, $user, 'provider_unavailable');

            throw AiUnavailable::notConfigured();
        }

        $this->assertWithinRateLimit($ask, $capability, $organization, $user);

        try {
            $this->quota->assertWithin($capability, $organization, $user);
        } catch (AiQuotaExceeded $exception) {
            $this->usage->blocked($ask, $organization, $user, $exception->scope());

            throw $exception;
        }

        return $this->send($ask, $organization, $user);
    }

    /**
     * Two ceilings per minute, and a request has to pass both (§28 of the
     * Relatórios brief, applied per capability here).
     *
     * PER USER, so one person holding down a button cannot spend the school's
     * budget. PER ORGANIZATION, so thirty people each within their own limit
     * still cannot. The buckets are hit only once both are known to have room,
     * so a request refused by the organization ceiling does not also consume the
     * user's.
     *
     * @throws AiQuotaExceeded
     */
    protected function assertWithinRateLimit(
        AiAsk $ask,
        AiCapability $capability,
        Organization $organization,
        User $user,
    ): void {
        $perMinute = max(1, (int) config('lapis.ai.per_minute'));
        $organizationPerMinute = max(1, (int) config('lapis.ai.organization_per_minute'));

        $userKey = $capability->rateLimiterKey().':user:'.$user->getKey();
        $organizationKey = $capability->rateLimiterKey().':organization:'.$organization->getKey();

        foreach ([[$userKey, $perMinute, 'user_daily'], [$organizationKey, $organizationPerMinute, 'organization_monthly']] as [$key, $maximum, $scope]) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                $this->usage->blocked($ask, $organization, $user, 'rate_limited');

                // Reported as a quota because that is what it is to the person
                // reading it — «demasiados pedidos, tente daqui a pouco». The
                // scope tells the meter which bucket it was.
                throw $scope === 'user_daily'
                    ? AiQuotaExceeded::forUser($capability, $maximum)
                    : AiQuotaExceeded::forOrganization($capability, $maximum);
            }
        }

        RateLimiter::hit($userKey);
        RateLimiter::hit($organizationKey);
    }

    /**
     * The wire.
     *
     * The privacy check runs HERE, on a payload the gateway was handed rather
     * than one it built, and immediately before the request is made. A caller
     * that constructed a `SanitisedPayload` by hand — the only way to get an
     * unsanitised string this far — is refused at the last possible moment
     * rather than trusted because it had the right type.
     */
    protected function send(AiAsk $ask, ?Organization $organization, ?User $user): AiAnswer
    {
        $this->sanitizer->assertClean($ask->content);

        $provider = $this->providers->make();
        $startedAt = hrtime(true);

        try {
            $response = $provider->complete(new AiTextRequest(
                instruction: $ask->instruction,
                content: $ask->content->text,
                temperature: $ask->temperature,
            ));
        } catch (AiRequestFailed $exception) {
            $this->usage->failed(
                $ask,
                $organization,
                $user,
                provider: $provider->name(),
                model: $provider->model(),
                errorCategory: $exception->category(),
                durationMilliseconds: $this->elapsed($startedAt),
            );

            throw $exception;
        }

        $answer = AiAnswer::from($response, $ask->content, $this->elapsed($startedAt));

        $this->usage->succeeded($ask, $answer, $organization, $user, $this->elapsed($startedAt));

        return $answer;
    }

    protected function elapsed(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
