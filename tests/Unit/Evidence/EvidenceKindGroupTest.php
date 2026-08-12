<?php

namespace Tests\Unit\Evidence;

use App\Models\EvidenceInternalGroup;
use App\Models\EvidenceKind;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EvidenceKind::group() (§5): the internal category the teacher never picks
 * directly. The match has no default arm, so a case added to EvidenceKind
 * without a matching arm here throws instead of silently falling through.
 */
class EvidenceKindGroupTest extends TestCase
{
    #[Test]
    public function every_kind_resolves_to_a_group_without_throwing(): void
    {
        foreach (EvidenceKind::cases() as $kind) {
            $kind->group();
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_learning_group_holds_exactly_the_four_academic_kinds(): void
    {
        $this->assertSame(
            [EvidenceKind::Homework, EvidenceKind::Participation, EvidenceKind::Progress, EvidenceKind::Difficulty],
            $this->kindsInGroup(EvidenceInternalGroup::Learning),
        );
    }

    #[Test]
    public function the_behavior_attitudes_group_holds_exactly_incident_and_positive_behaviour(): void
    {
        $this->assertSame(
            [EvidenceKind::Incident, EvidenceKind::PositiveBehaviour],
            $this->kindsInGroup(EvidenceInternalGroup::BehaviorAttitudes),
        );
    }

    #[Test]
    public function the_follow_up_group_holds_exactly_the_four_accompaniment_kinds(): void
    {
        $this->assertSame(
            [EvidenceKind::Support, EvidenceKind::Contact, EvidenceKind::Activity, EvidenceKind::Note],
            $this->kindsInGroup(EvidenceInternalGroup::FollowUp),
        );
    }

    /** @return list<EvidenceKind> */
    private function kindsInGroup(EvidenceInternalGroup $group): array
    {
        return array_values(array_filter(
            EvidenceKind::cases(),
            fn (EvidenceKind $kind) => $kind->group() === $group,
        ));
    }
}
