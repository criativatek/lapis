<?php

namespace App\Support\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a screen needs to draw «Retificar» on each published classification, said
 * once for both screens that offer it (Classificações and Resultados).
 *
 * CHEAP BY CONSTRUCTION. The rights are worked out ONCE for the class and period
 * (RectificationAccess::context()) and applied to each row from the row's own
 * state; the number of rectifications comes from a single grouped query. The
 * warning about external communication and the history are NOT here: they cost
 * queries per row, so the dialog asks for them when it opens.
 */
final class RectificationPayload
{
    /**
     * @param  Collection<int, Classification>  $classifications  the live row of each cell, any status
     * @return array<int, array{allowed: bool, needs_confirmation: bool, blocked_reason: ?string, lock_version: int, count: int}> keyed by enrollment id
     */
    public static function for(
        User $user,
        SchoolClass $class,
        AcademicPeriod $period,
        ClassificationScope $scope,
        Collection $classifications,
    ): array {
        if ($classifications->isEmpty()) {
            return [];
        }

        $organization = Organization::query()->findOrFail($class->organization_id);
        $context = RectificationAccess::context($user, $organization, $class, $period);

        // Every rectification writes a row that points at the one it replaced, so
        // the length of a cell's chain is the number of rows in it that point back.
        $counts = Classification::query()->withoutGlobalScope('organization')
            ->where('organization_id', $class->organization_id)
            ->where('academic_period_id', $period->getKey())
            ->where('scope', $scope)
            ->whereIn('enrollment_id', $classifications->pluck('enrollment_id'))
            ->whereNotNull('rectifies_id')
            ->selectRaw('enrollment_id, count(*) as total')
            ->groupBy('enrollment_id')
            ->pluck('total', 'enrollment_id');

        $payload = [];

        foreach ($classifications as $classification) {
            $access = $context->accessFor($classification);

            $payload[(int) $classification->enrollment_id] = [
                'allowed' => $access->allowed,
                'needs_confirmation' => $access->needsConfirmation,
                'blocked_reason' => $access->blockedReason,
                'lock_version' => (int) $classification->lock_version,
                'count' => (int) ($counts[$classification->enrollment_id] ?? 0),
            ];
        }

        return $payload;
    }
}
