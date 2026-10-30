<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $class_group_id
 * @property int|null $recurring_lesson_slot_id
 * @property LessonOrigin $origin `schedule` (nasceu de um tempo do horário — mesmo que `recurring_lesson_slot_id` esteja NULL por uma órfã) ou `manual` (nunca teve tempo do horário a produzi-la); ver 2026_11_13_000100_add_origin_to_lessons_table
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $lesson_number
 * @property int|null $lesson_number_end LEGADO (0.172.0, ADR-0017): o fim de um intervalo «12–13» gravado até à 0.171.0. Conserva-se e mostra-se, mas não ocupa números nem é escrito por nenhum caminho novo; NULL em todas as aulas novas
 * @property bool $lesson_number_manual o professor indicou o número: nunca é recalculado
 * @property string|null $lesson_unit_key
 * @property LessonStatus $status
 * @property LessonOutcome|null $outcome NULL = ocorrência ainda não fechada (0.146.0)
 * @property TeacherAbsenceReason|null $outcome_reason só numa ausência do professor, só categoria
 * @property string|null $outcome_note descrição curta, opcional, de uma atividade da turma
 * @property Carbon|null $outcome_recorded_at
 * @property int|null $outcome_recorded_by
 * @property Carbon|null $attendance_recorded_at
 * @property int|null $attendance_recorded_by
 * @property int $summary_version sobe em TODA a escrita do sumário (eventos de LessonSummary); vive na aula porque a linha do sumário é apagada e recriada
 * @property int|null $created_by nullable desde 2026-11-10 (importação de backup — ver 2026_11_10_000500_let_imported_lessons_keep_an_unresolved_author); nunca `null` numa aula criada pela própria aplicação
 * @property-read int|null $absent_count carregado por `withCount()` em WeeklyLessonsQuery — não existe fora dessa consulta
 * @property-read int|null $plan_count carregado por `withCount()` em WeeklyLessonsQuery — não existe fora dessa consulta
 * @property-read int|null $attendances_count carregado por `withCount()` em WeeklyLessonsQuery — todas as linhas, não só as faltas; não existe fora dessa consulta
 */
#[Fillable(['class_id', 'class_group_id', 'recurring_lesson_slot_id', 'origin', 'starts_at', 'ends_at', 'lesson_number', 'lesson_number_end', 'lesson_number_manual', 'lesson_unit_key', 'status', 'outcome', 'outcome_reason', 'outcome_note', 'outcome_recorded_at', 'outcome_recorded_by', 'attendance_recorded_at', 'attendance_recorded_by', 'created_by'])]
class Lesson extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lesson_number' => 'integer',
            'lesson_number_end' => 'integer',
            'lesson_number_manual' => 'boolean',
            'summary_version' => 'integer',
            'status' => LessonStatus::class,
            'outcome' => LessonOutcome::class,
            'origin' => LessonOrigin::class,
            'outcome_reason' => TeacherAbsenceReason::class,
            'outcome_recorded_at' => 'datetime',
            'attendance_recorded_at' => 'datetime',
        ];
    }

    /**
     * «11» ou «11–12» (travessão U+2013) — o número de uma aula que corresponde
     * a uma ou a várias lições, composto num só sítio para que o ecrã, os
     * relatórios e as exportações escrevam o mesmo. Sem início não há número.
     */
    public static function formatLessonNumber(?int $start, ?int $end): ?string
    {
        if ($start === null) {
            return null;
        }

        return $end !== null && $end > $start ? $start.'–'.$end : (string) $start;
    }

    public function lessonNumberLabel(): ?string
    {
        return self::formatLessonNumber($this->lesson_number, $this->lesson_number_end);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<RecurringLessonSlot, $this>
     */
    public function recurringLessonSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringLessonSlot::class);
    }

    /**
     * O grupo com que esta aula nasceu — NULL quando é a turma inteira.
     *
     * INSTANTÂNEO, NÃO LEITURA. Copiado do tempo do horário no momento da
     * materialização (MaterializeLessonsForRange) e nunca reescrito: rever o
     * slot em janeiro cria uma versão nova do slot, e as aulas de novembro
     * continuam a apontar para o grupo que na altura era verdade.
     *
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * «8.º F» ou «8.º F · T1» — o nome por que o professor reconhece esta
     * aula, num sítio só.
     *
     * Escrito aqui e não em cada ecrã porque são quatro os sítios que o
     * mostram (o cartão da semana, o cabeçalho do sumário, o título da página
     * e o `<Head>`), e um separador diferente em qualquer um deles leria como
     * outra coisa. O front-end recebe a frase já composta.
     *
     * Exige `schoolClass` (e `classGroup`, quando existe) carregados — todos
     * os chamadores o fazem com eager-loading, precisamente para que uma
     * lista de aulas não faça uma consulta por linha.
     */
    public function contextLabel(): string
    {
        return $this->classGroup === null
            ? $this->schoolClass->label
            : $this->schoolClass->label.' · '.$this->classGroup->label;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<LessonPlan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(LessonPlan::class);
    }

    /**
     * @return HasOne<LessonSummary, $this>
     */
    public function summary(): HasOne
    {
        return $this->hasOne(LessonSummary::class);
    }

    /**
     * @return HasMany<LessonAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(LessonAttendance::class);
    }

    /**
     * Só as linhas de falta — para a vista da semana dizer QUEM faltou, e não
     * apenas quantos. Leitura: nunca se grava através desta relação.
     *
     * @return HasMany<LessonAttendance, $this>
     */
    public function absentAttendances(): HasMany
    {
        return $this->attendances()->where('status', AttendanceStatus::Absent->value);
    }

    /**
     * Se a assiduidade desta aula já está CONSOLIDADA — um instantâneo
     * fechado, e não mais um rascunho. `attendance_recorded_at` é a única
     * fonte desta resposta: nunca se infere pela presença de linhas, porque
     * antes da consolidação já podem existir linhas `absent` de rascunho.
     */
    public function attendanceRecorded(): bool
    {
        return $this->attendance_recorded_at !== null;
    }

    /**
     * A ocorrência já fechou — com qualquer resultado. Uma aula `taught` de
     * antes da 0.146.0 fica fechada pelo backfill; o `status` é lido também
     * para que um modelo em memória nunca pareça aberto por engano.
     */
    public function isClosed(): bool
    {
        return $this->outcome !== null || $this->status === LessonStatus::Taught;
    }

    /**
     * A aula leva algo que NÃO se perde em silêncio ao ser retirada do horário:
     * está fechada ou lecionada, tem número indicado pelo professor, assiduidade
     * (consolidada ou rascunho) ou uma linha de sumário/plano.
     *
     * A definição que ReconcileLessonsWithSlotValidity sempre usou, extraída
     * para aqui para que a reconciliação com o calendário a reutilize em vez de
     * a duplicar. LÊ OS ATRIBUTOS `*_exists`: quem chama carrega a aula com
     * `withExists(['summary', 'plan', 'attendances'])`.
     */
    public function carriesContent(): bool
    {
        return $this->isClosed()
            || $this->lesson_number_manual
            || $this->status === LessonStatus::Taught
            || $this->attendance_recorded_at !== null
            || (bool) $this->getAttribute('summary_exists')
            || (bool) $this->getAttribute('plan_exists')
            || (bool) $this->getAttribute('attendances_exists');
    }

    /**
     * Fechada e com número próprio na sequência (lecionada ou atividade da
     * turma). É o histórico que a renumeração não pode mudar.
     */
    public function isClosedAndNumbered(): bool
    {
        return $this->isClosed() && $this->outcome !== LessonOutcome::TeacherAbsent;
    }

    /**
     * «Lecionada», nos mesmos termos de `whereCountsAsTaughtWithAttendance`:
     * `outcome = taught`, ou — sem resultado ainda, com o estado antigo — `status
     * = taught`. Professor ausente e atividade da turma NÃO são lecionadas,
     * mesmo estando fechadas: é a distinção de que ClearLessonSummary precisa
     * para saber que sumário nunca é registo do que aconteceu na aula.
     */
    public function isTaught(): bool
    {
        return $this->outcome === LessonOutcome::Taught
            || ($this->outcome === null && $this->status === LessonStatus::Taught);
    }

    /**
     * «Lecionada», na base de dados: `outcome = taught`, ou — numa linha que
     * ainda não tem resultado mas tem o estado antigo (importação, fixture) —
     * `status = taught`. É a única ocorrência onde a assiduidade se aplica.
     *
     * @param  Builder<Lesson>|\Illuminate\Database\Query\Builder  $query
     */
    public static function whereCountsAsTaughtWithAttendance($query): void
    {
        $query->where('outcome', LessonOutcome::Taught->value)
            ->orWhere(fn ($legacy) => $legacy->whereNull('outcome')->where('status', LessonStatus::Taught->value));
    }

    /**
     * A assiduidade desta aula CONTA: foi consolidada (marcada como lecionada,
     * ou registada depois) e a ocorrência é uma aula — sem resultado ainda, ou
     * `taught`. Um rascunho de faltas numa aula por fechar, ou uma ocorrência
     * sem assiduidade aplicável (professor ausente, turma noutras atividades),
     * nunca conta. A regra única de StudentAttendanceHistory,
     * ClassAttendanceSummary e das faltas de presença da consulta de Registos.
     * Colunas qualificadas: serve tanto um `whereHas('lesson')` como uma junção.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>|\Illuminate\Database\Query\Builder  $query
     */
    public static function whereAttendanceCounts($query): void
    {
        $query->whereNotNull('lessons.attendance_recorded_at')
            ->where(fn ($outcome) => $outcome->whereNull('lessons.outcome')->orWhere('lessons.outcome', LessonOutcome::Taught->value));
    }

    /**
     * A assiduidade dos alunos não se aplica a esta ocorrência.
     */
    public function attendanceNotApplicable(): bool
    {
        return $this->outcome !== null && ! $this->outcome->takesAttendance();
    }
}
