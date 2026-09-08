<?php

namespace App\Services\Classes;

use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Collection;

/**
 * «QUE ALUNOS PERTENCEM A ESTA AULA?» — a pergunta feita num sítio só.
 *
 * Ainda não há presenças nesta aplicação, e é precisamente por isso que este
 * serviço existe agora e não depois: o dia em que houver, a resposta tem de já
 * estar escrita uma vez, e não descoberta outra vez dentro do ecrã das
 * presenças, e mais outra dentro do relatório que as conta (§18 do briefing).
 *
 * DUAS REGRAS, CONFORME A AULA:
 *
 *   `class_group_id` NULL (turma inteira)
 *       → as inscrições ativas da turma NAQUELE DIA
 *
 *   com grupo
 *       → as pertenças em vigor naquele dia ∩ as mesmas inscrições ativas
 *
 * A DATA É A DA AULA, NUNCA «HOJE». `SchoolClass::activeEnrollments()` responde
 * pelo estado de hoje (`status = active`), o que é o que um ecrã operacional
 * quer e não é o que uma aula de novembro quer: um aluno que se transferiu em
 * janeiro esteve na aula de novembro, e a lista dessa aula tem de continuar a
 * dizê-lo. Por isso a interseção é com `Enrollment::scopeEnrolledOn()`, que lê
 * `enrolled_on`/`left_on` — o predicado datado que a avaliação já usa em
 * memória para saber a quem um elemento se aplica.
 *
 * NUNCA UMA CONSULTA POR ALUNO. Uma consulta para as inscrições, e no máximo
 * uma segunda para as pertenças do grupo: o custo é o mesmo numa turma de oito
 * e numa de trinta.
 *
 * ISTO NÃO É AVALIAÇÃO. Nada aqui é chamado pela pauta, pelo Quadro Síntese,
 * pelas classificações, pelos instrumentos ou pelos relatórios — esses
 * continuam a ler a turma inteira, e um grupo não entra em nenhum denominador
 * (§19 do briefing).
 */
class ClassRoster
{
    /**
     * @return Collection<int, Enrollment>
     */
    public function forLesson(Lesson $lesson): Collection
    {
        $class = $lesson->schoolClass()->firstOrFail();

        return $this->on($class, $lesson->classGroup, $lesson->starts_at->toDateString());
    }

    /**
     * @return Collection<int, Enrollment>
     */
    public function on(SchoolClass $class, ?ClassGroup $classGroup, string $date): Collection
    {
        $enrollments = $class->activeEnrollments()
            ->enrolledOn($date)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        if ($classGroup === null) {
            return $enrollments;
        }

        $memberIds = ClassGroupMembership::query()
            ->where('class_group_id', $classGroup->getKey())
            ->inVigorOn($date)
            ->pluck('enrollment_id')
            ->all();

        return $enrollments->whereIn('id', $memberIds)->values();
    }

    /**
     * Quantos alunos tem cada grupo desta turma NAQUELE DIA, por id de grupo.
     *
     * UMA CONSULTA PARA A TURMA INTEIRA, e não um `withCount` por grupo: o
     * ecrã da turma mostra o número ao lado de cada grupo, e um `withCount`
     * contaria também as pertenças já fechadas — «T1 (14)» num grupo que hoje
     * tem oito alunos e teve seis que saíram.
     *
     * @return array<int, int>
     */
    public function memberCountsOn(SchoolClass $class, string $date): array
    {
        $groupIds = $class->classGroups()->pluck('id')->all();

        if ($groupIds === []) {
            return [];
        }

        $activeEnrollmentIds = $class->activeEnrollments()->enrolledOn($date)->pluck('id')->all();

        if ($activeEnrollmentIds === []) {
            return array_fill_keys($groupIds, 0);
        }

        $counts = ClassGroupMembership::query()
            ->whereIn('class_group_id', $groupIds)
            ->whereIn('enrollment_id', $activeEnrollmentIds)
            ->inVigorOn($date)
            ->selectRaw('class_group_id, COUNT(*) as members')
            ->groupBy('class_group_id')
            ->pluck('members', 'class_group_id')
            ->all();

        $result = [];

        foreach ($groupIds as $groupId) {
            $result[(int) $groupId] = (int) ($counts[$groupId] ?? 0);
        }

        return $result;
    }

    /**
     * A que grupo pertence cada inscrição desta turma NAQUELE DIA — null para
     * quem não pertence a nenhum.
     *
     * O «Sem grupo» do ecrã da turma sai daqui, e não da ausência de uma linha
     * na lista de um grupo: é um estado com nome, e é sempre legítimo (§9 do
     * briefing) — uma turma pode ter grupos sem que todos os alunos estejam num.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, int|null>
     */
    public function groupIdByEnrollmentOn(array $enrollmentIds, string $date): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $memberships = ClassGroupMembership::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->inVigorOn($date)
            ->pluck('class_group_id', 'enrollment_id')
            ->all();

        $result = [];

        foreach ($enrollmentIds as $enrollmentId) {
            $groupId = $memberships[$enrollmentId] ?? null;
            $result[(int) $enrollmentId] = $groupId === null ? null : (int) $groupId;
        }

        return $result;
    }
}
