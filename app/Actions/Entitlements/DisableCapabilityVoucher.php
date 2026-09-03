<?php

namespace App\Actions\Entitlements;

use App\Models\CapabilityVoucher;
use App\Models\User;
use App\Services\Audit\AuditLog;

class DisableCapabilityVoucher
{
    public function __construct(private AuditLog $audit) {}

    public function handle(CapabilityVoucher $voucher, User $operator): CapabilityVoucher
    {
        if (! $operator->is_platform_admin) {
            abort(403);
        }
        if ($voucher->disabled_at === null) {
            $voucher->forceFill(['disabled_at' => now(), 'disabled_by' => $operator->getKey()])->save();
            $this->audit->recordPlatform('capability.voucher_disabled', $operator, "Código {$voucher->code} desativado.", ['capability_voucher_id' => $voucher->getKey()]);
        }

        return $voucher;
    }
}
