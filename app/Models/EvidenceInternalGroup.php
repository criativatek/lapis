<?php

namespace App\Models;

/**
 * The 3 categories EvidenceKind is organized into for the teacher (§14) —
 * always derived from the kind via EvidenceKind::group(), never stored: a
 * record's own kind is the only fact on disk, so the grouping can never drift
 * out of sync with it.
 */
enum EvidenceInternalGroup: string
{
    case Learning = 'learning';
    case BehaviorAttitudes = 'behavior_attitudes';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::Learning => __('Aprendizagem'),
            self::BehaviorAttitudes => __('Comportamento e atitudes'),
            self::FollowUp => __('Acompanhamento'),
        };
    }
}
