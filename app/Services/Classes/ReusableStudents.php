<?php

namespace App\Services\Classes;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * OS ALUNOS QUE UM PROFESSOR PODE INSCREVER NUMA TURMA DE APOIO — sem os criar
 * de novo.
 *
 * O ÂMBITO É O QUE O PRODUTO JÁ AUTORIZA, e não um novo. Um aluno é visível a
 * um professor quando tem uma inscrição ATIVA numa turma que esse professor
 * leciona — é exatamente a regra de StudentPolicy::viewPhoto(). Aqui junta-se
 * mais duas condições: a turma de origem não está arquivada e é do MESMO ANO
 * LETIVO da turma de apoio. Nunca «todos os alunos da organização»: numa
 * escola com vários professores, isso daria a cada um os nomes das turmas dos
 * colegas (§23, «só as minhas turmas»). Outra organização nem chega a entrar —
 * o scope de tenant já a esconde (ADR-0002).
 *
 * A PESQUISA É FEITA EM PHP, DE PROPÓSITO. O nome está cifrado em repouso
 * (ADR-0004) e o índice cego só serve para igualdade exata; uma pesquisa
 * progressiva por «joã» não existe em SQL. O conjunto decifrado está limitado
 * às turmas deste professor neste ano — dezenas ou poucas centenas de linhas —
 * e o browser recebe no máximo MAX_RESULTS, nunca a lista inteira.
 *
 * NUNCA ESCOLHE. Dois «João Silva» aparecem os dois, cada um com as suas
 * turmas e o seu n.º; é o professor quem diz qual é (§3.3).
 */
class ReusableStudents
{
    public const MAX_RESULTS = 20;

    public const MINIMUM_QUERY_LENGTH = 2;

    /**
     * As inscrições ativas que tornam um aluno reutilizável por este professor
     * para esta turma. A pergunta existe uma vez, aqui, e serve à pesquisa e à
     * verificação do servidor quando o ULID volta.
     *
     * @return Builder<Enrollment>
     */
    public function authorizingEnrollments(SchoolClass $supportClass, User $teacher): Builder
    {
        return Enrollment::query()
            ->active()
            ->where('class_id', '!=', $supportClass->getKey())
            ->whereHas('schoolClass', fn (Builder $classes) => $classes
                ->where('academic_year_id', $supportClass->academic_year_id)
                ->whereNull('archived_at')
                ->whereHas('teachers', fn (Builder $teachers) => $teachers->whereKey($teacher->getKey())));
    }

    /**
     * O aluno com este ULID, se — e só se — este professor o pode inscrever
     * nesta turma. Null em todos os outros casos, sem distinguir «não existe»
     * de «não é seu»: a resposta não confirma a existência de ninguém.
     */
    public function find(SchoolClass $supportClass, User $teacher, string $studentUlid): ?Student
    {
        return Student::query()
            ->where('ulid', $studentUlid)
            ->whereIn('id', $this->authorizingEnrollments($supportClass, $teacher)->select('student_id'))
            ->with('identity')
            ->first();
    }

    /**
     * @return list<array{ulid: string, name: string, process_number: string|null, photo_url: string|null, origins: list<array{label: string, class_number: int|null}>, already_in_class: bool}>
     */
    public function search(SchoolClass $supportClass, User $teacher, string $query): array
    {
        $needle = $this->normalize($query);

        if (mb_strlen($needle) < self::MINIMUM_QUERY_LENGTH) {
            return [];
        }

        /** @var Collection<int, Enrollment> $origins */
        $origins = $this->authorizingEnrollments($supportClass, $teacher)
            ->with(['schoolClass:id,label', 'student.identity'])
            ->get();

        $alreadyInClass = array_flip(array_map(
            intval(...),
            $supportClass->activeEnrollments()->pluck('student_id')->all(),
        ));

        return array_values($origins
            ->groupBy('student_id')
            ->map(function (Collection $enrollments) use ($alreadyInClass): array {
                /** @var Enrollment $first */
                $first = $enrollments->first();
                $student = $first->student;

                return [
                    'ulid' => $student->ulid,
                    'name' => $student->identity->display_name ?? '',
                    'process_number' => $student->processNumber(),
                    'photo_url' => $student->photoUrl(),
                    'origins' => array_values($enrollments
                        ->map(fn (Enrollment $enrollment): array => [
                            'label' => $enrollment->schoolClass->label,
                            'class_number' => $enrollment->class_number,
                        ])
                        ->sortBy('label')
                        ->all()),
                    'already_in_class' => isset($alreadyInClass[$student->id]),
                ];
            })
            ->filter(fn (array $row): bool => $row['name'] !== '' && (
                str_contains($this->normalize($row['name']), $needle)
                || ($row['process_number'] !== null && str_contains($this->normalize($row['process_number']), $needle))
            ))
            ->sortBy(fn (array $row): string => $this->normalize($row['name']))
            ->take(self::MAX_RESULTS)
            ->all());
    }

    /**
     * «João» encontra «joao» e vice-versa: sem acentos, minúsculas, espaços
     * comprimidos.
     */
    protected function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->toString();
    }
}
