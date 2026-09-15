<?php

namespace App\Services\Lessons;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonOutcome;
use App\Models\SchoolClass;
use Illuminate\Validation\ValidationException;

/**
 * A assiduidade de TODOS os alunos de uma turma, num intervalo — a tabela por
 * aluno que o relatório de turma precisa (§ do briefing de assiduidade).
 *
 * A MESMA DISTINÇÃO DE `StudentAttendanceHistory`, só que por aluno em vez de
 * por linha: presença/falta consolidadas contam à parte de «não registada», e
 * «não registada» nunca é somada a presença.
 *
 * POUCAS CONSULTAS, NÃO UMA POR ALUNO: uma para o efetivo da turma, uma para
 * as linhas consolidadas do intervalo, uma para as aulas lecionadas sem
 * registo — e, só para essas últimas (tipicamente poucas), uma chamada ao
 * roster de elegibilidade já existente, que este serviço reutiliza em vez de
 * reescrever a regra.
 */
class ClassAttendanceSummary
{
    public function __construct(private LessonAttendanceRoster $roster) {}

    /**
     * @return array{
     *     available: bool,
     *     rows: list<array{enrollment_id: int, class_number: int|null, name: string, present: int, absent: int, not_recorded: int}>,
     * }
     */
    public function for(SchoolClass $class, ?string $from = null, ?string $to = null): array
    {
        $enrollments = Enrollment::query()
            ->where('class_id', $class->id)
            ->with('student.identity')
            ->orderBy('class_number')
            ->get();

        if ($enrollments->isEmpty()) {
            return ['available' => false, 'rows' => []];
        }

        $counts = [];

        foreach ($enrollments as $enrollment) {
            $counts[$enrollment->id] = ['present' => 0, 'absent' => 0, 'not_recorded' => 0];
        }

        $consolidated = LessonAttendance::query()
            ->whereHas('lesson', function ($query) use ($class, $from, $to): void {
                // SÓ CONSOLIDADAS (0.146.0). Uma linha `absent` numa aula ainda
                // por consolidar é um RASCUNHO e não entra em total nenhum; e
                // numa ocorrência sem assiduidade aplicável não conta nada.
                $query->where('class_id', $class->id)
                    ->whereNotNull('attendance_recorded_at')
                    ->where(fn ($outcome) => $outcome->whereNull('outcome')->orWhere('outcome', LessonOutcome::Taught->value));

                if ($from !== null) {
                    $query->whereDate('starts_at', '>=', $from);
                }

                if ($to !== null) {
                    $query->whereDate('starts_at', '<=', $to);
                }
            })
            ->get(['enrollment_id', 'status']);

        foreach ($consolidated as $attendance) {
            if (! isset($counts[$attendance->enrollment_id])) {
                // Uma inscrição fora do efetivo lido acima — não deveria
                // acontecer (mesma turma), mas uma linha órfã não deve
                // rebentar o relatório.
                continue;
            }

            $counts[$attendance->enrollment_id][$attendance->status->value]++;
        }

        $notRecordedLessons = Lesson::query()
            ->where('class_id', $class->id)
            ->where(fn ($query) => Lesson::whereCountsAsTaughtWithAttendance($query))
            ->whereNull('attendance_recorded_at')
            ->when($from !== null, fn ($query) => $query->whereDate('starts_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('starts_at', '<=', $to))
            ->with(['classGroup', 'schoolClass'])
            ->get();

        foreach ($notRecordedLessons as $lesson) {
            try {
                $eligibleStudents = $this->roster->for($lesson)['students'];
            } catch (ValidationException) {
                // O grupo da aula já não pertence à turma — a mesma situação
                // que a página da aula relata em vez de rebentar; um
                // relatório não deve falhar por causa de uma aula antiga
                // nesse estado.
                continue;
            }

            foreach ($eligibleStudents as $eligible) {
                if (isset($counts[$eligible->id])) {
                    $counts[$eligible->id]['not_recorded']++;
                }
            }
        }

        $rows = [];

        foreach ($enrollments as $enrollment) {
            $count = $counts[$enrollment->id];

            $rows[] = [
                'enrollment_id' => $enrollment->id,
                'class_number' => $enrollment->class_number,
                'name' => (string) (optional($enrollment->student->identity)->display_name ?? '(sem identidade)'),
                'present' => $count['present'],
                'absent' => $count['absent'],
                'not_recorded' => $count['not_recorded'],
            ];
        }

        return ['available' => true, 'rows' => $rows];
    }
}
