<?php

namespace App\Http\Requests\Lessons;

use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InsertLessonRequest extends FormRequest
{
    private ?SchoolClass $resolvedClass = null;

    public function authorize(): bool
    {
        $schoolClass = $this->findSchoolClass();

        return $schoolClass !== null
            && $this->user()?->can('create', [Lesson::class, $schoolClass]) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Pelo ULID e nunca pelo id sequencial (§11.2): é o ULID que
            // aparece nas rotas e no ecrã, e é dele que o front-end dispõe.
            'class' => ['required', 'string'],
            'class_group_id' => ['nullable', 'integer'],
            'insert_at' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schoolClass = $this->findSchoolClass();

            if ($schoolClass === null) {
                $validator->errors()->add('class', __('Turma não encontrada.'));

                return;
            }

            $classGroupId = $this->classGroupId();

            if ($classGroupId === null) {
                return;
            }

            // O grupo tem de ser DESTA turma. `BelongsToCurrentOrganization`
            // garantiria a organização e mais nada — o id de um grupo de outra
            // turma minha passaria por lá sem tropeçar —, e é a turma que
            // define a sequência em que a aula é inserida.
            $classGroup = ClassGroup::query()->find($classGroupId);

            if ($classGroup === null || $classGroup->class_id !== $schoolClass->getKey()) {
                $validator->errors()->add(
                    'class_group_id',
                    __('O grupo escolhido não pertence a esta turma.'),
                );
            }
        });
    }

    public function schoolClass(): SchoolClass
    {
        $schoolClass = $this->findSchoolClass();

        abort_if($schoolClass === null, 404);

        return $schoolClass;
    }

    public function classGroupId(): ?int
    {
        $value = $this->input('class_group_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Resolvida uma só vez por pedido: `authorize()`, `withValidator()` e o
     * controlador fazem-lhe todos a mesma pergunta, e o global scope de
     * organização já limita a busca ao tenant em curso.
     */
    private function findSchoolClass(): ?SchoolClass
    {
        if ($this->resolvedClass !== null) {
            return $this->resolvedClass;
        }

        $ulid = $this->input('class');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return $this->resolvedClass = SchoolClass::query()->where('ulid', $ulid)->first();
    }
}
