<?php

namespace App\Models\Concerns;

use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Support\Carbon;

/**
 * What the two characterisations — a class's and a student's — have in common.
 *
 * They are separate tables on purpose: one describes a group and has a single
 * section, the other describes a person and has six. What they share is how
 * they are written and how their history is kept, and that shared part lives
 * here so that RecordCharacterisation can hold either without either pretending
 * to be the other.
 *
 * @property int|null $updated_by
 * @property Carbon|null $last_updated_at
 */
trait IsCharacterisation
{
    /**
     * The sections this kind of characterisation actually has.
     *
     * Asked of the model rather than supplied by the caller: a request that
     * sends `strengths` to a class must not be able to create the column by
     * asking for it.
     *
     * @return list<string>
     */
    public function characterisationSections(): array
    {
        return CharacterisationSection::keys();
    }

    /**
     * Whether anything has been written here yet. «Sem caracterização» has to
     * mean it, so whitespace does not count.
     */
    public function hasAnySection(): bool
    {
        foreach ($this->characterisationSections() as $section) {
            if (trim((string) $this->{$section}) !== '') {
                return true;
            }
        }

        return false;
    }
}
