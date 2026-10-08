<?php

namespace App\Http\Requests\Lessons;

use App\Models\ClassGroup;
use App\Models\LessonSequence;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Serve a pré-visualização e a aplicação: o MESMO pedido, validado da mesma
 * maneira — só o `plan_token` é exigido apenas na aplicação.
 */
class LessonSequenceApplyRequest extends FormRequest
{
    /**
     * Whether the actor teaches the chosen class, and whether the class is
     * actually compatible with the sequence (subject, grade_level), are both
     * checked by ApplyLessonSequence itself — the authoritative gate, not
     * duplicated here. This only confirms the sequence being applied is the
     * requester's own.
     */
    public function authorize(): bool
    {
        $sequence = $this->route('lessonSequence');

        return $sequence instanceof LessonSequence && $this->user()?->can('view', $sequence) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            'class_group_id' => ['nullable', 'integer'],
            // Um dia do calendário, no fuso da aplicação — nunca um instante.
            'from' => ['required', 'date_format:Y-m-d'],
            'summary' => ['sometimes', 'boolean'],
            'resources' => ['sometimes', 'boolean'],
            'homework' => ['sometimes', 'boolean'],
            'private_notes' => ['sometimes', 'boolean'],
            'replace' => ['sometimes', 'array'],
            'replace.*' => ['string', 'max:64'],
            'confirm_replace' => ['sometimes', 'boolean'],
            'plan_token' => [$this->isApply() ? 'required' : 'nullable', 'string', 'size:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');

            if (is_string($from) && ! $validator->errors()->has('from')) {
                $today = CarbonImmutable::now(config('app.timezone'))->format('Y-m-d');

                if ($from < $today) {
                    $validator->errors()->add('from', __('Escolhe hoje ou uma data posterior — as aulas anteriores nunca são alteradas.'));
                }
            }

            $selected = false;

            foreach (['summary', 'resources', 'homework', 'private_notes'] as $field) {
                $selected = $selected || $this->boolean($field);
            }

            if (! $selected) {
                $validator->errors()->add('summary', __('Escolhe pelo menos um campo a copiar.'));
            }

            $groupId = $this->classGroupId();
            $classId = $this->input('class_id');

            if ($groupId === null || ! is_numeric($classId) || $validator->errors()->has('class_id')) {
                return;
            }

            // O grupo tem de ser DESTA turma (e estar ativo): a organização
            // sozinha deixava passar o id de um grupo de outra turma minha.
            $group = ClassGroup::query()->find($groupId);

            if ($group === null || $group->class_id !== (int) $classId || $group->isArchived()) {
                $validator->errors()->add('class_group_id', __('O grupo escolhido não pertence a esta turma ou já não está ativo.'));
            }
        });
    }

    public function classGroupId(): ?int
    {
        $value = $this->input('class_group_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function fromDate(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated('from'), config('app.timezone'))->startOfDay();
    }

    /**
     * @return list<string>
     */
    public function replaceUlids(): array
    {
        /** @var list<string> $replace */
        $replace = array_values((array) ($this->validated('replace') ?? []));

        return $replace;
    }

    private function isApply(): bool
    {
        return $this->routeIs('lessons.sequences.apply');
    }
}
