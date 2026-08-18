<?php

namespace App\Domain\Reporting;

/**
 * What one section IS, before any data has been read: its identity, its
 * heading, what a school must be entitled to in order to have it at all, and
 * whether it exists only because the teacher filled something in.
 *
 * Deliberately inert. It holds no data and generates no text — it is what the
 * creation screen offers, what the capability gate reads, and what the composer
 * walks. The content lives in ReportSection rows.
 */
readonly class SectionDefinition
{
    /**
     * @param  string|null  $module  The capability required, or null for Base.
     * @param  bool  $defaultIncluded  Whether it is ticked before the teacher chooses (§45).
     * @param  bool  $needsTeacherInput  Whether it says nothing at all until the teacher speaks (§8).
     * @param  bool  $mayNameStudents  Whether including it can put an individual's name on a class-wide document (§28, §57).
     */
    public function __construct(
        public SectionKey $key,
        public string $heading,
        public ?string $module = null,
        public bool $defaultIncluded = true,
        public bool $needsTeacherInput = false,
        public bool $mayNameStudents = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value,
            'heading' => $this->heading,
            'module' => $this->module,
            'default_included' => $this->defaultIncluded,
            'needs_teacher_input' => $this->needsTeacherInput,
            'may_name_students' => $this->mayNameStudents,
        ];
    }
}
