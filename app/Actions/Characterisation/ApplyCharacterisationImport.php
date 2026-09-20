<?php

namespace App\Actions\Characterisation;

use App\Models\CharacterisationImportBatch;
use App\Models\CharacterisationSource;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\User;
use App\Services\Characterisation\RecordCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a characterisation import — and it is the only thing that can.
 *
 * THIS ACTION CANNOT PARSE. It takes decisions: an enrolment, some text, some
 * measure codes, each one named explicitly by whoever confirmed the preview. It
 * has no reader, no resolver and no matcher, so there is no path from «a file
 * was uploaded» to «a row was written» that does not pass through a person. The
 * guarantee the brief asks for — nothing saved before confirmation — is
 * therefore structural rather than a rule someone has to remember.
 *
 * EVERYTHING IS RE-VERIFIED HERE. The preview is a document that lived in a
 * browser, and a browser is not a place where authorisation decisions are safe.
 * Every enrolment ULID is resolved through the class itself, so a ULID from
 * another class — or another organization — resolves to nothing and the row is
 * dropped rather than written somewhere it does not belong.
 */
class ApplyCharacterisationImport
{
    public function __construct(private readonly RecordCharacterisation $recorder) {}

    /**
     * @param  list<array{enrollment_ulid: string, sections?: array<string, string|null>, measure_codes?: list<string>, raw_tokens?: array<string, string>}>  $decisions
     * @return array{batch: CharacterisationImportBatch, written: int, skipped: int}
     */
    public function apply(
        SchoolClass $class,
        array $decisions,
        string $sourceKind,
        ?string $originalFilename,
        User $confirmedBy,
    ): array {
        return DB::transaction(function () use ($class, $decisions, $sourceKind, $originalFilename, $confirmedBy) {
            // Read once, through the class. Resolving each ULID with its own
            // query would be a query per student AND would need the tenant
            // scope restating every time; going through the relation applies
            // both the class and the organization boundary in one place.
            $enrollments = $class->enrollments()->get()->keyBy('ulid');

            $batch = new CharacterisationImportBatch([
                'class_id' => $class->getKey(),
                'source_kind' => $sourceKind,
                'original_filename' => $originalFilename,
                'row_count' => count($decisions),
                'confirmed_by' => $confirmedBy->getKey(),
                'confirmed_at' => Carbon::now(),
            ]);
            $batch->save();

            $written = 0;
            $skipped = 0;

            foreach ($decisions as $decision) {
                $enrollment = $enrollments->get($decision['enrollment_ulid']);

                if (! $enrollment instanceof Enrollment) {
                    // Not an error to report back: a ULID that does not belong
                    // to this class is either a stale tab or someone trying it
                    // on, and neither deserves confirmation that the enrolment
                    // exists somewhere else (ADR-0002).
                    $skipped++;

                    continue;
                }

                $characterisation = $this->characterisationFor($enrollment);

                $changed = $this->recorder->apply(
                    characterisation: $characterisation,
                    sections: $this->sectionsFrom($decision),
                    author: $confirmedBy,
                    source: CharacterisationSource::Import,
                    batch: $batch,
                );

                $measures = $this->writeMeasures($characterisation, $decision, $batch, $confirmedBy);

                if ($changed !== [] || $measures > 0) {
                    $written++;
                } else {
                    $skipped++;
                }
            }

            return ['batch' => $batch, 'written' => $written, 'skipped' => $skipped];
        });
    }

    /**
     * Found, or built and not yet saved.
     *
     * A row is a foreign key, and a foreign key blocks the student from ever
     * being removed from the class. Creating one for a row that turns out to
     * carry nothing — every section blank, every code unrecognised — would
     * strand a student who was enrolled by mistake.
     */
    private function characterisationFor(Enrollment $enrollment): EnrollmentCharacterisation
    {
        return EnrollmentCharacterisation::query()->firstOrNew(['enrollment_id' => $enrollment->getKey()]);
    }

    /**
     * Only the six known sections, and only from this decision. A key the
     * enum does not know is dropped rather than written, so a crafted request
     * cannot set a column the screen never offered.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string|null>
     */
    private function sectionsFrom(array $decision): array
    {
        $sections = [];

        foreach (CharacterisationSection::keys() as $key) {
            if (array_key_exists($key, $decision['sections'] ?? [])) {
                $sections[$key] = $decision['sections'][$key];
            }
        }

        return $sections;
    }

    /**
     * Destination (B): the measures the teacher accepted, stored typed.
     *
     * Stored as (level, code) rather than as a sentence because that is what a
     * future legal instrument can reuse without re-reading anyone's prose — and
     * always beside the `raw_token`, so what the school's own file said survives
     * whatever the interpretation later turns out to be worth.
     *
     * NOTE WHAT THIS DOES NOT DO: it creates no Intervention. An intervention is
     * a teacher's action, with dates, an objective and reviews; deducing one
     * from a spreadsheet cell would be inventing a measure nobody decided on.
     *
     * @param  array<string, mixed>  $decision
     */
    private function writeMeasures(
        EnrollmentCharacterisation $characterisation,
        array $decision,
        CharacterisationImportBatch $batch,
        User $confirmedBy,
    ): int {
        $written = 0;

        foreach (($decision['measure_codes'] ?? []) as $rawCode) {
            $code = SupportMeasureCode::tryFrom((string) $rawCode);

            if ($code === null) {
                // Only codes the catalogue actually has. An unknown string here
                // is not a measure to record verbatim — it is destination (D),
                // which stores nothing at all.
                continue;
            }

            // Keyed on the pair, not on the code alone. Today the level is
            // always derived from the code so the two can never disagree — but
            // when the versioned catalogue replaces the resolver and a code
            // becomes valid at more than one level, a code-only key would start
            // silently discarding the second one.
            $exists = $characterisation->exists && $characterisation->sourceMeasures()
                ->where('support_measure_code', $code->value)
                ->where('support_measure_level', $code->level()->value)
                ->exists();

            if ($exists) {
                // Re-importing the same sheet must not stack duplicates of the
                // same measure on one student.
                continue;
            }

            $rawToken = trim((string) ($decision['raw_tokens'][$rawCode] ?? ''));

            if ($rawToken === '') {
                // NEVER fall back to the measure's own label. `raw_token` is
                // rendered as «o ficheiro indicava: …», so substituting
                // Lapispro's catalogue wording there would put this
                // application's words into the school document's mouth — the
                // exact report/assert distinction the whole feature is built
                // around. Without the source text there is nothing honest to
                // store, so nothing is stored.
                continue;
            }

            // The parent may still be unsaved: a row that carries measures but
            // no section text never went through RecordCharacterisation's save.
            // It is persisted here, at the first moment there is actually
            // something to hang off it.
            if (! $characterisation->exists) {
                $characterisation->save();
            }

            $measure = new EnrollmentCharacterisationSourceMeasure([
                'enrollment_characterisation_id' => $characterisation->getKey(),
                'support_measure_level' => $code->level(),
                'support_measure_code' => $code,
                'raw_token' => $rawToken,
                'unresolved_annotations' => $decision['annotations'][$rawCode] ?? null,
                'import_batch_id' => $batch->getKey(),
                'confirmed_by' => $confirmedBy->getKey(),
                'confirmed_at' => Carbon::now(),
            ]);
            $measure->save();

            $written++;
        }

        return $written;
    }
}
