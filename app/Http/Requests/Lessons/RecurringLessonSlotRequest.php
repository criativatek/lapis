<?php

namespace App\Http\Requests\Lessons;

use App\Models\ClassGroup;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecurringLessonSlotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $schoolClass = SchoolClass::query()->find($this->integer('class_id'));

        if ($user === null || $schoolClass === null) {
            return false;
        }

        $recurringLessonSlot = $this->route('recurringLessonSlot');

        if ($recurringLessonSlot instanceof RecurringLessonSlot) {
            return $recurringLessonSlot->class_id === $schoolClass->id
                && $user->can('update', $recurringLessonSlot);
        }

        return $user->can('create', [RecurringLessonSlot::class, $schoolClass]);
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
            'class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            // «Participantes»: NULL é a turma inteira, e é o que todos os
            // tempos já existentes dizem — nenhum backfill, nenhum professor
            // tem de rever o horário que já configurou (§22 do briefing).
            //
            // `new BelongsToCurrentOrganization(...)` e nunca `exists:`: um
            // `exists:` corre no query builder e não vê o global scope, pelo
            // que aceitaria o id de um grupo de outra escola. Que o grupo seja
            // DESTA turma, e que não esteja arquivado, é verificado em
            // `withValidator()` — nenhuma regra estática sabe isso.
            'class_group_id' => [
                'nullable', 'integer', new BelongsToCurrentOrganization(ClassGroup::class),
            ],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => $endsOnRules,
            // "Aplicar alteração a partir de" — required exactly when the
            // route-bound slot requires versioning (§ RecurringLessonSlot::
            // requiresVersioning(), § ReviseRecurringLessonSlot): that is the
            // one case where an in-place update would rewrite history, so the
            // request must say from when the new version starts instead. A
            // not-yet-started slot — including one whose starts_on is exactly
            // today but has produced no Lesson yet — or a fresh create (where
            // there is no route-bound slot at all) never needs it.
            'effective_from' => [
                Rule::requiredIf($this->routeSlotRequiresVersioning()),
                'nullable',
                'date_format:Y-m-d',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_from.required' => __('Indique a partir de quando a alteração passa a vigorar.'),
            'effective_from.date_format' => __('Indique uma data de entrada em vigor válida.'),
        ];
    }

    /**
     * As duas invariantes que `effective_from` tem de respeitar para que a
     * versão histórica que fecha e a versão nova que nasce fiquem as duas
     * bem formadas — nunca verificáveis por uma regra estática porque as
     * duas comparam com algo que só existe em tempo de pedido: "hoje", e o
     * `starts_on` da própria linha.
     *
     * A DE HOJE PRIMEIRO: não é possível recuar uma alteração para uma data
     * já passada. A SEGUIR, e só quando a linha atual tem um `starts_on`
     * definido: `effective_from` tem de ficar estritamente depois dele —
     * fechar a versão atual no próprio dia (ou antes) em que ela começou
     * produziria um `ends_on` menor que o `starts_on`, o que a CHECK da
     * tabela já recusaria, e a mensagem aqui é a versão legível disso.
     *
     * QUANDO O `starts_on` DA LINHA ATUAL É EXATAMENTE HOJE, as duas regras
     * juntas não deixam passar nada antes de amanhã — `effective_from` tem
     * de ser `>= hoje` (primeira regra) E `> hoje` (segunda, porque
     * `starts_on` é hoje), o que só amanhã ou mais tarde satisfaz as duas ao
     * mesmo tempo. Não é um impasse: é exatamente "qualquer alteração entra
     * em vigor, no mínimo, amanhã" — a versão de hoje já aconteceu (ou ainda
     * pode acontecer) e fica intacta. Uma linha que começou hoje mas ainda
     * não produziu nenhuma Lesson nem chega aqui: RecurringLessonSlot::
     * requiresVersioning() manda-a pelo caminho de edição/remoção simples,
     * onde `effective_from` nem é pedido.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after($this->validateClassGroup(...));

        $validator->after(function (Validator $validator): void {
            $recurringLessonSlot = $this->route('recurringLessonSlot');
            $effectiveFrom = $this->input('effective_from');

            if (! $recurringLessonSlot instanceof RecurringLessonSlot
                || ! is_string($effectiveFrom)
                || $effectiveFrom === '') {
                return;
            }

            $today = CarbonImmutable::now(app(CurrentOrganization::class)->get()->timezone)
                ->startOfDay()
                ->toDateString();

            if ($effectiveFrom < $today) {
                $validator->errors()->add(
                    'effective_from',
                    __('A data de entrada em vigor não pode ser anterior a hoje.'),
                );

                return;
            }

            $currentStartsOn = $recurringLessonSlot->starts_on?->toDateString();

            if ($currentStartsOn !== null && $effectiveFrom <= $currentStartsOn) {
                // starts_on === hoje E já existe pelo menos uma Lesson sob
                // esta versão (senão requiresVersioning() nunca teria exigido
                // effective_from): a mensagem genérica falaria de "início da
                // versão atual" sem dizer que esse início é hoje mesmo — esta
                // é mais clara sobre o porquê.
                if ($currentStartsOn === $today) {
                    $validator->errors()->add(
                        'effective_from',
                        __(
                            'Já existe uma aula prevista para hoje com este horário; a alteração só pode entrar em vigor a partir de amanhã.',
                        ),
                    );

                    return;
                }

                $validator->errors()->add(
                    'effective_from',
                    __('A data de entrada em vigor tem de ser posterior ao início da versão atual (:date).', [
                        'date' => $currentStartsOn,
                    ]),
                );
            }
        });
    }

    /**
     * O grupo escolhido tem de ser DESTA turma, e tem de aceitar trabalho novo.
     *
     * Nenhuma das duas coisas cabe numa regra estática:
     * `BelongsToCurrentOrganization` garante a organização e mais nada, pelo
     * que o id de um grupo de outra turma minha passaria por lá sem tropeçar;
     * e «arquivado» é um estado que muda enquanto o formulário está aberto.
     *
     * O CASO QUE ESTA REGRA NÃO APANHA — de propósito: um tempo do horário que
     * JÁ aponta para um grupo entretanto arquivado continua a poder ser
     * revisto sem lhe mexer no grupo. Só a ESCOLHA de um grupo arquivado é
     * recusada, e por isso a comparação é com o valor que o slot já tinha.
     * Sem isto, arquivar um grupo trancaria os tempos que o usam, e a saída
     * seria desarquivar o grupo para poder mexer no horário — o beco que
     * ArchiveClassGroup existe para não abrir.
     */
    private function validateClassGroup(Validator $validator): void
    {
        $classGroupId = $this->input('class_group_id');

        if ($classGroupId === null || $classGroupId === '') {
            return;
        }

        $classGroup = ClassGroup::query()->find((int) $classGroupId);

        if ($classGroup === null) {
            return; // BelongsToCurrentOrganization já terá falhado.
        }

        if ($classGroup->class_id !== $this->integer('class_id')) {
            $validator->errors()->add(
                'class_group_id',
                __('O grupo escolhido não pertence a esta turma.'),
            );

            return;
        }

        $recurringLessonSlot = $this->route('recurringLessonSlot');
        $wasAlreadyThisGroup = $recurringLessonSlot instanceof RecurringLessonSlot
            && $recurringLessonSlot->class_group_id === $classGroup->getKey();

        if ($classGroup->isArchived() && ! $wasAlreadyThisGroup) {
            $validator->errors()->add(
                'class_group_id',
                __('O grupo :label está arquivado e não pode ser usado em tempos novos do horário.', [
                    'label' => $classGroup->label,
                ]),
            );
        }
    }

    private function routeSlotRequiresVersioning(): bool
    {
        $recurringLessonSlot = $this->route('recurringLessonSlot');

        return $recurringLessonSlot instanceof RecurringLessonSlot
            && $recurringLessonSlot->requiresVersioning(app(CurrentOrganization::class)->get()->timezone);
    }
}
