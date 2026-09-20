<?php

namespace App\Services\Characterisation;

use App\Models\CharacterisationImportBatch;
use App\Models\CharacterisationRevision;
use App\Models\CharacterisationSource;
use App\Models\ClassCharacterisation;
use App\Models\EnrollmentCharacterisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one place a characterisation's text changes.
 *
 * Both the edit screen and the import come through here, which is what keeps
 * the history honest: there is no second writer that could update the text and
 * forget the revision, so «last updated» and «what it said before» cannot drift
 * apart from what actually happened.
 *
 * A SAVE THAT CHANGES NOTHING WRITES NOTHING. Opening a student, reading, and
 * closing is the commonest thing a teacher will do on this screen, and it must
 * not leave a trail of identical revisions that buries the three edits that
 * mattered. Absence of a row is the normal case, not a gap.
 */
class RecordCharacterisation
{
    /**
     * @param  ClassCharacterisation|EnrollmentCharacterisation  $characterisation
     * @param  array<string, string|null>  $sections  Keyed by CharacterisationSection value.
     * @return list<string> the sections that actually changed
     */
    public function apply(
        Model $characterisation,
        array $sections,
        ?User $author,
        CharacterisationSource $source = CharacterisationSource::Manual,
        ?CharacterisationImportBatch $batch = null,
    ): array {
        $changed = [];
        $previous = [];

        foreach ($characterisation->characterisationSections() as $section) {
            if (! array_key_exists($section, $sections)) {
                // A section the caller did not mention is a section it has no
                // opinion about. Treating absence as "blank it" would let the
                // import wipe text the teacher wrote by hand, simply because
                // the spreadsheet had no column for it.
                continue;
            }

            $new = $this->normalise($sections[$section]);
            $old = $this->normalise($characterisation->{$section});

            if ($new === $old) {
                continue;
            }

            $changed[] = $section;
            $previous[$section] = $old;
            $characterisation->{$section} = $new;
        }

        if ($changed === []) {
            return [];
        }

        $characterisation->updated_by = $author?->getKey();
        $characterisation->last_updated_at = Carbon::now();
        $characterisation->save();

        $revision = new CharacterisationRevision([
            'changed_sections' => $changed,
            'previous_values' => $previous,
            'author_id' => $author?->getKey(),
            'source' => $source,
            'import_batch_id' => $batch?->getKey(),
            'created_at' => Carbon::now(),
        ]);

        $revision->characterisable()->associate($characterisation);
        $revision->save();

        return $changed;
    }

    /**
     * Empty and absent are the same thing for a text section, and both are
     * stored as null. A column of empty strings would make «sem caracterização»
     * untrue for every student anyone had ever opened.
     */
    private function normalise(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
