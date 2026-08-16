<?php

namespace App\Support\Assessment;

use App\Models\Scale;

/**
 * How a classification is CHOSEN on a given scale, described once for every
 * screen that lets a teacher choose one.
 *
 * Both Resultados and Classificações now offer the decision, and they must
 * offer exactly the same thing: the same word for it, the same closed list of
 * bands, the same interval. Building that payload twice is how two screens end
 * up disagreeing about what a class is graded on.
 *
 * Everything here is read from the scale itself. There is no «básico» and no
 * «secundário» — those are not properties this app stores, and a scale that
 * knows its own kind and its own limits already says all of it.
 */
final readonly class DecisionScale
{
    private function __construct(
        public ?Scale $scale,
    ) {}

    public static function for(?Scale $scale): self
    {
        return new self($scale);
    }

    /** «Nível atribuído» on a scale made of bands, «Classificação atribuída» on an interval. */
    public function label(): string
    {
        return $this->classifiesByLevel() ? __('Nível atribuído') : __('Classificação atribuída');
    }

    public function classifiesByLevel(): bool
    {
        return $this->scale?->classifiesByLevel() ?? false;
    }

    /**
     * The closed list to choose from. Empty on an interval scale, where the
     * limits are the definition instead.
     *
     * @return list<array<string, mixed>>
     */
    public function levels(): array
    {
        if (! $this->classifiesByLevel() || $this->scale === null) {
            return [];
        }

        $options = [];

        foreach ($this->scale->levels as $level) {
            $options[] = [
                'id' => (int) $level->id,
                'code' => (string) $level->code,
                'label' => (string) $level->label,
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'label' => $this->label(),
            'classifies_by_level' => $this->classifiesByLevel(),
            'levels' => $this->levels(),
            'min_value' => $this->scale === null ? null : (string) $this->scale->min_value,
            'max_value' => $this->scale === null ? null : (string) $this->scale->max_value,
        ];
    }
}
