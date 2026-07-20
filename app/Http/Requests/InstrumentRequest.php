<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation for an instrument and its items. The multi-row rules — domain
 * allocations totalling 100%, item points matching the declared total — live in
 * InstrumentBuilder, since they span rows.
 */
class InstrumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'academic_period_id' => ['required', new BelongsToCurrentOrganization(AcademicPeriod::class)],
            // Instrument types may be system-wide (organization_id NULL); the rule
            // runs through the model, whose visibility scope allows both.
            'instrument_type_id' => ['required', new BelongsToCurrentOrganization(InstrumentType::class)],
            'applied_on' => ['required', 'date'],
            'status' => ['required', Rule::in(['draft', 'prepared', 'in_correction', 'completed', 'published', 'cancelled', 'archived'])],
            'purpose' => ['required', Rule::in(['diagnostic', 'formative', 'summative', 'other'])],
            'counts_toward_classification' => ['required', 'boolean'],
            'total_points' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'allow_bonus' => ['required', 'boolean'],
            'internal_notes' => ['nullable', 'string'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.code' => ['required', 'string', 'max:16'],
            'items.*.label' => ['nullable', 'string', 'max:500'],
            'items.*.points_possible' => ['required', 'numeric', 'min:0'],
            'items.*.is_bonus' => ['nullable', 'boolean'],
            'items.*.domains' => ['nullable', 'array'],
            'items.*.domains.*.domain_id' => ['required', new BelongsToCurrentOrganization(Domain::class)],
            'items.*.domains.*.allocation_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
