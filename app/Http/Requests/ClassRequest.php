<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $class = $this->route('class');
        $classId = $class instanceof SchoolClass ? $class->id : null;

        return [
            'label' => [
                'required', 'string', 'max:64',
                Rule::unique('classes', 'label')
                    ->where('organization_id', $organizationId)
                    ->where('academic_year_id', $this->input('academic_year_id'))
                    ->where('subject_id', $this->input('subject_id'))
                    ->ignore($classId),
            ],
            'academic_year_id' => ['required', new BelongsToCurrentOrganization(AcademicYear::class)],
            'subject_id' => ['required', new BelongsToCurrentOrganization(Subject::class)],
            'grade_level' => ['nullable', 'string', 'max:16'],
            // The active profile version this class is assessed by. Optional at
            // creation; a class can be set up before its profile is chosen.
            'assessment_profile_version_id' => ['nullable', new BelongsToCurrentOrganization(AssessmentProfileVersion::class)],
        ];
    }
}
