<?php

namespace App\Http\Requests\ClassGroups;

use App\Models\ClassGroup;
use App\Models\SchoolClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criar ou renomear um grupo.
 *
 * O RÓTULO É LIVRE. «T1» não está escrito em lado nenhum do código — a
 * validação é de forma (não vazio, até 40 caracteres) e de unicidade DENTRO da
 * turma. «A» e «B», «PL1» e «PL2», «Grupo da manhã»: todos valem.
 *
 * A unicidade é escrita com `Rule::unique(...)->where('class_id', ...)` e não
 * com um `unique:class_groups,label` simples, que compararia o rótulo com o de
 * todas as turmas de todas as organizações. Aqui a coluna `class_id` do
 * `where` é a da turma já resolvida pela rota — dentro do tenant, portanto —, e
 * é a mesma fronteira que o índice `class_groups_class_label_unique` desenha na
 * base de dados.
 */
class ClassGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $classGroup = $this->route('classGroup');

        if ($classGroup instanceof ClassGroup) {
            return $user->can('update', $classGroup);
        }

        $schoolClass = $this->route('class');

        return $schoolClass instanceof SchoolClass
            && $user->can('create', [ClassGroup::class, $schoolClass]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $classGroup = $this->route('classGroup');
        $classId = $classGroup instanceof ClassGroup
            ? $classGroup->class_id
            : $this->schoolClass()?->getKey();

        $unique = Rule::unique('class_groups', 'label')->where('class_id', $classId);

        if ($classGroup instanceof ClassGroup) {
            $unique = $unique->ignore($classGroup->getKey());
        }

        return [
            'label' => ['required', 'string', 'max:40', $unique],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.required' => __('Dê um nome ao grupo.'),
            'label.unique' => __('Já existe um grupo com esse nome nesta turma.'),
            'label.max' => __('O nome do grupo não pode ter mais de 40 caracteres.'),
        ];
    }

    public function schoolClass(): ?SchoolClass
    {
        $schoolClass = $this->route('class');

        return $schoolClass instanceof SchoolClass ? $schoolClass : null;
    }
}
