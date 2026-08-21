<?php

namespace App\Support\Retention;

use Carbon\CarbonInterface;

/**
 * Formats a closure request (requested_at + scheduled_deletion_at) into the
 * shape every closure UI needs — request page, banner, admin read-only view.
 *
 * Deliberately built AROUND ClosureRetention rather than inside it: that
 * class is small, already tested (tests/Unit/Retention/ClosureRetentionTest.php)
 * and is the canonical recoverable/not-recoverable boundary. This composes
 * it instead of editing it, so that boundary is never touched by anything
 * this fatia adds.
 *
 * Typed against CarbonInterface, not Carbon: the app runs Date::use(CarbonImmutable::class)
 * globally, so every Eloquent datetime cast (closure_requested_at,
 * scheduled_deletion_at) hands back a CarbonImmutable, not a Carbon.
 */
final class ClosureStatusPresenter
{
    public function __construct(private readonly ClosureRetention $retention) {}

    /**
     * @return array{requested_at: string, scheduled_deletion_at: string, days_remaining: int, recoverable: bool}
     */
    public function personal(CarbonInterface $requestedAt, CarbonInterface $scheduledDeletionAt): array
    {
        return $this->present(
            $requestedAt,
            $scheduledDeletionAt,
            fn (CarbonInterface $requestedAt, CarbonInterface $now): bool => $this->retention->isPersonalAccountRecoverable($requestedAt, $now),
        );
    }

    /**
     * @return array{requested_at: string, scheduled_deletion_at: string, days_remaining: int, recoverable: bool}
     */
    public function institutional(CarbonInterface $requestedAt, CarbonInterface $scheduledDeletionAt): array
    {
        return $this->present(
            $requestedAt,
            $scheduledDeletionAt,
            fn (CarbonInterface $requestedAt, CarbonInterface $now): bool => $this->retention->isInstitutionalOrganizationRecoverable($requestedAt, $now),
        );
    }

    /**
     * @return array{requested_at: string, scheduled_deletion_at: string, days_remaining: int, recoverable: bool}
     */
    private function present(CarbonInterface $requestedAt, CarbonInterface $scheduledDeletionAt, callable $recoverable): array
    {
        $now = now();

        return [
            'requested_at' => $requestedAt->toIso8601String(),
            'scheduled_deletion_at' => $scheduledDeletionAt->toIso8601String(),
            'days_remaining' => $scheduledDeletionAt->isFuture() ? (int) $now->diffInDays($scheduledDeletionAt) : 0,
            'recoverable' => $recoverable($requestedAt, $now),
        ];
    }
}
