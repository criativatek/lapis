<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use App\Services\Classes\ClassArchivalWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;

final class WeeklyLessonsQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

    public function __construct(private readonly ClassArchivalWindow $archivalWindow) {}

    /** @return list<array<string, mixed>> */
    public function for(User $teacher, AcademicYear $academicYear, CarbonImmutable $weekStart): array
    {
        $from = $weekStart->setTimezone(self::TIMEZONE)->startOfWeek()->startOfDay();
        $to = $from->endOfWeek()->endOfDay();

        return array_values(Lesson::query()
            ->whereBetween('starts_at', [$from, $to])
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
            // O sumário INTEIRO, e já não só os primeiros 180 caracteres: a
            // lista continua a mostrar o excerto truncado, mas «Ver mais»
            // passa a abrir o texto completo sem uma segunda ida ao servidor
            // (§21). Um sumário são poucos kB de texto e a semana são poucas
            // dezenas de aulas — o custo de o trazer é menor do que o de um
            // pedido por cada vez que alguém quer ler o que escreveu.
            ->with(['summary' => fn (Relation $query) => $query->select(['id', 'lesson_id', 'content'])])
            // Uma contagem por linha, não uma consulta por linha: o número de
            // faltas é sempre pedido junto com a própria linha (`withCount`),
            // e nunca lido por aula à parte — a mesma razão que classGroup
            // acima é carregado com a lista inteira.
            ->withCount(['attendances as absent_count' => fn ($query) => $query->where('status', 'absent')])
            // `plan_count` e `attendances_count` — TODAS as linhas de
            // assiduidade, e não só as faltas — existem para uma pergunta
            // só: esta aula tem trabalho pedagógico agarrado a ela? É o
            // que ClassArchivalWindow::isEmptyScheduledOccurrence lê para
            // nunca esconder um plano escrito ou um rascunho de faltas. Em
            // contagens com a lista inteira, e nunca uma consulta por linha.
            ->withCount(['plan', 'attendances'])
            ->orderBy('starts_at')
            ->get()
            // A TURMA ARQUIVADA NÃO TRAZ AS SUAS OCORRÊNCIAS VAZIAS PARA A
            // SEMANA (0.154.3). A mesma regra temporal do Horário do Professor
            // e da materialização, lida do mesmo sítio: a partir do dia do
            // arquivamento, inclusive, as ocorrências que o horário deixou para
            // trás — por preparar, abertas, sem sumário, sem plano, sem uma
            // única linha de assiduidade — deixam de aparecer na vista
            // operacional. Eram estas as aulas de 25/09 e de outubro da turma 23.
            //
            // E SÓ ESSAS. Uma aula lecionada, uma com sumário, uma com plano ou
            // rascunho de faltas, e QUALQUER aula introduzida à mão continuam a
            // aparecer, arquivada ou não a turma: esconder trabalho pedagógico
            // seria perdê-lo de vista, que é o mesmo mal que apagá-lo. Também
            // não se esconde nada ANTES do arquivamento — nessas semanas a
            // turma estava viva e a semana é a que foi.
            //
            // ESCONDER, E NUNCA APAGAR: nenhuma linha é tocada. As aulas
            // continuam na base de dados e acessíveis pela própria turma
            // arquivada, que Turmas continua a listar no seu filtro de
            // arquivadas e cuja página se abre como sempre.
            ->reject(function (Lesson $lesson): bool {
                $timezone = $this->archivalWindow->timezoneFor($lesson->schoolClass);
                $day = $lesson->starts_at->setTimezone($timezone)->toDateString();

                return ! $this->archivalWindow->coversDate($lesson->schoolClass, $day, $timezone)
                    && $this->archivalWindow->isEmptyScheduledOccurrence($lesson);
            })
            ->map(function (Lesson $lesson): array {
                $content = trim((string) $lesson->summary?->content);
                $excerpt = $content === '' ? null : mb_substr($content, 0, 180);

                return [
                    'ulid' => $lesson->ulid,
                    'starts_at' => $lesson->starts_at->toIso8601String(),
                    'ends_at' => $lesson->ends_at?->toIso8601String(),
                    'school_class' => ['ulid' => $lesson->schoolClass->ulid, 'label' => $lesson->schoolClass->label],
                    // «8.º F» ou «8.º F · T1», composto no servidor
                    // (Lesson::contextLabel()) para que o cartão da semana, o
                    // cabeçalho do sumário e o `<Head>` não possam divergir no
                    // separador que usam.
                    'context_label' => $lesson->contextLabel(),
                    'class_group_label' => $lesson->classGroup?->label,
                    'subject' => $lesson->schoolClass->subject->name,
                    'status' => $lesson->status->value,
                    'status_label' => $this->statusLabel($lesson->status),
                    'has_summary' => $content !== '',
                    'summary_excerpt' => $excerpt,
                    // O texto completo só viaja quando é MAIOR do que o
                    // excerto: repetir os mesmos 40 caracteres em dois campos
                    // duplicaria a resposta sem dar nada a ler ao professor, e
                    // é o `null` aqui que diz ao ecrã que não há «Ver mais»
                    // nenhum para mostrar.
                    'summary_full' => $content !== '' && mb_strlen($content) > 180 ? $content : null,
                    'lesson_number' => $lesson->lesson_number,
                    'class_group_id' => $lesson->class_group_id,
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
