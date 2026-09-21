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
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\Import\MergeCharacterisationSections;
use App\Services\Characterisation\RecordCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use App\Support\Interventions\InterventionAuditProperties;
use App\Support\Interventions\InterventionLegalFramework;
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
 * It resolves the applicable `InterventionLegalFramework` once per import —
 * from `$class->organization` at the moment of confirmation, the same source
 * `LegalFrameworkResolver` gives the manual form — and asks it, and only it,
 * what level a measure sits at. That is a lookup, not a parse — it turns no
 * text into decisions, and it is here precisely so that the level written to
 * a child's record is the law's answer rather than the client's. It used to
 * ask a `LegalCodeResolver` instead, which re-resolved the SAME question
 * through `CurrentOrganization` + `now()` — a second source of truth that
 * happened to agree, until `CurrentOrganization` was unresolved, at which
 * point it silently answered "no measures" instead. Reading the framework
 * this class already resolved removes that second source entirely.
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
        private readonly MergeCharacterisationSections $merger,
        private readonly CreateIntervention $creator,
        private readonly LegalFrameworkResolver $frameworks,
        private readonly AuditLog $audit,
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

                $measures = $this->writeMeasures($characterisation, $decision, $batch, $confirmedBy, $framework);

                $interventionsCreated = $this->createInterventions(
                    $class,
                    $enrollment,
                    $decision,
                    $startedOn,
                    $frameworkCode,
                    $confirmedBy,
                    $batch,
                    $framework,
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
        InterventionLegalFramework $framework,
    ): int {
        $written = 0;

        foreach ($this->recognisedMeasures($decision, $framework) as [$code, $level, $rawToken]) {
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
     * AUDIT (§44, F11): `intervention.created` used to be recorded ONLY from
     * InterventionController::store(), which is the manual form's own path —
     * an intervention created here, from an import, left no trace in the
     * audit log at all. InterventionController records it AFTER its
     * transaction commits, because that controller only has one intervention
     * (or a handful from one form submission) to record once the write is
     * certain. This method is different: it runs once PER DECISION, inside
     * the SAME transaction ApplyCharacterisationImport::apply() wraps the
     * whole import in, and a later decision in that same loop can still fail
     * and roll the entire batch back. Recording the event here — inside that
     * transaction, through an ordinary Eloquent write, never a queued job —
     * means the audit row rolls back right along with the intervention it
     * describes, so the trail can never claim an intervention exists that
     * the rollback just undid. IDs and counts only, exactly like every other
     * audit event this feature already writes — never the pedagogical text
     * in `description`.
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
        CharacterisationImportBatch $batch,
        InterventionLegalFramework $framework,
    ): int {
        $created = 0;

        foreach ($this->recognisedMeasures($decision, $framework) as [$code, $level, $rawToken]) {
            if ($this->activeInterventionExists($enrollment, $code)) {
                continue;
            }

            $type = InterventionType::tryFrom($code->value) ?? InterventionType::Other;

            $intervention = $this->creator->create(
                class: $class,
                attributes: [
                    'enrollment_id' => $enrollment->getKey(),
                    'target_type' => InterventionTargetType::Student,
                    'intervention_type' => $type,
                    'intervention_type_label' => $code->label(),
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => $code->label(),
                    'description' => __('Importado da caracterização — o ficheiro indicava: :token', ['token' => $rawToken]),
                    // This text is COMPOSED BY THIS CLASS, quoting the school's
                    // paperwork — it is not the teacher's own words, so it must
                    // never read as Manual (§4 of the intervention-creation
                    // audit: that value is documented as "Escrita pelo
                    // professor").
                    'description_source' => InterventionDescriptionSource::Import,
                    'status' => InterventionStatus::New,
                    'started_on' => $startedOn->toDateString(),
                    'available_for_reports' => true,
                    'include_in_report' => true,
                    'support_measure_level' => $level,
                    'support_measure_code' => $code,
                    // The app derived this framing from the framework and the
                    // teacher confirmed it row-by-row in the preview — exactly
                    // what SystemSuggestedConfirmed documents. Manual would
                    // claim the teacher picked the measure themselves, which
                    // is not what happened here.
                    'legal_mapping_source' => LegalMappingSource::SystemSuggestedConfirmed,
                    'legal_framework_code' => $frameworkCode,
                    'origin' => InterventionOrigin::CharacterisationImport,
                ],
                supportMeasures: [['level' => $level->value, 'code' => $code->value]],
                participantIds: [$enrollment->getKey()],
                createdBy: $confirmedBy,
            );

            $this->audit->record(
                'intervention.created',
                $intervention,
                $confirmedBy,
                __('Intervenção criada a partir da importação da caracterização.'),
                [
                    ...InterventionAuditProperties::base($intervention),
                    'enrollment_id' => $enrollment->getKey(),
                    'import_batch_ulid' => $batch->ulid,
                    'support_measure_level' => $level->value,
                    'support_measure_code' => $code->value,
                ],
            );

            $created++;
        }

        return $created;
    }

    /**
     * §30's dedup, both places a measure can be held.
     *
     * A hand-created intervention that carries several measures stores only
     * the FIRST pair on the parent's own `support_measure_code` column — the
     * rest live exclusively in the `intervention_support_measures` pivot (see
     * `CreateIntervention::create()`). Checking the parent column alone missed
     * every measure but the first on such a row, so importing a sheet naming
     * the SECOND measure of an existing multi-measure intervention created a
     * duplicate. Both places are checked here; the coarseness on period and
     * context documented on `createInterventions()` above is deliberately kept
     * — this still only asks "is there an active one at all", never "one that
     * also matches on every other field".
     */
    private function activeInterventionExists(Enrollment $enrollment, SupportMeasureCode $code): bool
    {
        $activeStatuses = [InterventionStatus::New->value, InterventionStatus::InProgress->value];

        return Intervention::query()
            ->where('enrollment_id', $enrollment->getKey())
            ->whereIn('status', $activeStatuses)
            ->where(function ($query) use ($code): void {
                $query->where('support_measure_code', $code->value)
                    ->orWhereHas('supportMeasures', function ($measures) use ($code): void {
                        $measures->where('support_measure_code', $code->value);
                    });
            })
            ->exists();
    }

    /**
     * The measure codes a decision named, resolved to a (code, level,
     * raw_token) triple — the same recognition rule `writeMeasures()` and
     * `createInterventions()` both need, kept in one place so the two
     * destinations can never quietly diverge on what counts as "recognised".
     *
     * THE LEVEL COMES FROM THE FRAMEWORK THIS BATCH ALREADY RESOLVED
     * (`apply()`, from `$class->organization` at `$startedOn`), passed in as
     * `$framework` — never re-resolved through `$this->resolver`, which reads
     * `CurrentOrganization` + `now()` instead. Those agreed as long as an
     * import always ran inside a request for the organization it names, at
     * the moment it was confirmed — but they are two sources of truth for the
     * same question, and a resolver reading `CurrentOrganization` fails
     * silently (returns null, treated exactly like "not on this framework")
     * the moment that binding is unresolved, with the row counted `skipped`
     * and indistinguishable from an empty one. Reading `$framework` directly
     * removes the second source entirely.
     *
     * @param  array<string, mixed>  $decision
     * @return list<array{0: SupportMeasureCode, 1: SupportMeasureLevel, 2: string}>
     */
    private function recognisedMeasures(array $decision, InterventionLegalFramework $framework): array
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

            // `levelFor()` returning null means the regime does not name this
            // measure — a real answer, and a refusal to write, not a gap to
            // fill in locally.
            $level = $framework->levelFor($code);

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
