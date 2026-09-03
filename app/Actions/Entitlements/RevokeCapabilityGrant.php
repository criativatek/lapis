<?php

namespace App\Actions\Entitlements;

use App\Models\CapabilityGrant;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;

class RevokeCapabilityGrant
{
    public function __construct(private AuditLog $audit, private Entitlements $entitlements) {}

    public function handle(CapabilityGrant $grant, User $operator): CapabilityGrant
    {
        if (! $operator->is_platform_admin) {
            abort(403);
        }
        if ($grant->revoked_at === null) {
            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $operator->getKey()])->save();
            $this->audit->recordPlatform('capability.grant_revoked', $operator, 'Atribuição temporária revogada.', ['organization_id' => $grant->organization_id, 'capability_grant_id' => $grant->getKey()]);
            $this->entitlements->flush();
        }

        return $grant;
    }
}
