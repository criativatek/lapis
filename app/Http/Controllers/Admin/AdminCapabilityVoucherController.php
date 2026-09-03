<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Entitlements\DisableCapabilityVoucher;
use App\Actions\Entitlements\GenerateCapabilityVoucher;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCapabilityVoucherRequest;
use App\Models\CapabilityGrantPreset;
use App\Models\CapabilityVoucher;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AdminCapabilityVoucherController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/CapabilityVouchers', [
            'modules' => Module::query()->orderBy('name')->get(['key', 'name']),
            'presets' => CapabilityGrantPreset::query()->with('modules:key,name')->orderBy('name')->get()->map(fn (CapabilityGrantPreset $preset): array => ['id' => $preset->getKey(), 'key' => $preset->key, 'name' => $preset->name, 'notes' => $preset->notes, 'durationDays' => $preset->default_duration_days, 'active' => $preset->active, 'moduleKeys' => $preset->modules->pluck('key')->all()]),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'ulid', 'name']),
            'vouchers' => CapabilityVoucher::query()->with('modules:key,name')->withCount('redemptions')->latest('id')->get()->map(fn (CapabilityVoucher $voucher): array => ['ulid' => $voucher->ulid, 'code' => $voucher->code, 'label' => $voucher->label, 'durationDays' => $voucher->duration_days, 'validFrom' => $voucher->valid_from?->toIso8601String(), 'validUntil' => $voucher->valid_until?->toIso8601String(), 'maxRedemptions' => $voucher->max_redemptions, 'redemptionsCount' => (int) $voucher->getAttribute('redemptions_count'), 'restrictedOrganizationId' => $voucher->restricted_organization_id, 'disabledAt' => $voucher->disabled_at?->toIso8601String(), 'modules' => $voucher->modules->map(fn (Module $module): array => ['key' => $module->key, 'name' => $module->name])]),
        ]);
    }

    public function store(StoreCapabilityVoucherRequest $request, GenerateCapabilityVoucher $generate): RedirectResponse
    {
        $data = $request->validated();
        $preset = isset($data['preset_id']) ? CapabilityGrantPreset::query()->findOrFail((int) $data['preset_id']) : null;
        $generate->handle($this->user($request), (string) $data['label'], (int) $data['duration_days'], $data['module_keys'] ?? null, $preset, $data['code'] ?? null, isset($data['valid_from']) ? Carbon::parse($data['valid_from']) : null, isset($data['valid_until']) ? Carbon::parse($data['valid_until'])->endOfDay() : null, $data['max_redemptions'] ?? null, $data['restricted_organization_id'] ?? null, $data['notes'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Código de capacidades gerado.')]);

        return back();
    }

    public function disable(Request $request, CapabilityVoucher $capabilityVoucher, DisableCapabilityVoucher $disable): RedirectResponse
    {
        $disable->handle($capabilityVoucher, $this->user($request));

        return back();
    }

    private function user(Request $request): User
    { /** @var User $user */ $user = $request->user();

        return $user;
    }
}
