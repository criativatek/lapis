<?php

namespace Tests\Feature\Retention;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\Organization;
use App\Support\Retention\AcademicYearRetentionClassifier;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pedagogical data retention: current academic year + N previous years
 * (config('retention.pedagogical_previous_years_retained')). The classifier
 * counts in ACADEMIC YEARS by starts_on ordering, never by created_at or any
 * other raw timestamp — see App\Support\Retention\AcademicYearRetentionClassifier.
 *
 * This is Feature (not Unit) because it needs real AcademicYear Eloquent
 * models, which are tenant-scoped — see App\Support\Tenancy\CurrentOrganization.
 */
class AcademicYearRetentionClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifier(): AcademicYearRetentionClassifier
    {
        return new AcademicYearRetentionClassifier(new RetentionPolicy);
    }

    /**
     * Builds $count academic years for a fresh organization, oldest first,
     * starting at 2000, and returns them ordered oldest-to-newest so the
     * caller can index into them by how far back they are.
     *
     * @return Collection<int, AcademicYear>
     */
    private function academicYears(int $count, ?int $activeIndex = null): Collection
    {
        $organization = Organization::factory()->institutional()->create();

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $count, $activeIndex): Collection {
            $years = collect();

            for ($index = 0; $index < $count; $index++) {
                $startYear = 2000 + $index;

                $years->push(AcademicYear::factory()->create([
                    'organization_id' => $organization->id,
                    'label' => sprintf('%d/%d', $startYear, $startYear + 1),
                    'starts_on' => sprintf('%d-09-01', $startYear),
                    'ends_on' => sprintf('%d-08-31', $startYear + 1),
                    'status' => $activeIndex === $index ? AcademicYearStatus::Active : AcademicYearStatus::Draft,
                ]));
            }

            return $years;
        });
    }

    /**
     * @param  Collection<int, array{year: AcademicYear, within_retention: bool}>  $classified
     * @return array<int, string>
     */
    private function withinRetentionLabels(Collection $classified): array
    {
        return $classified
            ->filter(fn (array $entry): bool => $entry['within_retention'])
            ->map(fn (array $entry): string => $entry['year']->label)
            ->sort()
            ->values()
            ->all();
    }

    #[Test]
    public function the_current_year_alone_is_within_retention(): void
    {
        $years = $this->academicYears(1);
        $currentYear = $years->last();

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(['2000/2001'], $this->withinRetentionLabels($classified));
    }

    #[Test]
    public function current_plus_one_previous_year_are_both_within_retention(): void
    {
        $years = $this->academicYears(2);
        $currentYear = $years->last(); // 2001/2002

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(['2000/2001', '2001/2002'], $this->withinRetentionLabels($classified));
    }

    #[Test]
    public function current_plus_two_previous_years_are_all_within_retention(): void
    {
        $years = $this->academicYears(3);
        $currentYear = $years->last(); // 2002/2003

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(['2000/2001', '2001/2002', '2002/2003'], $this->withinRetentionLabels($classified));
    }

    #[Test]
    public function current_plus_three_previous_years_are_all_within_retention(): void
    {
        $years = $this->academicYears(4);
        $currentYear = $years->last(); // 2003/2004

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(
            ['2000/2001', '2001/2002', '2002/2003', '2003/2004'],
            $this->withinRetentionLabels($classified),
        );
    }

    #[Test]
    public function the_fourth_previous_year_falls_outside_retention(): void
    {
        $years = $this->academicYears(5);
        $currentYear = $years->last(); // 2004/2005 — current + 3 previous = 4 years total, 2000/2001 is the 4th previous

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(
            ['2001/2002', '2002/2003', '2003/2004', '2004/2005'],
            $this->withinRetentionLabels($classified),
        );
    }

    #[Test]
    public function with_ten_years_only_the_newest_four_are_within_retention(): void
    {
        $years = $this->academicYears(10);
        $currentYear = $years->last(); // 2009/2010

        $classified = $this->classifier()->classify($years, $currentYear);

        $this->assertSame(
            ['2006/2007', '2007/2008', '2008/2009', '2009/2010'],
            $this->withinRetentionLabels($classified),
        );
    }

    #[Test]
    public function current_year_for_picks_the_single_active_year(): void
    {
        $years = $this->academicYears(3, activeIndex: 1);

        $currentYear = $this->classifier()->currentYearFor($years);

        $this->assertNotNull($currentYear);
        $this->assertSame('2001/2002', $currentYear->label);
    }

    #[Test]
    public function current_year_for_falls_back_to_the_most_recent_by_starts_on_when_none_is_active(): void
    {
        $years = $this->academicYears(3);

        $currentYear = $this->classifier()->currentYearFor($years);

        $this->assertNotNull($currentYear);
        $this->assertSame('2002/2003', $currentYear->label);
    }

    #[Test]
    public function current_year_for_returns_null_for_an_empty_collection(): void
    {
        $currentYear = $this->classifier()->currentYearFor(collect());

        $this->assertNull($currentYear);
    }
}
