<?php

namespace App\Actions\Entitlements;

use App\Models\CapabilityGrantPreset;
use App\Models\CapabilityVoucher;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Entitlements\CapabilityVoucherCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateCapabilityVoucher
{
    use ResolvesCapabilityModules;

    public function __construct(private AuditLog $audit) {}

    /**
     * @param  list<string>|null  $moduleKeys
     *
     * Typed against CarbonInterface, not Carbon: the app runs
     * Date::use(CarbonImmutable::class), so now() and every caller that
     * reaches this from a request hands over a CarbonImmutable, not the
     * mutable Carbon this action used to require.
     */
    public function handle(User $operator, string $label, int $durationDays, ?array $moduleKeys = null, ?CapabilityGrantPreset $preset = null, ?string $code = null, ?CarbonInterface $validFrom = null, ?CarbonInterface $validUntil = null, ?int $maxRedemptions = null, ?int $restrictedOrganizationId = null, ?string $notes = null): CapabilityVoucher
    {
        if ($durationDays < 1 || ($maxRedemptions !== null && $maxRedemptions < 1) || ($validFrom !== null && $validUntil !== null && $validUntil->lt($validFrom))) {
            throw ValidationException::withMessages(['duration_days' => __('A duração e a janela do código não são válidas.')]);
        }
        $modules = $preset !== null ? $preset->modules()->get() : $this->modulesFor($moduleKeys ?? []);
        if ($modules->isEmpty()) {
            throw ValidationException::withMessages(['module_keys' => __('Escolha pelo menos uma capacidade.')]);
        }
        $displayCode = trim((string) $code) === '' ? CapabilityVoucherCode::generate() : trim((string) $code);
        if (! CapabilityVoucherCode::isWellFormed($displayCode) || CapabilityVoucher::query()->code($displayCode)->exists()) {
            throw ValidationException::withMessages(['code' => __('O código é inválido ou já existe.')]);
        }

        $voucher = DB::transaction(function () use ($operator, $label, $durationDays, $preset, $displayCode, $validFrom, $validUntil, $maxRedemptions, $restrictedOrganizationId, $notes, $modules): CapabilityVoucher {
            $voucher = CapabilityVoucher::create(['code' => $displayCode, 'label' => $label, 'preset_id' => $preset?->getKey(), 'duration_days' => $durationDays, 'valid_from' => $validFrom, 'valid_until' => $validUntil, 'max_redemptions' => $maxRedemptions, 'restricted_organization_id' => $restrictedOrganizationId, 'created_by' => $operator->getKey(), 'notes' => $notes]);
            $voucher->modules()->attach($modules->modelKeys());

            return $voucher;
        });
        $this->audit->recordPlatform('capability.voucher_issued', $operator, "Código de capacidades {$voucher->code} emitido.", ['capability_voucher_id' => $voucher->getKey(), 'module_keys' => $modules->pluck('key')->all(), 'duration_days' => $durationDays]);

        return $voucher;
    }
}
