<?php

namespace App\Actions\Entitlements;

use App\Models\CapabilityGrant;
use App\Models\CapabilityGrantSource;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\Entitlements;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantCapabilitiesDirectly
{
    use ResolvesCapabilityModules;

    public function __construct(private AuditLog $audit, private Entitlements $entitlements) {}

    /**
     * @param  list<string>  $moduleKeys
     *
     * Typed against CarbonInterface, not Carbon: the app runs
     * Date::use(CarbonImmutable::class), so now() and every caller that
     * reaches this from a request hands over a CarbonImmutable, not the
     * mutable Carbon this action used to require.
     */
    public function handle(Organization $organization, User $operator, array $moduleKeys, int $durationDays, string $reason, ?CarbonInterface $startsAt = null): CapabilityGrant
    {
        if (! $operator->is_platform_admin) {
            abort(403);
        }
        if ($durationDays < 1 || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Indique o motivo da atribuição e uma duração válida.')]);
        }
        $modules = $this->modulesFor($moduleKeys);
        $startsAt ??= Carbon::now();
        $grant = DB::transaction(function () use ($organization, $operator, $durationDays, $reason, $startsAt, $modules): CapabilityGrant {
            $grant = new CapabilityGrant;
            $grant->forceFill(['organization_id' => $organization->getKey(), 'source' => CapabilityGrantSource::Direct, 'capability_voucher_id' => null, 'granted_by' => $operator->getKey(), 'reason' => trim($reason), 'starts_at' => $startsAt, 'expires_at' => $startsAt->copy()->addDays($durationDays)]);
            $grant->save();
            $grant->modules()->attach($modules->modelKeys());

            return $grant;
        });
        $this->entitlements->flush();
        $this->audit->recordPlatform('capability.grant_issued', $operator, "Capacidades temporárias atribuídas a {$organization->name}.", ['organization_id' => $organization->getKey(), 'capability_grant_id' => $grant->getKey(), 'module_keys' => $modules->pluck('key')->all(), 'reason' => trim($reason)]);

        return $grant;
    }
}
