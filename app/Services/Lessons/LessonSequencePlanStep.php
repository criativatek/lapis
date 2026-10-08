<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\LessonSequenceItem;

/**
 * Um passo do plano: o que acontece a esta aula e que elemento (se algum) lá
 * fica. `details` é o que seria escrito no sumário — já decidido pelo
 * planeamento, para que a execução não volte a decidir nada.
 */
final readonly class LessonSequencePlanStep
{
    /**
     * @param  array{content?: string, private_notes?: ?string, resources?: ?string, homework?: ?string}  $details
     */
    public function __construct(
        public LessonSequenceStepKind $kind,
        public Lesson $lesson,
        public bool $lessonIsNew,
        public ?LessonSequenceItem $item,
        public array $details = [],
    ) {}

    /**
     * Identidade da aula que sobrevive à reversão da pré-visualização: o ULID
     * muda quando as aulas materializadas são desfeitas, a tripla não.
     */
    public function lessonIdentity(): string
    {
        return ($this->lesson->class_group_id ?? '').'|'
            .($this->lesson->recurring_lesson_slot_id ?? '').'|'
            .$this->lesson->starts_at->format('Y-m-d H:i:s');
    }
}
