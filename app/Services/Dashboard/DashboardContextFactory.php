<?php

namespace App\Services\Dashboard;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ClassIdentityTones;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Lessons\ScheduleOccurrences;
use App\Support\Entitlements\Entitlements;
use App\Support\Retention\AcademicYearRetentionClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Resolve o DashboardContext de um professor: o ano letivo pela mesma
 * heurística que o resto da aplicação já usa (AcademicYearRetentionClassifier),
 * as turmas pelo escopo que o menu e as listas usam (`taughtBy` + `notArchived`),
 * os módulos pelo Entitlements, e — só com o módulo `lessons` legível — a
 * projeção SÓ DE LEITURA das ocorrências do horário na semana de hoje.
 *
 * A projeção custa três consultas por turma (tempos do horário, ano letivo e
 * exceções do calendário, dentro de ScheduleOccurrences, que consulta a relação
 * em vez de a ler carregada); baixá-la é trabalho nesse serviço, não aqui.
 *
 * NUNCA MATERIALIZA UMA AULA. A página das aulas cria as ocorrências da semana
 * quando se abre (MaterializeLessonsForWeek); o painel projeta-as com
 * ScheduleOccurrences e junta-lhes as aulas que já existem, e nada mais.
 */
final class DashboardContextFactory
{
    public function __construct(
        private readonly AcademicYearRetentionClassifier $retentionClassifier,
        private readonly ClassIdentityTones $identityTones,
        private readonly Entitlements $entitlements,
        private readonly ScheduleOccurrences $occurrences,
    ) {}

    public function for(User $teacher, ?CarbonImmutable $now = null): DashboardContext
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(DashboardContext::TIMEZONE);

        $academicYears = AcademicYear::query()->get();
        $academicYear = $this->retentionClassifier->currentYearFor($academicYears);

        /** @var EloquentCollection<int, SchoolClass> $classes */
        $classes = $academicYear === null
            ? new EloquentCollection
            : SchoolClass::query()
                ->where('academic_year_id', $academicYear->getKey())
                ->taughtBy($teacher)
                ->notArchived()
                ->with('subject')
                ->orderBy('label')
                ->get();

        $classIds = array_values($classes->map(fn (SchoolClass $class): int => (int) $class->getKey())->all());

        $tones = $classIds === [] ? [] : $this->identityTones->forTeacher((int) $teacher->getKey(), $classIds);

        $timeline = $academicYear === null
            ? AssessmentPeriodTimeline::fromPeriods(collect())
            : AssessmentPeriodTimeline::forYear((int) $academicYear->getKey());

        $hasLessons = $this->entitlements->canRead('lessons');

        $weekStart = $now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(6);

        $weekOccurrences = [];

        if ($hasLessons) {
            foreach ($classes as $class) {
                $weekOccurrences[(int) $class->getKey()] = $this->occurrences->between($class, $weekStart, $weekEnd, null, true);
            }
        }

        return new DashboardContext(
            teacher: $teacher,
            academicYear: $academicYear,
            now: $now,
            classes: $classes,
            tones: $tones,
            timeline: $timeline,
            currentPeriod: $timeline->periodOn($now),
            hasLessons: $hasLessons,
            hasCalendar: $this->entitlements->canRead('calendar'),
            hasAssessments: $this->entitlements->canRead('assessments'),
            hasRecords: $this->entitlements->canRead('records'),
            hasInterventions: $this->entitlements->canRead('interventions'),
            hasSelfAssessments: $this->entitlements->canRead('self_assessments'),
            weekOccurrences: $weekOccurrences,
        );
    }
}
