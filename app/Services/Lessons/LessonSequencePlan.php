<?php

namespace App\Services\Lessons;

use App\Actions\Lessons\LessonCopyOptions;
use App\Models\Lesson;
use App\Models\LessonSequenceItem;
use Carbon\CarbonImmutable;

/**
 * O resultado de planear uma aplicação de sequência — o MESMO objeto para a
 * pré-visualização (que o mostra e o reverte) e para a execução (que o escreve).
 */
final readonly class LessonSequencePlan
{
    /**
     * @param  list<LessonSequencePlanStep>  $steps
     * @param  list<array{item: LessonSequenceItem, lesson: Lesson}>  $alreadyApplied
     * @param  list<LessonSequenceItem>  $unplaced
     */
    public function __construct(
        public CarbonImmutable $from,
        public ?int $classGroupId,
        public LessonCopyOptions $options,
        public array $steps,
        public array $alreadyApplied,
        public array $unplaced,
    ) {}

    /** Todos os elementos têm aula? Um plano incompleto nunca se executa. */
    public function complete(): bool
    {
        return $this->unplaced === [];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (LessonSequenceStepKind::cases() as $kind) {
            $counts[$kind->value] = 0;
        }

        foreach ($this->steps as $step) {
            $counts[$step->kind->value]++;
        }

        $counts['unplaced'] = count($this->unplaced);

        return $counts;
    }

    public function replaceCount(): int
    {
        return $this->counts()[LessonSequenceStepKind::Replace->value];
    }

    /**
     * A impressão digital do plano. Calculada sobre a IDENTIDADE da aula e não
     * sobre o ULID (a pré-visualização desfaz as aulas que materializou), e
     * sobre a versão do sumário — se alguém preparar uma aula entre a
     * pré-visualização e a confirmação, a versão sobe e o token deixa de bater.
     */
    public function token(): string
    {
        $steps = array_map(fn (LessonSequencePlanStep $step): array => [
            $step->kind->value,
            $step->lessonIdentity(),
            $step->item?->ulid,
            $step->item === null ? null : self::itemFingerprint($step->item),
            (int) $step->lesson->summary_version,
        ], $this->steps);

        return hash('sha256', (string) json_encode([
            'from' => $this->from->format('Y-m-d'),
            'audience' => $this->classGroupId,
            'options' => $this->options->toArray(),
            'steps' => $steps,
            'unplaced' => array_map(fn (LessonSequenceItem $item): string => $item->ulid, $this->unplaced),
        ]));
    }

    public static function itemFingerprint(LessonSequenceItem $item): string
    {
        return hash('sha256', (string) json_encode([
            (string) $item->summary,
            (string) $item->resources,
            (string) $item->homework,
            (string) $item->private_notes,
        ]));
    }
}
