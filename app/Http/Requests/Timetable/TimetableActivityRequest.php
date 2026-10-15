<?php

namespace App\Http\Requests\Timetable;

use App\Models\SchoolClass;
use App\Models\TimetableActivity;
use App\Models\TimetableActivityKind;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TimetableActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $activity = $this->route('timetableActivity');

        if ($activity instanceof TimetableActivity) {
            // `Gate::authorize` e não `can()`: a política responde 404 a quem
            // não é o dono, e um `false` aqui viraria um 403 que confirma que
            // a atividade existe.
            Gate::forUser($user)->authorize('update', $activity);

            return true;
        }

        return $user->can('create', TimetableActivity::class);
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
            'kind' => ['required', 'string', Rule::enum(TimetableActivityKind::class)],
            'title' => ['required', 'string', 'max:60'],
            // A turma é só uma referência (a «DT 6.º D»): tem de ser desta
            // organização — `BelongsToCurrentOrganization` e nunca `exists:` —
            // e uma turma que o professor leciona, verificado em `after()`.
            'class_ulid' => ['nullable', 'string', 'size:26', new BelongsToCurrentOrganization(SchoolClass::class, 'ulid')],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => $endsOnRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo',
            'title' => 'designação',
            'class_ulid' => 'turma',
            'day_of_week' => 'dia',
            'starts_at' => 'início',
            'ends_at' => 'fim',
            'starts_on' => 'válido desde',
            'ends_on' => 'válido até',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after($this->validateTeachesClass(...));
        $validator->after($this->validateNoOverlap(...));
    }

    /**
     * A turma referida, dentro da organização atual.
     */
    public function schoolClass(): ?SchoolClass
    {
        $ulid = $this->input('class_ulid');

        return is_string($ulid) && $ulid !== ''
            ? SchoolClass::query()->where('ulid', $ulid)->first()
            : null;
    }

    private function validateTeachesClass(Validator $validator): void
    {
        $user = $this->user();

        if ($validator->errors()->isNotEmpty() || ! $user instanceof User) {
            return;
        }

        $schoolClass = $this->schoolClass();

        if ($schoolClass !== null && ! $schoolClass->teachers()->whereKey($user->getKey())->exists()) {
            $validator->errors()->add('class_ulid', __('Só podes referir uma turma que lecionas.'));
        }
    }

    /**
     * Duas atividades do MESMO professor não se pisam: mesmo dia, horas que se
     * cruzam e vigências que se cruzam. Contra aulas não se verifica nada — os
     * tempos de aula também não se verificam entre turmas.
     */
    private function validateNoOverlap(Validator $validator): void
    {
        $user = $this->user();

        if ($validator->errors()->isNotEmpty() || ! $user instanceof User) {
            return;
        }

        $startsOn = $this->filled('starts_on') ? (string) $this->input('starts_on') : null;
        $endsOn = $this->filled('ends_on') ? (string) $this->input('ends_on') : null;
        $own = $this->route('timetableActivity');
        $ownId = $own instanceof TimetableActivity ? $own->getKey() : null;

        $clash = TimetableActivity::query()
            ->where('user_id', $user->getKey())
            ->where('day_of_week', $this->integer('day_of_week'))
            ->when($ownId !== null, fn (Builder $query) => $query->whereKeyNot($ownId))
            ->where('starts_at', '<', $this->string('ends_at')->toString().':00')
            ->where('ends_at', '>', $this->string('starts_at')->toString().':00')
            ->when($endsOn !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('starts_on')->orWhere('starts_on', '<=', $endsOn),
            ))
            ->when($startsOn !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn),
            ))
            ->first();

        if ($clash !== null) {
            $validator->errors()->add('starts_at', __('Já tens a atividade «:title» (:start–:end) neste dia, em vigor no mesmo período.', [
                'title' => $clash->title,
                'start' => substr($clash->starts_at, 0, 5),
                'end' => substr($clash->ends_at, 0, 5),
            ]));
        }
    }
}
