<?php

namespace App\Support\Retention;

use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;

/**
 * Read-only preview of what the closure/retention policy considers eligible
 * right now. Never deletes, never mutates — backs both `retention:status`
 * and the admin backoffice's read-only closure column (§18-§20 of the
 * lifecycle brief). Cross-tenant by nature: this is the one place that is
 * SUPPOSED to see every organization at once.
 */
final class DeletionEligibility
{
    public function __construct(
        private readonly ClosureRetention $retention,
        private readonly AcademicYearRetentionClassifier $academicYearClassifier,
        private readonly RetentionPolicy $policy,
        private readonly CurrentOrganization $currentOrganization,
    ) {}

    /**
     * @return Collection<int, array{user: User, days_remaining: int, eligible: bool}>
     */
    public function personalAccounts(): Collection
    {
        $now = now();

        return User::query()->closureRequested()->get()->map(fn (User $user): array => [
            'user' => $user,
            'days_remaining' => $user->scheduled_deletion_at?->isFuture() ? (int) $now->diffInDays($user->scheduled_deletion_at) : 0,
            'eligible' => ! $this->retention->isPersonalAccountRecoverable($user->closure_requested_at, $now),
        ]);
    }

    /**
     * @return Collection<int, array{organization: Organization, days_remaining: int, eligible: bool}>
     */
    public function institutionalOrganizations(): Collection
    {
        $now = now();

        return Organization::query()->closureRequested()->get()->map(fn (Organization $organization): array => [
            'organization' => $organization,
            'days_remaining' => $organization->scheduled_deletion_at?->isFuture() ? (int) $now->diffInDays($organization->scheduled_deletion_at) : 0,
            'eligible' => ! $this->retention->isInstitutionalOrganizationRecoverable($organization->closure_requested_at, $now),
        ]);
    }

    /**
     * Expired export ZIPs still on disk — the same rows
     * `data-exports:prune` (already scheduled hourly) will clear. This is a
     * preview only; it never touches the filesystem.
     *
     * @return Collection<int, DataExport>
     */
    public function expiredDataExports(): Collection
    {
        return DataExport::query()
            ->withoutGlobalScope('organization')
            ->whereNotNull('disk_path')
            ->where('expires_at', '<', now())
            ->get();
    }

    /**
     * Every organization's academic years, classified within/outside the
     * pedagogical retention window (current + N previous, §20). Never
     * deletes or archives anything — AcademicYearRetentionClassifier is a
     * pure read.
     *
     * Plain arrays, not Collections: PHPStan's Collection<TKey, TValue> is
     * invariant, so a shape this exact (nested array-shapes, built across an
     * empty/non-empty branch) cannot be expressed as one — the same
     * limitation GenerateDataExport already worked around the same way.
     *
     * @return array<int, array{organization: Organization, years: array<int, array{year: AcademicYear, within_retention: bool}>}>
     */
    public function academicYears(): array
    {
        return Organization::query()->get()->map(function (Organization $organization): array {
            $years = $this->currentOrganization->runFor($organization, fn () => AcademicYear::query()->get());

            $current = $this->academicYearClassifier->currentYearFor($years);

            return [
                'organization' => $organization,
                'years' => $current === null ? [] : $this->academicYearClassifier->classify($years, $current)->all(),
            ];
        })->filter(fn (array $entry): bool => $entry['years'] !== [])->values()->all();
    }

    /**
     * How many audit events are older than the security/institutional audit
     * window (§24 of the lifecycle brief). A COUNT, not a listing — the
     * trail itself is security-sensitive, and a preview only needs to
     * answer "how many," never "which." Nothing here purges a single event;
     * there is no purge command for audit_events in this fatia at all.
     */
    public function auditEventsOutsideRetention(): int
    {
        return AuditEvent::query()
            ->withoutGlobalScope('organization')
            ->where('created_at', '<', now()->subYears($this->policy->securityAuditYears()))
            ->count();
    }
}
