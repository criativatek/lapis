<?php

namespace App\Actions\Lessons;

/**
 * Which of a sequence item's four fields ApplyLessonSequence is allowed to
 * write.
 *
 * `privateNotes` defaults to false and stays false unless a caller explicitly
 * opts in — a private note written for one class's rhythm rarely belongs
 * verbatim to another, so copying it is never assumed.
 */
final readonly class LessonCopyOptions
{
    public function __construct(
        public bool $summary = false,
        public bool $resources = false,
        public bool $homework = false,
        public bool $privateNotes = false,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            summary: (bool) ($options['summary'] ?? false),
            resources: (bool) ($options['resources'] ?? false),
            homework: (bool) ($options['homework'] ?? false),
            privateNotes: (bool) ($options['private_notes'] ?? false),
        );
    }

    /**
     * @return array{summary: bool, resources: bool, homework: bool, private_notes: bool}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'resources' => $this->resources,
            'homework' => $this->homework,
            'private_notes' => $this->privateNotes,
        ];
    }
}
