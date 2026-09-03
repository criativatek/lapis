<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Entitlements\GrantCapabilitiesDirectly;
use App\Actions\Entitlements\RevokeCapabilityGrant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDirectCapabilityGrantRequest;
use App\Models\CapabilityGrant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminCapabilityGrantController extends Controller
{
    public function store(StoreDirectCapabilityGrantRequest $request, Organization $organization, GrantCapabilitiesDirectly $grant): RedirectResponse
    {
        $data = $request->validated();
        $grant->handle($organization, $this->user($request), $data['module_keys'], (int) $data['duration_days'], (string) $data['reason']);

        return back();
    }

    public function revoke(Request $request, Organization $organization, string $grant, RevokeCapabilityGrant $revoke): RedirectResponse
    {
        $row = CapabilityGrant::query()->withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->where('ulid', $grant)->firstOrFail();
        $revoke->handle($row, $this->user($request));

        return back();
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
