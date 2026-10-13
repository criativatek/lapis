<?php

namespace App\Actions\Accounts;

use App\Actions\Usage\ForgetUsageData;
use App\Models\OrganizationType;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Accounts\AccountClosureException;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Starts the recoverable closure window for a personal account (§5-§6 of the
 * lifecycle brief). Deletes nothing of the account itself — it stamps a
 * deadline that CancelPersonalAccountClosure can still undo, and that
 * ClosureRetention/EnsureAccountIsOperational read from that moment on.
 *
 * The one exception is the usage metrics (docs/usage-insights.md): they are
 * not the person's work, only a record of how they used the app, so they are
 * erased HERE, in the same transaction, not on day 60. Cancelling the request
 * does not bring them back.
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
        protected ForgetUsageData $forgetUsage,
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

            // A recolha pára aqui (o recorder não grava para quem pediu o
            // encerramento) e o que já existia sai já, com a linha bloqueada.
            $this->forgetUsage->forUser($lockedUser);

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
