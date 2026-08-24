<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use App\Models\AcademicPeriodKind;
use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates an academic year and its periods together — a year is created with
 * its periods in one step (§9), so they are validated in one request.
 *
 * All validation is server-side (§22.2). The uniqueness rule is scoped to the
 * resolved organization, not a bare unique:, so it cannot collide with another
 * organization's label.
 */
class AcademicYearRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = app(CurrentOrganization::class)->id();
        $year = $this->route('academic_year');
        $yearId = $year instanceof AcademicYear ? $year->id : null;

        return [
            'label' => [
                'required', 'string', 'max:32',
                Rule::unique('academic_years', 'label')
                    ->where('organization_id', $organizationId)
                    ->ignore($yearId),
            ],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'status' => ['required', Rule::enum(AcademicYearStatus::class)],
            'country_code' => ['required', 'string', 'size:2'],
            'region_code' => ['nullable', 'string', 'max:8'],

            'periods' => ['required', 'array', 'min:1'],
            // Absent/null is a brand-new period. When present, it must at
            // least belong to the current organization — whether it belongs
            // to THIS year is checked below, in after(), since that needs
            // the resolved $academicYear rather than a bare column rule.
            'periods.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(AcademicPeriod::class, 'ulid')],
            'periods.*.label' => ['required', 'string', 'max:64'],
            'periods.*.kind' => ['required', Rule::enum(AcademicPeriodKind::class)],
            'periods.*.sequence' => ['required', 'integer', 'min:1', 'max:255'],
            'periods.*.starts_on' => ['required', 'date'],
            'periods.*.ends_on' => ['required', 'date', 'after:periods.*.starts_on'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $periods = $this->input('periods', []);

            // Period sequences must be unique within the year — the same rule the
            // UNIQUE(academic_year_id, sequence) index enforces, surfaced as a
            // clear message instead of a database error.
            $sequences = array_column($periods, 'sequence');
            if (count($sequences) !== count(array_unique($sequences))) {
                $validator->errors()->add('periods', __('Cada período deve ter uma ordem distinta.'));
            }

            // Every period must fall inside the academic year.
            $yearStart = $this->input('starts_on');
            $yearEnd = $this->input('ends_on');
            foreach ($periods as $index => $period) {
                if (isset($period['starts_on'], $period['ends_on'])
                    && ($period['starts_on'] < $yearStart || $period['ends_on'] > $yearEnd)) {
                    $validator->errors()->add(
                        "periods.{$index}.starts_on",
                        __('O período tem de estar dentro do ano letivo.'),
                    );
                }
            }

            // A period ulid that belongs to the current organization is still
            // not necessarily THIS year's own — without this, editing one
            // year could smuggle in (and later silently adopt, or even
            // remove) another year's period. Mirrors LessonSequenceRequest's
            // identical check for its own items.
            $year = $this->route('academic_year');
            if ($year instanceof AcademicYear) {
                $ownUlids = $year->periods()->pluck('ulid');
                foreach ($periods as $index => $period) {
                    $ulid = is_array($period) ? ($period['ulid'] ?? null) : null;
                    if ($ulid !== null && ! $ownUlids->contains($ulid)) {
                        $validator->errors()->add(
                            "periods.{$index}.ulid",
                            __('O período selecionado não pertence a este ano letivo.'),
                        );
                    }
                }
            }
        });
    }
}
