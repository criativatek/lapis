<?php

namespace App\Actions\Entitlements;

use App\Models\CapabilityGrant;
use App\Models\CapabilityGrantSource;
use App\Models\CapabilityVoucher;
use App\Models\CapabilityVoucherRedemption;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\CapabilityVoucherUnavailable;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RedeemCapabilityVoucher
{
    public function __construct(private AuditLog $audit, private CurrentOrganization $tenancy, private Entitlements $entitlements) {}

    public function handle(string $code, Organization $organization, User $redeemer): CapabilityGrant
    {
        $grant = DB::transaction(function () use ($code, $organization, $redeemer): CapabilityGrant {
            $voucher = CapabilityVoucher::query()->code($code)->lockForUpdate()->first();
            if ($voucher === null) {
                throw new CapabilityVoucherUnavailable(__('Código inválido.'));
            }
            $now = Carbon::now();
            if ($voucher->disabled_at !== null) {
                throw new CapabilityVoucherUnavailable(__('Este código está desativado.'));
            }
            if ($voucher->valid_from !== null && $now->lt($voucher->valid_from)) {
                throw new CapabilityVoucherUnavailable(__('Este código ainda não está disponível.'));
            }
            if ($voucher->valid_until !== null && $now->gt($voucher->valid_until)) {
                throw new CapabilityVoucherUnavailable(__('Este código expirou.'));
            }
            if ($voucher->restricted_organization_id !== null && $voucher->restricted_organization_id !== $organization->getKey()) {
                throw new CapabilityVoucherUnavailable(__('Este código pertence a outra organização.'));
            }
            if (CapabilityVoucherRedemption::query()->withoutGlobalScope('organization')->where('capability_voucher_id', $voucher->getKey())->where('organization_id', $organization->getKey())->exists()) {
                throw new CapabilityVoucherUnavailable(__('Esta organização já resgatou este código.'));
            }
            $used = CapabilityVoucherRedemption::query()->withoutGlobalScope('organization')->where('capability_voucher_id', $voucher->getKey())->count();
            if ($voucher->max_redemptions !== null && $used >= $voucher->max_redemptions) {
                throw new CapabilityVoucherUnavailable(__('Este código já atingiu o limite de utilizações.'));
            }
            $modules = $voucher->modules()->get();
            if ($modules->isEmpty()) {
                throw new CapabilityVoucherUnavailable(__('Este código não contém capacidades.'));
            }
            $grant = new CapabilityGrant;
            $grant->forceFill(['organization_id' => $organization->getKey(), 'source' => CapabilityGrantSource::Voucher, 'capability_voucher_id' => $voucher->getKey(), 'granted_by' => $redeemer->getKey(), 'reason' => null, 'starts_at' => $now, 'expires_at' => $now->copy()->addDays($voucher->duration_days)]);
            $grant->save();
            $grant->modules()->attach($modules->modelKeys());
            $redemption = new CapabilityVoucherRedemption;
            $redemption->forceFill(['organization_id' => $organization->getKey(), 'capability_voucher_id' => $voucher->getKey(), 'capability_grant_id' => $grant->getKey(), 'redeemed_by' => $redeemer->getKey(), 'redeemed_at' => $now]);
            $redemption->save();
            $this->tenancy->runFor($organization, fn () => $this->audit->record('capability.voucher_redeemed', $grant, $redeemer, "Código {$voucher->code} resgatado.", ['capability_voucher_id' => $voucher->getKey(), 'module_keys' => $modules->pluck('key')->all()]));

            return $grant;
        });
        $this->entitlements->flush();

        return $grant;
    }
}
