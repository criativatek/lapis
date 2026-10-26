<?php

namespace App\Services\Dashboard;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Tudo o que as leituras do Painel do Professor partilham, resolvido UMA vez
 * por pedido (DashboardContextFactory) e passado a cada serviço: o professor,
 * o ano letivo em curso, o «agora» em Lisboa, as turmas que ele leciona neste
 * ano e ainda não arquivou, os tons de identidade, o período em curso e os
 * módulos que a organização pode ao menos consultar.
 *
 * É um valor, não um serviço: nenhuma consulta corre aqui dentro. Quem precisa
 * de dados pede-os aos serviços com este contexto na mão, e assim um painel
 * com oito turmas não repete oito vezes a pergunta «que turmas são as tuas».
 */
final class DashboardContext
{
    public const TIMEZONE = 'Europe/Lisbon';

    /**
     * @param  EloquentCollection<int, SchoolClass>  $classes  as turmas do professor neste ano, não arquivadas, com `subject` carregado
     * @param  array<int, string>  $tones  class_id => valor de ClassIdentityTone, só para as turmas acima
     * @param  array<int, list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, slot_id: int, class_group_id: int|null}>>  $weekOccurrences  class_id => ocorrências do horário na semana de hoje (segunda a domingo); vazio sem o módulo `lessons`
     */
    public function __construct(
        public readonly User $teacher,
        public readonly ?AcademicYear $academicYear,
        public readonly CarbonImmutable $now,
        public readonly EloquentCollection $classes,
        public readonly array $tones,
        public readonly AssessmentPeriodTimeline $timeline,
        public readonly ?AcademicPeriod $currentPeriod,
        public readonly bool $hasLessons,
        public readonly bool $hasCalendar,
        public readonly bool $hasAssessments,
        public readonly bool $hasRecords,
        public readonly bool $hasInterventions,
        public readonly bool $hasSelfAssessments,
        public readonly array $weekOccurrences,
    ) {}

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * Guarda uma leitura cara durante a vida deste contexto, para dois blocos
     * que precisam da mesma semana (aulas, sessões) a pedirem uma só vez —
     * mesmo quando o contentor lhes entrega instâncias diferentes do serviço.
     */
    public function remember(string $key, callable $load): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $load();
        }

        return $this->memo[$key];
    }

    public function today(): string
    {
        return $this->now->toDateString();
    }

    public function startOfToday(): CarbonImmutable
    {
        return $this->now->startOfDay();
    }

    public function endOfToday(): CarbonImmutable
    {
        return $this->now->endOfDay();
    }

    /** A segunda-feira da semana de hoje, às 00:00 de Lisboa. */
    public function weekStart(): CarbonImmutable
    {
        return $this->now->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /** O domingo da semana de hoje, às 23:59:59 de Lisboa. */
    public function weekEnd(): CarbonImmutable
    {
        return $this->weekStart()->addDays(6)->endOfDay();
    }

    /** @return list<int> */
    public function classIds(): array
    {
        return array_values($this->classes->map(fn (SchoolClass $class): int => (int) $class->getKey())->all());
    }

    public function classById(int $id): ?SchoolClass
    {
        /** @var SchoolClass|null $class */
        $class = $this->classes->first(fn (SchoolClass $class): bool => (int) $class->getKey() === $id);

        return $class;
    }

    public function tone(int $classId): ?string
    {
        return $this->tones[$classId] ?? null;
    }

    public function hasClasses(): bool
    {
        return $this->classes->isNotEmpty();
    }

    /**
     * A turma como o painel a nomeia em todo o lado — um só formato, para a
     * régua, a fila, os cartões e o «arrumado» não divergirem.
     *
     * @return array{ulid: string, label: string, subject: string|null, tone: string|null}
     */
    public function classSummary(SchoolClass $class): array
    {
        return [
            'ulid' => $class->ulid,
            'label' => $class->label,
            'subject' => $class->subject?->name,
            'tone' => $this->tone((int) $class->getKey()),
        ];
    }
}
