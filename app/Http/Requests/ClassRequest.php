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

        // Editing an existing class (Fatia D) only ever changes the label —
        // academic_year_id/subject_id/grade_level stay whatever the class
        // already has, never whatever the client happens to send, because
        // they feed reporting, config-sharing and profile-matching logic
        // that assumes a class's year/subject/grade are stable once
        // enrollments or instruments exist against them. The uniqueness
        // scope below reads the class's own stored values on update, so it
        // stays correct even though those two fields are no longer required
        // on this route.
        $academicYearId = $classId !== null ? $class->academic_year_id : $this->input('academic_year_id');
        $subjectId = $classId !== null ? $class->subject_id : $this->input('subject_id');

        return [
            'label' => [
                'required', 'string', 'max:64',
                Rule::unique('classes', 'label')
                    ->where('organization_id', $organizationId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('subject_id', $subjectId)
                    ->ignore($classId),
            ],
            'academic_year_id' => [$classId !== null ? 'sometimes' : 'required', new BelongsToCurrentOrganization(AcademicYear::class)],
            'subject_id' => [$classId !== null ? 'sometimes' : 'required', new BelongsToCurrentOrganization(Subject::class)],
            'grade_level' => ['nullable', 'string', 'max:16'],
            // «Turma de apoio». Opcional e falso por omissão: sem ele a turma é
            // exatamente o que sempre foi.
            'is_support_class' => ['sometimes', 'boolean'],
            // The active profile version this class is assessed by. Optional at
            // creation; a class can be set up before its profile is chosen.
            'assessment_profile_version_id' => ['nullable', new BelongsToCurrentOrganization(AssessmentProfileVersion::class)],
        ];
    }
}
