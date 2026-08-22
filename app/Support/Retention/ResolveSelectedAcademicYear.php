<?php

namespace App\Support\Retention;

use App\Models\AcademicYear;
use Illuminate\Support\Collection;

final class ResolveSelectedAcademicYear
{
    public function __construct(private readonly AcademicYearRetentionClassifier $classifier) {}

    /**
     * @param  Collection<int, AcademicYear>  $academicYears
     */
    public function for(Collection $academicYears, ?int $sessionSelectedId): ?AcademicYear
    {
        if ($sessionSelectedId !== null) {
            $selectedAcademicYear = $academicYears->first(
                fn (AcademicYear $academicYear): bool => $academicYear->getKey() === $sessionSelectedId
            );

            if ($selectedAcademicYear !== null) {
                return $selectedAcademicYear;
            }
        }

        return $this->classifier->currentYearFor($academicYears);
    }
}
