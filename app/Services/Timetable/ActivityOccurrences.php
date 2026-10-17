<?php

namespace App\Services\Timetable;

use App\Models\AcademicYear;
use App\Models\TimetableActivity;
use App\Models\User;
use App\Services\Lessons\ScheduleOccurrences;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * «Quando é que esta atividade acontece, e posso lá registar uma sessão?»
 *
 * As «outras atividades» são uma regra semanal (dia da semana + vigência), como
 * os tempos de aula; as suas ocorrências são VIRTUAIS — só passam a existir como
 * `TimetableActivitySession` quando o professor guarda. Esta classe responde à
 * pergunta sem escrever nada: quais as ocorrências de um professor entre duas
 * datas, e se uma data é um dia em que se pode criar a sessão.
 *
 * Uma sessão não se cria num dia não letivo do calendário. O calendário é o do
 * ANO LETIVO DA SESSÃO — o da turma, se a atividade a referir; senão o da
 * organização que contém a data (`AcademicYear::containing`). Sem ano, não há
 * calendário que diga o contrário e o dia conta como letivo.
 *
 * Uma instância guarda memórias curtas (anos e exceções por data): é pensada
 * para um pedido.
 */
final class ActivityOccurrences
{
    /** @var array<string, AcademicYear|null> */
    private array $yearByDate = [];

    /** @var array<string, bool> */
    private array $nonTeachingMemo = [];

    public function __construct(private readonly ScheduleOccurrences $schedule) {}

    /**
     * As ocorrências das atividades do professor entre duas datas, inclusivas.
     *
     * @return list<array{activity: TimetableActivity, date: string, registrable: bool}>
     */
    public function between(User $teacher, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($from->greaterThan($to)) {
            return [];
        }

        /** @var Collection<int, TimetableActivity> $activities */
        $activities = TimetableActivity::query()
            ->where('user_id', $teacher->getKey())
            ->with('schoolClass')
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $occurrences = [];

        foreach ($activities as $activity) {
            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                $day = $date->toDateString();

                if (! $activity->occursOn($day)) {
                    continue;
                }

                $occurrences[] = [
                    'activity' => $activity,
                    'date' => $day,
                    'registrable' => $this->isRegistrable($activity, $day),
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Pode criar-se aqui uma sessão? Não, se o dia é não letivo no calendário
     * do ano da sessão. NÃO verifica se a atividade ocorre nesse dia — é o
     * `TimetableActivity::occursOn`.
     */
    public function isRegistrable(TimetableActivity $activity, string $date): bool
    {
        $academicYear = $this->academicYearFor($activity, $date);

        if ($academicYear === null) {
            return true;
        }

        $key = $academicYear->getKey().'|'.$date;

        return ! ($this->nonTeachingMemo[$key]
            ??= $this->schedule->isNonTeachingDateIn($academicYear, $date));
    }

    /**
     * O ano letivo de uma sessão desta atividade nesta data: o da turma, se a
     * atividade a referir; senão o da organização que contém a data; `null`
     * se nenhum.
     */
    public function academicYearFor(TimetableActivity $activity, string $date): ?AcademicYear
    {
        if ($activity->class_id !== null) {
            $classYear = $activity->schoolClass?->academicYear;

            if ($classYear !== null) {
                return $classYear;
            }
        }

        return $this->containing($date);
    }

    public function containing(string $date): ?AcademicYear
    {
        if (! array_key_exists($date, $this->yearByDate)) {
            $this->yearByDate[$date] = AcademicYear::containing($date);
        }

        return $this->yearByDate[$date];
    }
}
