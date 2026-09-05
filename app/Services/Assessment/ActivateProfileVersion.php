<?php

namespace App\Services\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\ProfileVersionStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Assessment\ProfileActivationException;
use App\Support\Assessment\SupportedCalculationRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Activates a draft profile version — the freeze point (§10.2, domain-model.md §3).
 *
 * All of this happens in one transaction (§24.2):
 *  1. Validate: it is a draft; it has domains; the weights total 100% when the
 *     mode demands it. A profile that fails does not activate (§10.3).
 *  2. Supersede the profile's currently active version first, so there is never
 *     a moment with two active versions — the DB's one-active-version index
 *     (active_flag) would reject that anyway; ordering keeps it from firing.
 *  3. Freeze and activate the draft. Frozen means the historical rule a result
 *     points at can never change under it.
 *  4. Point the profile's current_version_id at it (a read shortcut).
 *
 * A frozen org-owned scale is frozen on first active use (copy-on-write, §2.3).
 * System scales (organization_id NULL) are immutable by contract and skipped.
 */
class ActivateProfileVersion
{
    public function __construct(protected AuditLog $audit) {}

    public function activate(AssessmentProfileVersion $version, User $actor): AssessmentProfileVersion
    {
        $this->guardIsDraft($version);
        $this->guardWeights($version);
        $this->guardSupportedRules($version);

        return DB::transaction(function () use ($version, $actor): AssessmentProfileVersion {
            $this->supersedeCurrentActive($version);

            $now = Carbon::now();

            $version->allowFrozenWrite = true;
            $version->forceFill([
                'status' => ProfileVersionStatus::Active,
                'activated_at' => $now,
                'activated_by' => $actor->getKey(),
                'frozen_at' => $now,
            ])->save();
            // Close the escape hatch immediately — any later write to this now-frozen
            // instance must hit the immutability guard.
            $version->allowFrozenWrite = false;

            $this->freezeScale($version, $now);

            $version->profile()->update(['current_version_id' => $version->getKey()]);

            $this->audit->record(
                'profile_version.activated',
                $version,
                $actor,
                "Versão {$version->version_number} do perfil ativada e congelada.",
                ['version_number' => $version->version_number, 'assessment_profile_id' => $version->assessment_profile_id],
            );

            return $version->refresh();
        });
    }

    /**
     * SÓ ativa o que o motor sabe cumprir.
     *
     * A lista vive em SupportedCalculationRules porque a importação de backup
     * precisa da mesma pergunta: uma versão pode chegar já ativa de outra
     * instalação sem passar por aqui.
     *
     * Falhar aqui é barato — o professor vê a mensagem antes de haver notas.
     * Falhar no cálculo é caro e invisível.
     */
    protected function guardSupportedRules(AssessmentProfileVersion $version): void
    {
        $unsupported = SupportedCalculationRules::firstUnsupported($version->attributesToArray());

        if ($unsupported !== null) {
            throw ProfileActivationException::unsupportedRule(
                $unsupported['field'],
                $unsupported['value'],
                $unsupported['supported'],
            );
        }
    }

    protected function guardIsDraft(AssessmentProfileVersion $version): void
    {
        if ($version->status !== ProfileVersionStatus::Draft) {
            throw ProfileActivationException::notADraft();
        }
    }

    protected function guardWeights(AssessmentProfileVersion $version): void
    {
        $domainCount = $version->domains()->count();

        if ($domainCount === 0) {
            throw ProfileActivationException::noDomains();
        }

        if ($version->domain_weight_mode === 'must_total_100') {
            $total = $version->totalDomainWeight();

            // DECIMAL sum compared with tolerance for the 4th decimal place.
            if (abs((float) $total - 100.0) > 0.0001) {
                throw ProfileActivationException::weightsMustTotal100(rtrim(rtrim($total, '0'), '.') ?: '0');
            }
        }
    }

    protected function supersedeCurrentActive(AssessmentProfileVersion $version): void
    {
        $active = AssessmentProfileVersion::query()
            ->where('assessment_profile_id', $version->assessment_profile_id)
            ->where('status', ProfileVersionStatus::Active->value)
            ->first();

        if ($active === null) {
            return;
        }

        // A controlled write to a frozen row: superseding does not change the
        // calculation rule, only the lifecycle pointer, so the guard is bypassed
        // here and only here.
        $active->allowFrozenWrite = true;
        $active->forceFill([
            'status' => ProfileVersionStatus::Superseded,
            'superseded_at' => Carbon::now(),
            'superseded_by_version_id' => $version->getKey(),
        ])->save();
        $active->allowFrozenWrite = false;
    }

    protected function freezeScale(AssessmentProfileVersion $version, Carbon $now): void
    {
        $scale = $version->scale;

        if ($scale->organization_id !== null && $scale->frozen_at === null) {
            $scale->forceFill(['frozen_at' => $now])->save();
        }
    }
}
