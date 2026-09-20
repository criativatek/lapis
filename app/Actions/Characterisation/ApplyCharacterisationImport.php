<?php

namespace App\Actions\Characterisation;

use App\Actions\Interventions\CreateIntervention;
use App\Models\CharacterisationImportBatch;
use App\Models\CharacterisationSource;
use App\Models\Enrollment;
use App\Models\EnrollmentCharacterisation;
use App\Models\EnrollmentCharacterisationSourceMeasure;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionOrigin;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\LegalMappingSource;
use App\Models\SchoolClass;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Models\User;
use App\Services\Characterisation\Import\MergeCharacterisationSections;
use App\Services\Characterisation\RecordCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use App\Support\Characterisation\LegalCodeResolver;
use App\Support\Interventions\LegalFrameworkResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a characterisation import — and it is the only thing that can.
 *
 * THIS ACTION CANNOT PARSE. It takes decisions: an enrolment, some text, some
 * measure codes, each one named explicitly by whoever confirmed the preview. It
 * has no reader and no matcher, so there is no path from «a file was uploaded»
 * to «a row was written» that does not pass through a person. The guarantee the
 * brief asks for — nothing saved before confirmation — is therefore structural
 * rather than a rule someone has to remember.
 *
 * It does hold the LegalCodeResolver, and only for one question: what level the
 * applicable framework puts a measure at. That is a lookup, not a parse — it
 * turns no text into decisions, and it is here precisely so that the level
 * written to a child's record is the law's answer rather than the client's.
 *
 * EVERYTHING IS RE-VERIFIED HERE. The preview is a document that lived in a
 * browser, and a browser is not a place where authorisation decisions are safe.
 * Every enrolment ULID is resolved through the class itself, so a ULID from
 * another class — or another organization — resolves to nothing and the row is
 * dropped rather than written somewhere it does not belong.
 *
 * IMPORTING IS ADDITIVE. Section text is no longer the client's new value
 * written verbatim — it is merged, here, against the CURRENT value read from
 * the database inside this same transaction, through
 * `MergeCharacterisationSections`. A client that posts what looks like a full
 * replacement can only ever ADD to what is recorded; there is no code path in
 * this class that blanks or overwrites a section, because merging happens
 * after the authoritative read and before the only save.
 */
class ApplyCharacterisationImport
{
    public function __construct(
        private readonly RecordCharacterisation $recorder,
        private readonly LegalCodeResolver $resolver,
        private readonly MergeCharacterisationSections $merger,
        private readonly CreateIntervention $creator,
        private readonly LegalFrameworkResolver $frameworks,
    ) {}

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

            // Resolved once per import, not once per row: it is always "as the
            // teacher confirms this, today" — a batch never straddles two
            // regimes because opening the preview and clicking "confirmar"
            // happen in the same instant as far as the law is concerned.
            $startedOn = Carbon::now();
            $framework = $this->frameworks->for($class->organization, $startedOn);
            $frameworkCode = $framework->hasLegalTaxonomy() ? $framework->code() : null;

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
                    sections: $this->mergedSectionsFrom($characterisation, $decision),
                    author: $confirmedBy,
                    source: CharacterisationSource::Import,
                    batch: $batch,
                );

                $measures = $this->writeMeasures($characterisation, $decision, $batch, $confirmedBy);

                $interventionsCreated = $this->createInterventions(
                    $class,
                    $enrollment,
                    $decision,
                    $startedOn,
                    $frameworkCode,
                    $confirmedBy,
                );

                if ($changed !== [] || $measures > 0 || $interventionsCreated > 0) {
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
     * Only the six known sections, and only from this decision, merged against
     * what is ALREADY recorded — never the incoming text taken as-is.
     *
     * The merge is why this class can promise "additive". `$characterisation`
     * is read from the database inside the surrounding transaction, so this is
     * the authoritative current value, not whatever the browser believed it to
     * be when the preview was built — a stale tab, or a second import
     * confirmed while this one was open, can never cause the older text to be
     * lost.
     *
     * @param  array<string, mixed>  $decision
     * @return array<string, string|null>
     */
    private function mergedSectionsFrom(EnrollmentCharacterisation $characterisation, array $decision): array
    {
        $incoming = [];

        foreach (CharacterisationSection::keys() as $key) {
            if (array_key_exists($key, $decision['sections'] ?? [])) {
                $incoming[$key] = $decision['sections'][$key];
            }
        }

        $current = [];

        foreach (array_keys($incoming) as $key) {
            $current[$key] = $characterisation->{$key};
        }

        $results = $this->merger->merge($current, $incoming);

        $sections = [];

        foreach ($results as $key => $result) {
            if (! array_key_exists($key, $incoming)) {
                // A section this decision never mentioned stays untouched —
                // the merger still computes a (no-op) result for it because it
                // iterates every CharacterisationSection, but only sections
                // the decision actually named are passed on to the recorder.
                continue;
            }

            $sections[$key] = $result->mergedValue;
        }

        return $sections;
    }

    /**
     * Destination (B): the measures the teacher accepted, stored typed — and,
     * since the reconciliation below, also the measures that become a
     * structured Intervention in Estratégias e Medidas (see
     * `createInterventions()`).
     *
     * Stored as (level, code) rather than as a sentence because that is what a
     * future legal instrument can reuse without re-reading anyone's prose — and
     * always beside the `raw_token`, so what the school's own file said survives
     * whatever the interpretation later turns out to be worth.
     *
     * THIS DOCBLOCK USED TO SAY THIS CREATES NO INTERVENTION, because
     * "deducing one from a spreadsheet cell would be inventing a measure
     * nobody decided on". That reasoning was sound and its conclusion is still
     * honoured here — nothing is EVER deduced from a cell by itself. What
     * changed is the premise: a measure only ever reaches this method inside a
     * decision a teacher submitted from the confirmation screen, per row and
     * per measure, exactly like every other field this class writes. Nobody
     * is deducing anything at that point — a person looked at the preview and
     * said yes. So the original worry (a cell becoming a measure nobody
     * decided on) does not apply to a CONFIRMED decision, and refusing to
     * create the Intervention here would instead be the opposite failure: a
     * measure the teacher explicitly accepted, silently absent from
     * Estratégias e Medidas, discoverable only by knowing to look at the
     * characterisation screen instead.
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

        foreach ($this->recognisedMeasures($decision) as [$code, $level, $rawToken]) {
            // Keyed on the pair, not on the code alone: when a future regime
            // puts a code at a different level, a code-only key would silently
            // discard the second one.
            $exists = $characterisation->exists && $characterisation->sourceMeasures()
                ->where('support_measure_code', $code->value)
                ->where('support_measure_level', $level->value)
                ->exists();

            if ($exists) {
                // Re-importing the same sheet must not stack duplicates of the
                // same measure on one student.
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
                'support_measure_level' => $level,
                'support_measure_code' => $code,
                'raw_token' => $rawToken,
                'unresolved_annotations' => $decision['annotations'][$code->value] ?? null,
                'import_batch_id' => $batch->getKey(),
                'confirmed_by' => $confirmedBy->getKey(),
                'confirmed_at' => Carbon::now(),
            ]);
            $measure->save();

            $written++;
        }

        return $written;
    }

    /**
     * Destination (B), the second half: for every measure that actually got
     * (or would get) stored typed against the characterisation, also create a
     * structured Intervention — reusing Intervention + InterventionSupportMeasure
     * + the same catalogue, family, legal level and framework snapshot the
     * manual form uses, via `CreateIntervention`. No second measures table.
     *
     * DEDUPLICATION (§30): if the same code is already an active (open)
     * Intervention for this enrolment, nothing new is created — re-confirming
     * an import, or importing a second sheet that names a measure the teacher
     * already registered by hand, must not double the count in Estratégias e
     * Medidas. This is deliberately the ONLY differentiator. A difference in
     * period, context, framework or status is exactly the kind of thing this
     * method refuses to reason about silently: rather than guess whether it is
     * the "same" measure under those differences, it takes the safe branch —
     * it does not create a second one — and leaves the existing record alone
     * for a person to look at. That is why this checks "is there an active one
     * at all", not "is there one that also matches on every other field".
     *
     * @param  array<string, mixed>  $decision
     */
    private function createInterventions(
        SchoolClass $class,
        Enrollment $enrollment,
        array $decision,
        Carbon $startedOn,
        ?string $frameworkCode,
        User $confirmedBy,
    ): int {
        $created = 0;

        foreach ($this->recognisedMeasures($decision) as [$code, $level, $rawToken]) {
            if ($this->activeInterventionExists($enrollment, $code)) {
                continue;
            }

            $type = InterventionType::tryFrom($code->value) ?? InterventionType::Other;

            $this->creator->create(
                class: $class,
                attributes: [
                    'enrollment_id' => $enrollment->getKey(),
                    'target_type' => InterventionTargetType::Student,
                    'intervention_type' => $type,
                    'intervention_type_label' => $code->label(),
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => $code->label(),
                    'description' => __('Importado da caracterização — o ficheiro indicava: :token', ['token' => $rawToken]),
                    'description_source' => InterventionDescriptionSource::Manual,
                    'status' => InterventionStatus::New,
                    'started_on' => $startedOn->toDateString(),
                    'available_for_reports' => true,
                    'include_in_report' => true,
                    'support_measure_level' => $level,
                    'support_measure_code' => $code,
                    'legal_mapping_source' => LegalMappingSource::Manual,
                    'legal_framework_code' => $frameworkCode,
                    'origin' => InterventionOrigin::CharacterisationImport,
                ],
                supportMeasures: [['level' => $level->value, 'code' => $code->value]],
                participantIds: [$enrollment->getKey()],
                createdBy: $confirmedBy,
            );

            $created++;
        }

        return $created;
    }

    private function activeInterventionExists(Enrollment $enrollment, SupportMeasureCode $code): bool
    {
        return Intervention::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->where('support_measure_code', $code->value)
            ->whereIn('status', [InterventionStatus::New->value, InterventionStatus::InProgress->value])
            ->exists();
    }

    /**
     * The measure codes a decision named, resolved to a (code, level,
     * raw_token) triple — the same recognition rule `writeMeasures()` and
     * `createInterventions()` both need, kept in one place so the two
     * destinations can never quietly diverge on what counts as "recognised".
     *
     * @param  array<string, mixed>  $decision
     * @return list<array{0: SupportMeasureCode, 1: SupportMeasureLevel, 2: string}>
     */
    private function recognisedMeasures(array $decision): array
    {
        $resolved = [];

        foreach (($decision['measure_codes'] ?? []) as $rawCode) {
            $code = SupportMeasureCode::tryFrom((string) $rawCode);

            if ($code === null) {
                // Only codes the catalogue actually has. An unknown string here
                // is not a measure to record verbatim — it is destination (D),
                // which stores nothing at all.
                continue;
            }

            // THE LEVEL COMES FROM THE APPLICABLE FRAMEWORK, never from the
            // enum and never from the client. `levelFor()` returning null means
            // the regime does not name this measure — a real answer, and a
            // refusal to write, not a gap to fill in locally.
            $level = $this->resolver->levelFor($code);

            if ($level === null) {
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

            $resolved[] = [$code, $level, $rawToken];
        }

        return $resolved;
    }
}
