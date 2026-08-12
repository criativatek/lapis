<?php

namespace Tests\Feature\Evidence;

use App\Models\EvidenceInternalGroup;
use App\Models\EvidenceRecord;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The reusable query scopes on EvidenceRecord (§10, §12): who can read them
 * (autoSelectableForReport) never depends on incidental field values, only on
 * kind + the explicit flag — and inGroup() must resolve its member kinds from
 * EvidenceKind::group() alone, never a second, hand-kept list.
 */
class EvidenceRecordScopeTest extends TestCase
{
    use RefreshDatabase;

    private function seedClass(): SchoolClass
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            fn () => SchoolClass::where('label', '7.º A')->firstOrFail(),
        );
    }

    #[Test]
    public function only_an_activity_flagged_for_the_report_is_auto_selectable(): void
    {
        $class = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($class): void {
            $this->makeRecord($class, 'activity', ['activity_include_in_report' => true]);
            $this->makeRecord($class, 'activity', ['activity_include_in_report' => false]);
            $this->makeRecord($class, 'activity', ['activity_include_in_report' => null]);
            // Contact/note/support can never carry this flag through the app, but the
            // scope itself must gate on kind, not merely on the flag being true.
            $this->makeRecord($class, 'contact', ['activity_include_in_report' => true]);
            $this->makeRecord($class, 'note', ['activity_include_in_report' => true]);
            $this->makeRecord($class, 'support', ['activity_include_in_report' => true]);

            $selectable = EvidenceRecord::autoSelectableForReport()->get();

            $this->assertCount(1, $selectable);
            $this->assertSame('activity', $selectable->first()->kind->value);
            $this->assertTrue($selectable->first()->activity_include_in_report);
        });
    }

    #[Test]
    public function in_group_resolves_its_member_kinds_from_evidence_kind_group(): void
    {
        $class = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($class): void {
            $this->makeRecord($class, 'homework');
            $this->makeRecord($class, 'progress');
            $this->makeRecord($class, 'incident', ['disciplinary_severity' => 'g3']);
            $this->makeRecord($class, 'note');
            $this->makeRecord($class, 'contact');

            $learning = EvidenceRecord::inGroup(EvidenceInternalGroup::Learning)->pluck('kind');
            $behaviorAttitudes = EvidenceRecord::inGroup(EvidenceInternalGroup::BehaviorAttitudes)->pluck('kind');
            $followUp = EvidenceRecord::inGroup(EvidenceInternalGroup::FollowUp)->pluck('kind');

            $this->assertSame(['homework', 'progress'], $learning->map(fn ($kind) => $kind->value)->sort()->values()->all());
            $this->assertSame(['incident'], $behaviorAttitudes->map(fn ($kind) => $kind->value)->all());
            $this->assertSame(['contact', 'note'], $followUp->map(fn ($kind) => $kind->value)->sort()->values()->all());
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRecord(SchoolClass $class, string $kind, array $attributes = []): EvidenceRecord
    {
        return EvidenceRecord::create(array_merge([
            'class_id' => $class->id,
            'occurred_at' => '2026-10-20',
            'kind' => $kind,
            'description' => 'Registo de teste.',
            'created_by' => User::where('email', 'ana.martins@lapis.test')->firstOrFail()->id,
        ], $attributes));
    }
}
