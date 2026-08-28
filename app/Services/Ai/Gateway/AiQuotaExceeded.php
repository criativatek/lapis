<?php

namespace App\Services\Ai\Gateway;

use RuntimeException;

/**
 * A ceiling stopped the call before it left the building.
 *
 * A THIRD FAILURE KIND, DISTINCT FROM THE OTHER TWO, because it means something
 * different to the person reading the screen. `AiUnavailable` means «not on this
 * installation / not in this plan» — clicking will never help. `AiRequestFailed`
 * means «the engine did not answer» — try again. This means «you have used what
 * you had» — try tomorrow, or next month, and the message says which.
 *
 * THE MESSAGE NAMES THE WINDOW AND THE NUMBER. «Atingiu o limite» with no number
 * is a dead end; the configured ceiling is quoted back, the same way
 * `Limits::limitReachedMessage()` already quotes a plan's turma limit rather than
 * writing one into the string.
 *
 * IT DOES NOT NAME THE OTHER PERSON. An organization-level ceiling is reported as
 * the organization's, never as «o professor X gastou-o» — a quota message is not
 * a place to tell one colleague what another has been doing.
 */
class AiQuotaExceeded extends RuntimeException
{
    protected function __construct(
        string $message,
        protected string $publicMessage,
        protected string $scope,
    ) {
        parent::__construct($message);
    }

    public static function forUser(AiCapability $capability, int $limit): self
    {
        return new self(
            "Daily per-user quota of {$limit} reached for [{$capability->value}].",
            __(
                'Atingiu o limite diário de :limit pedidos de IA. Volte a tentar amanhã.',
                ['limit' => $limit],
            ),
            'user_daily',
        );
    }

    public static function forOrganization(AiCapability $capability, int $limit): self
    {
        return new self(
            "Monthly per-organization quota of {$limit} reached for [{$capability->value}].",
            __(
                'A sua organização atingiu o limite mensal de :limit pedidos de IA. O limite renova-se no início do próximo mês.',
                ['limit' => $limit],
            ),
            'organization_monthly',
        );
    }

    /** Safe to show a teacher. */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /** `user_daily` or `organization_monthly` — what `ai_usage_events.error_category` stores. */
    public function scope(): string
    {
        return $this->scope;
    }
}
