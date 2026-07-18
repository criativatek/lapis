<?php

namespace App\Http\Requests;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Scale;
use App\Models\Subject;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a profile and the domains of its draft version.
 *
 * The year, subject and scale ids are checked with BelongsToCurrentOrganization
 * (or the scale's own visibility), never a bare exists:, so an id from another
 * organization is rejected. Domain weights are validated for shape here; the
 * 100% total is an activation-time gate, since a draft may be incomplete.
 */
class AssessmentProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $profile = $this->route('assessment_profile');
        $profileId = $profile instanceof AssessmentProfile ? $profile->id : null;

        return [
            'name' => [
                'required', 'string', 'max:160',
                Rule::unique('assessment_profiles', 'name')
                    ->where('organization_id', $organizationId)
                    ->where('academic_year_id', $this->input('academic_year_id'))
                    ->where('subject_id', $this->input('subject_id'))
                    ->where('grade_level', $this->input('grade_level'))
                    ->ignore($profileId),
            ],
            'academic_year_id' => ['required', new BelongsToCurrentOrganization(AcademicYear::class)],
            'subject_id' => ['required', new BelongsToCurrentOrganization(Subject::class)],
            'grade_level' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string'],
            // Scale may be a system scale (org NULL) or the organization's own.
            // The rule runs through the model, whose visibility scope allows both
            // and rejects another organization's scale — unlike a bare exists:.
            'scale_id' => ['required', new BelongsToCurrentOrganization(Scale::class)],

            'domains' => ['required', 'array', 'min:1'],
            'domains.*.name' => ['required', 'string', 'max:120'],
            'domains.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
