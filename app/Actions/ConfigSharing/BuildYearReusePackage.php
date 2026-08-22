<?php

declare(strict_types=1);

namespace App\Actions\ConfigSharing;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;

final class BuildYearReusePackage
{
    public function __construct(private readonly GenerateConfigurationPackage $generate) {}

    /** @return array<string, mixed> */
    public function handle(AssessmentProfile $source, AcademicYear $target): array
    {
        abort_if($source->academic_year_id === $target->id, 422, __('Escolha um ano letivo diferente do atual.'));

        $package = $this->generate->handle(['assessment_profiles' => [$source->ulid]]);
        $package['payload']['assessment_profiles'][0]['academic_year_label'] = $target->label;
        $package['payload']['academic_years'] = [];
        $package['components'] = array_values(array_filter(
            $package['components'],
            fn (string $component): bool => $component !== 'academic_years',
        ));

        return $package;
    }
}
