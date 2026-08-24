<?php

namespace App\Http\Requests\Lessons;

use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The batch equivalent of RecurringLessonSlotRequest — same rules, same policy,
 * same tenancy check, applied once per row.
 *
 * NOTHING THE PREVIEW SAID IS TRUSTED HERE. The browser is handed class ids so
 * it can render a screen, and it hands them back; every one of them is
 * re-resolved through the tenant-scoped model and re-authorised through
 * RecurringLessonSlotPolicy before a single slot is created. A turma from
 * another organization simply does not resolve — the scope hides it — so the
 * answer is «não autorizado» and never «esse registo não existe», which would be
 * a way of asking this application whether an id is real.
 *
 * A REJECTED ROW REJECTS THE WHOLE BATCH, deliberately. Authorisation failing on
 * any row means the payload is not one this teacher could have produced by
 * reviewing their own preview, so none of it is written. Duplicates and
 * conflicts are an entirely different matter — they are ordinary, expected, and
 * skipped row by row with a count in the result (see TimetableImportController).
 */
class TimetableImportConfirmRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $rows = $this->input('rows');

        if (! is_array($rows)) {
            // A malformed payload is a validation failure, not a permission
            // one — and creates nothing either way, since rules() refuses it.
            return true;
        }

        foreach ($this->classIds($rows) as $classId) {
            $schoolClass = SchoolClass::query()->find($classId);

            if ($schoolClass === null || ! $user->can('create', [RecurringLessonSlot::class, $schoolClass])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $endsOnRules = ['nullable', 'date_format:Y-m-d'];

        if ($this->filled('starts_on')) {
            $endsOnRules[] = 'after_or_equal:starts_on';
        }

        return [
            // One validity window for the whole import, exactly as the preview
            // presents it. Blank means blank: the slot is then bounded by its
            // turma's own academic year at materialisation, which is what a
            // teacher who leaves the manual form's dates empty already gets.
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => $endsOnRules,
            // Display only, and incapable of affecting what is written: it is
            // read exclusively to phrase the closing message.
            'unassociated_count' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            'rows.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'rows.*.starts_at' => ['required', 'date_format:H:i'],
            'rows.*.ends_at' => ['required', 'date_format:H:i', 'after:rows.*.starts_at'],
            'rows.*.include' => ['required', 'boolean'],
        ];
    }

    /**
     * The distinct turmas this payload wants to write to.
     *
     * Non-numeric ids are skipped rather than queried: rules() rejects them
     * cleanly as validation errors, and a string reaching a query on an integer
     * key is never worth the risk.
     *
     * @param  array<mixed>  $rows
     * @return list<int>
     */
    protected function classIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['class_id'])) {
                continue;
            }

            $classId = $row['class_id'];

            if (is_int($classId) || (is_string($classId) && ctype_digit($classId))) {
                $ids[(int) $classId] = true;
            }
        }

        return array_keys($ids);
    }
}
