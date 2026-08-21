<?php

namespace App\Actions\Accounts;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Accounts\AccountClosureException;
use App\Support\Retention\ClosureRetention;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Reverses a personal account closure request within the recovery window
 * (§7 of the lifecycle brief). Restores nothing beyond the two lifecycle
 * columns — pedagogical data, memberships and organizations were never
 * touched by the request in the first place, so there is nothing else to
 * undo.
 */
class CancelPersonalAccountClosure
{
    public function __construct(
        protected AuditLog $audit,
        protected ClosureRetention $retention,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function cancel(User $user): User
    {
        $this->guard($user);

        return DB::transaction(function () use ($user): User {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->guard($lockedUser);

            $lockedUser->forceFill([
                'closure_requested_at' => null,
                'scheduled_deletion_at' => null,
            ])->save();

            $personalOrganization = $lockedUser->personalOrganization();

            if ($personalOrganization !== null) {
                $this->currentOrganization->runFor($personalOrganization, fn () => $this->audit->record(
                    'account.closure_cancelled',
                    $lockedUser,
                    causer: $lockedUser,
                    summary: __('Cancelou o encerramento da conta e reativou o acesso normal.'),
                ));
            }

            return $lockedUser;
        });
    }

    protected function guard(User $user): void
    {
        if (! $user->isClosureRequested()) {
            throw AccountClosureException::notRequested();
        }

        if (! $this->retention->isPersonalAccountRecoverable($user->closure_requested_at, now())) {
            throw AccountClosureException::noLongerRecoverable();
        }
    }
}
