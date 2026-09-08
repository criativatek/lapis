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
     * O DIA POR QUE O ECRÃ DA TURMA LÊ A COMPOSIÇÃO — «hoje», mas dentro do ano
     * letivo da turma.
     *
     * Um professor que prepara os grupos em agosto, ou na primeira semana de
     * setembro antes de o ano começar, está a dizer quem vai para T1 NESTE ANO.
     * Lido pelo «hoje» a seco, o ecrã respondia-lhe «T1: 0 alunos» e punha os
     * trinta debaixo de «Sem grupo» — porque as pertenças começam no primeiro
     * dia do ano letivo e esse dia ainda não chegou. E o passo seguinte era
     * pior: «Distribuir alunos» voltava a oferecer os mesmos alunos, e o
     * servidor recusava-os com «já pertence a um grupo». Um beco a sério,
     * encontrado a validar isto no browser a 2026-09-09 contra uma turma cujo
     * ano começa a 2026-09-14.
     *
     * A resposta é a data limitada ao ano: antes do início lê-se o primeiro
     * dia, depois do fim lê-se o último, e no meio lê-se hoje. Não é uma
     * suavização — é o que a pergunta quer dizer. «Quem está em T1» numa turma
     * de um ano que ainda não abriu só pode significar «quem vai estar».
     *
     * NÃO É USADA PELAS AULAS. `forLesson()` continua a ler pela data DA AULA,
     * que é sempre um dia dentro do ano e é a única data que lá faz sentido.
     */
    public function readingDateFor(SchoolClass $class, string $today): string
    {
        $academicYear = $class->academicYear()->firstOrFail();
        $startsOn = $academicYear->starts_on->toDateString();
        $endsOn = $academicYear->ends_on->toDateString();

        if ($today < $startsOn) {
            return $startsOn;
        }

        return $today > $endsOn ? $endsOn : $today;
    }

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
     * A COMPOSIÇÃO QUE O ECRÃ DA TURMA MOSTRA: o grupo de cada aluno agora, ou
     * aquele em que vai entrar quando a sua pertença começar.
     *
     * `groupIdByEnrollmentOn()` abaixo responde à pergunta datada, exata, e é a
     * que serve quem pergunta por um dia concreto. Esta responde à pergunta que
     * o professor faz ao olhar para o ecrã — «em que grupo é que o Filipe
     * está?» —, e para um aluno de ingresso tardio a resposta honesta é «em T2,
     * a partir de novembro», não «em nenhum».
     *
     * SEM O RECUO A pertença de um aluno que entra a 3 de novembro começa a 3
     * de novembro (§ initialEffectiveFrom). Lido pelo dia de hoje, esse aluno
     * aparecia debaixo de «Sem grupo» durante dois meses, «Distribuir alunos»
     * voltava a oferecê-lo, e o servidor recusava-o com «já pertence a um
     * grupo» — o mesmo beco que `readingDateFor()` fecha para a turma inteira,
     * aqui por aluno.
     *
     * Os números por grupo saem DESTE mapa e não de uma segunda consulta: assim
     * o «T1 (12)» do cartão e os doze nomes por baixo dele não podem discordar.
     *
     * `since` diz DESDE QUANDO cada uma dessas pertenças vale, e existe por uma
     * razão prática: é a data que o diálogo «Mover» tem de oferecer. Corrigir a
     * pertença de um aluno de ingresso tardio numa data anterior ao dia em que
     * ele entrou seria recusado pelo servidor — e com razão —, e o professor
     * não tem por que adivinhar qual é essa data.
     *
     * @param  list<int>  $enrollmentIds
     * @return array{groups: array<int, int|null>, counts: array<int, int>, since: array<int, string|null>}
     */
    public function compositionFor(SchoolClass $class, array $enrollmentIds, string $date): array
    {
        $groupIds = $class->classGroups()->pluck('id')->all();
        $groups = array_fill_keys($enrollmentIds, null);
        $since = array_fill_keys($enrollmentIds, null);
        $counts = array_fill_keys(array_map(intval(...), $groupIds), 0);

        if ($enrollmentIds === [] || $groupIds === []) {
            return ['groups' => $groups, 'counts' => $counts, 'since' => $since];
        }

        // Uma consulta só, ordenada: a janela em vigor de cada aluno vem
        // primeiro (começou em ou antes da data), e a seguir as futuras pela
        // ordem em que começam. A primeira que aparecer para cada inscrição é
        // a resposta — nunca uma consulta por aluno.
        $memberships = ClassGroupMembership::query()
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('class_group_id', $groupIds)
            ->where(fn ($query) => $query
                ->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $date))
            ->orderBy('effective_from')
            ->get(['enrollment_id', 'class_group_id', 'effective_from']);

        foreach ($memberships as $membership) {
            $enrollmentId = (int) $membership->enrollment_id;

            if (($groups[$enrollmentId] ?? null) !== null) {
                continue;
            }

            $groups[$enrollmentId] = (int) $membership->class_group_id;
            $since[$enrollmentId] = $membership->effective_from->toDateString();
            $counts[(int) $membership->class_group_id]++;
        }

        return ['groups' => $groups, 'counts' => $counts, 'since' => $since];
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
