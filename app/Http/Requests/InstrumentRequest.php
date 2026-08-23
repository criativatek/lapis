<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shape validation for an instrument and its items. The multi-row rules — domain
 * allocations totalling 100%, item points matching the declared total — live in
 * InstrumentBuilder, since they span rows.
 */
class InstrumentRequest extends FormRequest
{
    /**
     * Authorization is also checked here, not only in the controller: a
     * FormRequest's own validation runs on container resolution, before the
     * controller method body — including its Gate::authorize() call — ever
     * executes. Without this, a teacher outside the class would get a 422 for
     * a malformed payload instead of the 403 the route is supposed to give,
     * because validation always wins the race against the controller. Route
     * parameters are already bound (SubstituteBindings runs first), so both
     * store()'s {class} and update()'s {instrument} are available here.
     */
    public function authorize(): bool
    {
        $instrument = $this->route('instrument');

        if ($instrument instanceof Instrument) {
            return Gate::allows('update', $instrument->schoolClass);
        }

        $class = $this->route('class');

        if ($class instanceof SchoolClass) {
            return Gate::allows('update', $class);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quick' => ['sometimes', 'boolean'],
            'title' => ['required', 'string', 'max:200'],
            'academic_period_id' => ['required', new BelongsToCurrentOrganization(AcademicPeriod::class)],
            // Instrument types may be system-wide (organization_id NULL); the rule
            // runs through the model, whose visibility scope allows both. 0 is
            // never a real id (they start at 1) — it's the "Outro" sentinel, and
            // InstrumentController resolves it into a real (possibly brand-new)
            // InstrumentType from custom_instrument_type_name before this reaches
            // InstrumentBuilder, so it never needs to belong to the organization
            // itself.
            'instrument_type_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((int) $value === 0) {
                        return;
                    }

                    (new BelongsToCurrentOrganization(InstrumentType::class))->validate($attribute, $value, $fail);
                },
            ],
            'custom_instrument_type_name' => ['required_if:instrument_type_id,0', 'nullable', 'string', 'max:80'],
            'applied_on' => ['required', 'date'],
            // 'cancelled' is deliberately excluded: only InstrumentController::cancel()
            // may set it (it also records the mandatory reason and the status to
            // restore on revert), and only revertCancellation() may clear it. Allowing
            // it here would let a generic update silently produce a "cancelled"
            // instrument with none of that bookkeeping, which then crashes revert.
            'status' => [
                'required',
                Rule::when(
                    $this->boolean('quick'),
                    Rule::in(['prepared']),
                    Rule::in(['draft', 'prepared', 'in_correction', 'completed', 'published', 'archived']),
                ),
            ],
            'purpose' => ['required', Rule::in(['diagnostic', 'formative', 'summative', 'other'])],
            // Required on update — an existing instrument's own value must
            // always be explicit. Optional on create only: leaving it out of
            // the request entirely (as opposed to sending an explicit
            // `false`) is what lets InstrumentBuilder::create() tell
            // "the teacher never touched this" apart from "the teacher chose
            // false", and apply its diagnostic-purpose default only to the
            // former. 'sometimes' means "validate as boolean if present, skip
            // silently if absent" — never coerces a missing key into false.
            'counts_toward_classification' => [$this->route('instrument') instanceof Instrument ? 'required' : 'sometimes', 'boolean'],
            'total_points' => [
                Rule::when($this->boolean('quick'), 'required', 'nullable'),
                'numeric',
                'min:0',
                Rule::when($this->boolean('quick'), Rule::in([100, 100.0, '100', '100.0'])),
            ],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'allow_bonus' => ['required', 'boolean', Rule::when($this->boolean('quick'), 'declined')],
            'internal_notes' => ['nullable', 'string'],

            // Optional: an instrument with no groups submitted gets the implicit
            // one, so a simple test never has to declare structure. Labels are
            // not unique — two groups may share a name, since identity is the
            // ulid. Cross-instrument tampering is caught in the controller,
            // which knows which instrument is being edited.
            'groups' => ['sometimes', 'array', Rule::when($this->boolean('quick'), 'size:1')],
            'groups.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(InstrumentGroup::class, 'ulid')],
            'groups.*.label' => ['nullable', 'string', 'max:120', Rule::when($this->boolean('quick'), 'prohibited')],

            'items' => ['required', 'array', 'min:1', Rule::when($this->boolean('quick'), 'size:1')],
            'items.*' => ['array'],
            'items.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(InstrumentItem::class, 'ulid')],
            // Which submitted group the question sits in. A code is unique
            // within its group, not across the instrument — that rule spans
            // rows, so it lives in InstrumentBuilder::guard().
            'items.*.group_index' => [
                'nullable',
                'integer',
                'min:0',
                Rule::when($this->boolean('quick'), Rule::in([0])),
            ],
            'items.*.code' => [
                'required',
                'string',
                'max:16',
                Rule::when($this->boolean('quick'), Rule::in(['Q1'])),
            ],
            'items.*.label' => ['nullable', 'string', 'max:500'],
            'items.*.points_possible' => [
                'required',
                'numeric',
                'min:0',
                Rule::when($this->boolean('quick'), Rule::in([100, 100.0, '100', '100.0'])),
            ],
            'items.*.is_bonus' => ['nullable', 'boolean', Rule::when($this->boolean('quick'), 'declined')],
            'items.*.domains' => [
                Rule::when($this->boolean('quick'), ['required', 'array', 'size:1'], ['nullable', 'array']),
            ],
            'items.*.domains.*.domain_id' => ['required', new BelongsToCurrentOrganization(Domain::class)],
            'items.*.domains.*.allocation_percent' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                Rule::when($this->boolean('quick'), Rule::in([100, 100.0, '100', '100.0'])),
            ],
        ];
    }

    /**
     * IDs may belong to the current organization and still be wrong for this
     * class. These checks bind periods to its academic year and domains to its
     * active profile version, for both quick and detailed creation.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $class = $this->schoolClass();

            if ($class === null) {
                return;
            }

            $periodId = $this->input('academic_period_id');

            if ($periodId !== null && ! AcademicPeriod::whereKey($periodId)
                ->where('academic_year_id', $class->academic_year_id)->exists()) {
                $validator->errors()->add('academic_period_id', 'O período selecionado não pertence ao ano letivo da turma.');
            }

            $validDomainIds = $class->profileVersion?->domains()->pluck('domain_id') ?? collect();
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $itemIndex => $item) {
                if (! is_array($item) || ! is_array($item['domains'] ?? null)) {
                    continue;
                }

                foreach ($item['domains'] as $allocationIndex => $allocation) {
                    if (! is_array($allocation) || ! isset($allocation['domain_id'])) {
                        continue;
                    }

                    if ($validDomainIds->contains((int) $allocation['domain_id'])) {
                        continue;
                    }

                    $validator->errors()->add(
                        "items.{$itemIndex}.domains.{$allocationIndex}.domain_id",
                        'O domínio selecionado não pertence ao perfil ativo da turma.',
                    );

                    break;
                }
            }
        }];
    }

    protected function schoolClass(): ?SchoolClass
    {
        $instrument = $this->route('instrument');

        if ($instrument instanceof Instrument) {
            return $instrument->schoolClass;
        }

        $class = $this->route('class');

        return $class instanceof SchoolClass ? $class : null;
    }
}
