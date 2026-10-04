<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
use App\Services\Classes\ClassIdentityTones;
use App\Support\Entitlements\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A LINHA DE AULA, escrita num sítio só.
 *
 * A semana e a vista da turma mostram as MESMAS aulas, e a regra é que as duas
 * vistas nunca divergem: um sumário editado numa tem de aparecer igual na
 * outra. O ecrã tem um só tipo `WeekLesson`; do lado de cá há um só construtor
 * dele. Quem precisa de aulas (WeeklyLessonsQuery, ClassLessonsView,
 * PreviousLessonSummary) pede a consulta base e entrega as aulas lidas para
 * serem transformadas em linhas.
 */
final class LessonRowBuilder
{
    public function __construct(
        private readonly ClassArchivalWindow $archivalWindow,
        private readonly ClassIdentityTones $identityTones,
        private readonly LessonDayEvents $dayEvents,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * As aulas das turmas deste professor, neste ano letivo — com tudo o que a
     * linha lê já carregado.
     *
     * @return Builder<Lesson>
     */
    public function query(User $teacher, AcademicYear $academicYear): Builder
    {
        return Lesson::query()
            ->whereHas('schoolClass', fn ($query) => $query
                ->where('academic_year_id', $academicYear->getKey())
                ->whereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->getKey())))
            // `classGroup` carregado com a lista inteira, e nunca lido por
            // aula: o cartão da semana mostra o rótulo em cada linha, e uma
            // consulta por linha faria de uma semana cheia trinta idas à base
            // de dados só para escrever «T1» trinta vezes.
            ->with('schoolClass.subject')
            // `schoolClass.organization` porque a regra do arquivamento se
            // lê em DATAS LOCAIS DA ORGANIZAÇÃO, e sem isto seria uma
            // consulta por aula só para saber o fuso.
            ->with('schoolClass.organization')
            ->with('classGroup')
            // O sumário INTEIRO: a linha leva o texto completo, e quem o
            // quer truncar (a lista) faz isso no ecrã. `reviewed_at` para
            // dizer se um sumário de aula lecionada já foi revisto.
            ->with(['summary' => fn (Relation $query) => $query->select(['id', 'lesson_id', 'content', 'reviewed_at'])])
            // Uma contagem por linha, não uma consulta por linha: o número de
            // faltas é sempre pedido junto com a própria linha (`withCount`),
            // e nunca lido por aula à parte.
            ->withCount(['attendances as absent_count' => fn ($query) => $query->where('status', 'absent')])
            // `plan_count` e `attendances_count` — TODAS as linhas de
            // assiduidade, e não só as faltas — existem para uma pergunta
            // só: esta aula tem trabalho pedagógico agarrado a ela? É o
            // que ClassArchivalWindow::isEmptyScheduledOccurrence lê para
            // nunca esconder um plano escrito ou um rascunho de faltas.
            ->withCount(['plan', 'attendances']);
    }

    /**
     * @param  iterable<Lesson>  $lessons  lidas por `query()`
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $lessons, User $teacher): array
    {
        // A TURMA ARQUIVADA NÃO TRAZ AS SUAS OCORRÊNCIAS VAZIAS (0.154.3). A
        // mesma regra temporal do Horário do Professor e da materialização,
        // lida do mesmo sítio: a partir do dia do arquivamento, inclusive, as
        // ocorrências que o horário deixou para trás — por preparar, abertas,
        // sem sumário, sem plano, sem uma única linha de assiduidade — deixam
        // de aparecer na vista operacional.
        //
        // E SÓ ESSAS. Uma aula lecionada, uma com sumário, uma com plano ou
        // rascunho de faltas, e QUALQUER aula introduzida à mão continuam a
        // aparecer, arquivada ou não a turma: esconder trabalho pedagógico
        // seria perdê-lo de vista, que é o mesmo mal que apagá-lo. Também
        // não se esconde nada ANTES do arquivamento.
        //
        // ESCONDER, E NUNCA APAGAR: nenhuma linha é tocada.
        $visible = (new EloquentCollection($lessons))->reject(function (Lesson $lesson): bool {
            $timezone = $this->archivalWindow->timezoneFor($lesson->schoolClass);
            $day = $lesson->starts_at->setTimezone($timezone)->toDateString();

            return ! $this->archivalWindow->coversDate($lesson->schoolClass, $day, $timezone)
                && $this->archivalWindow->isEmptyScheduledOccurrence($lesson);
        })->values();

        if ($visible->isEmpty()) {
            return [];
        }

        // Duas consultas para a lista inteira, e nunca uma por linha: os tons
        // do professor e os acontecimentos do dia. Os acontecimentos nunca
        // viajam com o Calendário trancado — canRead() e não allows(): um
        // plano só de leitura continua a ver os seus acontecimentos.
        $tones = $this->identityTones->forTeacher(
            (int) $teacher->getKey(),
            array_values(array_unique($visible->pluck('class_id')->map(fn ($id): int => (int) $id)->all())),
        );
        $dayEvents = $this->entitlements->canRead('calendar')
            ? $this->dayEvents->forLessons($visible, $teacher)
            : [];

        return array_values($visible->map(function (Lesson $lesson) use ($dayEvents, $tones): array {
            $content = trim((string) $lesson->summary?->content);

            return [
                'ulid' => $lesson->ulid,
                'starts_at' => $lesson->starts_at->toIso8601String(),
                'ends_at' => $lesson->ends_at?->toIso8601String(),
                'school_class' => [
                    'ulid' => $lesson->schoolClass->ulid,
                    'label' => $lesson->schoolClass->label,
                    'is_support_class' => (bool) $lesson->schoolClass->is_support_class,
                ],
                // «8.º F» ou «8.º F · T1», composto no servidor
                // (Lesson::contextLabel()) para que o cartão da semana, o
                // cabeçalho do sumário e o `<Head>` não possam divergir no
                // separador que usam.
                'context_label' => $lesson->contextLabel(),
                'class_group_label' => $lesson->classGroup?->label,
                'class_group_id' => $lesson->class_group_id,
                'subject' => $lesson->schoolClass->subject->name,
                'status' => $lesson->status->value,
                'status_label' => $this->statusLabel($lesson->status),
                'has_summary' => $content !== '',
                // O texto COMPLETO, aparado — NULL quando vazio. Substitui o
                // excerto e o texto completo separados: truncar para a lista
                // é apresentação, e faz-se no ecrã.
                'summary' => $content === '' ? null : $content,
                // A versão que o ecrã devolve ao gravar (bloqueio otimista).
                'summary_version' => $lesson->summary_version,
                'summary_reviewed' => $lesson->summary?->reviewed_at !== null,
                'lesson_number' => $lesson->lesson_number,
                // Decidido no servidor e enviado já decidido — esconder o
                // botão é apresentação, e DeleteLesson repete a recusa por
                // sua conta quando o pedido lá chega na mesma.
                'can_delete' => ! $lesson->isClosed(),
                'can_clear_summary' => ! $lesson->isTaught() && $content !== '',
                // Como a ocorrência fechou (0.146.0) — NULL enquanto aberta.
                'outcome' => $lesson->outcome?->value,
                'outcome_label' => $lesson->outcome?->label(),
                'attendance_recorded' => $lesson->attendanceRecorded(),
                // NULL enquanto não está consolidada: antes disso o que
                // existe é só o rascunho de faltas, e mostrá-lo como
                // contagem definitiva seria confundir um rascunho com um
                // registo.
                'absent_count' => $lesson->attendanceRecorded() ? (int) $lesson->absent_count : null,
                // A cor que ESTE professor deu à turma (ClassTeacher).
                'identity_tone' => $tones[$lesson->class_id] ?? null,
                'day_events' => $dayEvents[(int) $lesson->getKey()] ?? [],
            ];
        })->all());
    }

    private function statusLabel(LessonStatus $status): string
    {
        return match ($status) {
            LessonStatus::Preparation => __('Por preparar'),
            LessonStatus::Prepared => __('Preparada'),
            LessonStatus::Taught => __('Lecionada'),
        };
    }
}
