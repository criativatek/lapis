<?php

namespace App\Actions\ClassGroups;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A DISTRIBUIÇÃO INICIAL: pôr um punhado de alunos em grupos, de uma vez, sem
 * pedir datas nenhumas.
 *
 * É o caso normal e o mais frequente — o professor abre a turma em setembro,
 * marca quem vai para T1 e quem vai para T2, e guarda. Pedir-lhe uma data
 * nesse momento seria pedir-lhe que repetisse o que o ano letivo já diz, e
 * cada uma das trinta pertenças tem a sua (§4, §9 do briefing): a de quem
 * entrou em setembro começa no início do ano, a de quem entrou em novembro
 * começa quando entrou.
 *
 * SÓ ATRIBUI QUEM AINDA NÃO TEM GRUPO. Não é uma limitação por preguiça: mover
 * alguém que JÁ está em T1 para T2 é uma alteração com consequências — fecha
 * uma janela, abre outra — e tem de dizer a partir de quando. Essa é a
 * MoveClassGroupMembership, que pede a data. Manter as duas coisas separadas é
 * o que permite que esta não peça nada e que a outra nunca deixe de pedir.
 */
class AssignClassGroupMemberships
{
    public function __construct(protected ClassGroupMembershipRules $rules) {}

    /**
     * @param  array<int, int>  $groupIdByEnrollmentId  inscrição => grupo a que passa a pertencer
     * @return int quantas pertenças foram criadas
     */
    public function execute(SchoolClass $class, array $groupIdByEnrollmentId): int
    {
        if ($groupIdByEnrollmentId === []) {
            return 0;
        }

        return DB::transaction(function () use ($class, $groupIdByEnrollmentId): int {
            $academicYearStartsOn = $class->academicYear()->firstOrFail()->starts_on->toDateString();

            // Lidos DENTRO da transação, e uma vez só para todos os alunos —
            // nunca uma consulta por linha do formulário, que numa turma de
            // trinta seriam sessenta idas à base de dados para responder à
            // mesma pergunta.
            $groups = ClassGroup::query()
                ->whereIn('id', array_values($groupIdByEnrollmentId))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $enrollments = Enrollment::query()
                ->whereIn('id', array_keys($groupIdByEnrollmentId))
                ->get()
                ->keyBy('id');

            $created = 0;

            foreach ($groupIdByEnrollmentId as $enrollmentId => $groupId) {
                $enrollment = $enrollments->get($enrollmentId);
                $group = $groups->get($groupId);

                if (! $enrollment instanceof Enrollment || ! $group instanceof ClassGroup) {
                    throw ValidationException::withMessages([
                        'assignments' => __('O registo selecionado é inválido.'),
                    ]);
                }

                $this->rules->assertSameClass($group, $enrollment);
                $this->rules->assertAcceptsMembers($group);

                if ($group->class_id !== $class->getKey()) {
                    throw ValidationException::withMessages([
                        'assignments' => __('O grupo e o aluno têm de pertencer à mesma turma.'),
                    ]);
                }

                $effectiveFrom = $this->rules->initialEffectiveFrom($enrollment, $academicYearStartsOn);

                // A RE-VERIFICAÇÃO, depois do lock. Um aluno que já tenha uma
                // pertença aberta nesta turma — porque outro separador a criou
                // enquanto este formulário estava aberto — não é reatribuído em
                // silêncio: a atribuição inicial só sabe fazer o caso em que
                // não havia nada, e dizê-lo é melhor do que adivinhar de que
                // dia seria a mudança.
                if ($this->rules->membershipInVigor($enrollment, $effectiveFrom) !== null) {
                    throw ValidationException::withMessages([
                        'assignments' => __('Um dos alunos já pertence a um grupo. Recarregue a página e use «Mover» para o mudar de grupo.'),
                    ]);
                }

                $this->rules->assertNothingStartsOnOrAfter($enrollment, $effectiveFrom);

                ClassGroupMembership::create([
                    'class_group_id' => $group->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'effective_from' => $effectiveFrom,
                    'effective_until' => null,
                ]);

                $created++;
            }

            return $created;
        });
    }
}
