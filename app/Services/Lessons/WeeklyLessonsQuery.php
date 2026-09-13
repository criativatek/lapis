<?php

namespace App\Services\Lessons;

use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;

final class WeeklyLessonsQuery
{
    private const TIMEZONE = 'Europe/Lisbon';

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
            ->with('classGroup')
            // O sumário INTEIRO, e já não só os primeiros 180 caracteres: a
            // lista continua a mostrar o excerto truncado, mas «Ver mais»
            // passa a abrir o texto completo sem uma segunda ida ao servidor
            // (§21). Um sumário são poucos kB de texto e a semana são poucas
            // dezenas de aulas — o custo de o trazer é menor do que o de um
            // pedido por cada vez que alguém quer ler o que escreveu.
            ->with(['summary' => fn (Relation $query) => $query->select(['id', 'lesson_id', 'content'])])
            ->orderBy('starts_at')
            ->get()
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
                    'can_delete' => $lesson->status !== LessonStatus::Taught,
                    'can_clear_summary' => $lesson->status !== LessonStatus::Taught && $content !== '',
                ];
            })->all());
    }

    private function statusLabel(LessonStatus $status): string
    {
        return match ($status) {
            LessonStatus::Preparation => __('Por preparar'),
            LessonStatus::Prepared => __('Preparado'),
            LessonStatus::Taught => __('Lecionado'),
        };
    }
}
