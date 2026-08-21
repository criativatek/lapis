<?php

namespace App\Actions\Accounts;

use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Accounts\AccountClosureException;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Starts the recoverable closure window for a personal account (§5-§6 of the
 * lifecycle brief). Never deletes anything — it stamps a deadline that
 * CancelPersonalAccountClosure can still undo, and that
 * ClosureRetention/EnsureAccountIsOperational read from that moment on.
 *
 * Distinct from `deactivated_at` (an operator's action) and from
 * DeleteUserAccount (immediate, administrative, exceptional — §34 of the
 * lifecycle brief). This is the only door a teacher opens themselves.
 */
class RequestPersonalAccountClosure
{
    public function __construct(
        protected AuditLog $audit,
        protected RetentionPolicy $policy,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function request(User $user): User
    {
        $this->guard($user);

        return DB::transaction(function () use ($user): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->guard($lockedUser);

            $requestedAt = now();
            $scheduledDeletionAt = $requestedAt->copy()->addDays($this->policy->personalAccountClosureDays());

            $lockedUser->forceFill([
                'closure_requested_at' => $requestedAt,
                'scheduled_deletion_at' => $scheduledDeletionAt,
            ])->save();

            $personalOrganization = $lockedUser->personalOrganization();

            if ($personalOrganization !== null) {
                $this->currentOrganization->runFor($personalOrganization, fn () => $this->audit->record(
                    'account.closure_requested',
                    $lockedUser,
                    causer: $lockedUser,
                    summary: __('Pediu o encerramento da conta.'),
                    properties: ['scheduled_deletion_at' => $scheduledDeletionAt->toIso8601String()],
                ));
            }

            return $lockedUser;
        });
    }

    protected function guard(User $user): void
    {
        if ($user->isClosureRequested()) {
            throw AccountClosureException::alreadyRequested();
        }

        if ($user->ownedOrganizations()->where('type', OrganizationType::Institutional)->exists()) {
            throw AccountClosureException::ownsInstitution();
        }
    }
}
