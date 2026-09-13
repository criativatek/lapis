<?php

namespace App\Actions\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * «Marcar as aulas de hoje como lecionadas» — sem abrir doze páginas.
 *
 * A REGRA DE DOMÍNIO NÃO É REESCRITA AQUI. Cada aula elegível passa pelo mesmo
 * MarkLessonAsTaught que o botão individual chama: a mesma transição, o mesmo
 * bloqueio, o mesmo registo de auditoria. O que este lote acrescenta é a
 * SELEÇÃO — quais as aulas —, e mais nada. Uma segunda implementação da
 * transição num controlador seria a forma garantida de as duas divergirem no
 * dia em que a regra individual mudasse.
 *
 * AS AULAS FUTURAS NÃO SÃO BLOQUEADAS AQUI porque não o são no botão
 * individual: marcar uma aula de amanhã como lecionada é hoje possível, e
 * mudá-lo em silêncio nesta fatia seria alterar a semântica pedagógica pelo
 * caminho. O que o lote faz é TORNAR AS DATAS EXPLÍCITAS — o intervalo e a
 * contagem aparecem na confirmação — para que ninguém marque uma semana
 * inteira sem ver que metade dela ainda não aconteceu.
 *
 * NADA DE ALTERAÇÕES PARCIAIS SILENCIOSAS. O que não é elegível não é tocado e
 * é devolvido com o motivo, para o ecrã o poder dizer.
 */
class MarkLessonsAsTaughtInBatch
{
    public function __construct(protected MarkLessonAsTaught $markLessonAsTaught) {}

    /**
     * As aulas do professor que uma seleção abrange, já separadas entre as que
     * o lote vai marcar e as que não vai — e porquê.
     *
     * @param  list<string>|null  $ulids  seleção explícita; NULL usa o intervalo
     * @return array{eligible: Collection<int, Lesson>, ineligible: list<array{lesson: Lesson, reason: string}>}
     */
    public function candidates(
        User $teacher,
        AcademicYear $academicYear,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?array $ulids = null,
    ): array {
        $query = Lesson::query()
            ->whereHas('schoolClass', fn ($inner) => $inner
                ->where('academic_year_id', $academicYear->getKey())
                ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->getKey())))
            ->with(['schoolClass.subject', 'classGroup'])
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($ulids !== null) {
            $query->whereIn('ulid', $ulids);
        } else {
            $query->whereBetween('starts_at', [
                ($from ?? CarbonImmutable::now('Europe/Lisbon'))->setTimezone('Europe/Lisbon')->startOfDay(),
                ($to ?? CarbonImmutable::now('Europe/Lisbon'))->setTimezone('Europe/Lisbon')->endOfDay(),
            ]);
        }

        $eligible = new Collection;
        $ineligible = [];

        foreach ($query->get() as $lesson) {
            if ($lesson->status === LessonStatus::Taught) {
                $ineligible[] = ['lesson' => $lesson, 'reason' => __('Já está marcada como lecionada.')];

                continue;
            }

            // A autorização por aula, e não só a do ecrã: a seleção pode vir do
            // browser, e um ulid de outra turma num array não pode passar só
            // por estar na mesma organização. `whereHas('teachers')` acima já
            // filtra, e esta é a rede por baixo dela — a mesma policy que o
            // botão individual consulta.
            if (! Gate::forUser($teacher)->allows('update', $lesson)) {
                $ineligible[] = ['lesson' => $lesson, 'reason' => __('Sem permissão para esta aula.')];

                continue;
            }

            $eligible->push($lesson);
        }

        return ['eligible' => $eligible, 'ineligible' => $ineligible];
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @return int quantas ficaram efetivamente marcadas
     */
    public function execute(Collection $lessons, User $actor): int
    {
        // Uma transação à volta do lote inteiro: ou a semana fica marcada, ou
        // não fica — nunca metade, com o professor sem saber onde parou.
        // MarkLessonAsTaught abre a sua própria transação por aula, que aqui
        // dentro se torna um savepoint aninhado e não uma segunda transação.
        return DB::transaction(function () use ($actor, $lessons): int {
            $marked = 0;

            foreach ($lessons as $lesson) {
                $this->markLessonAsTaught->execute($lesson, $actor);
                $marked++;
            }

            return $marked;
        });
    }
}
