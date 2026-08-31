<?php

namespace Tests\Feature\DataImports;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\DataImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentGroup;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionReview;
use App\Models\Organization;
use App\Models\Report;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentTemplate;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

class CrossOrganizationCloneTest extends PedagogicalRoundTripTest
{
    #[Test]
    public function a_complete_backup_clones_into_an_empty_organization_with_its_academic_structure(): void
    {
        $teacher = $this->teacher();
        $source = $teacher->personalOrganization();
        $sourceClass = $this->completeScenario();
        $sourceProgression = $this->progressionFor($source, $sourceClass);
        $sourceYear = $this->inOrganization($source, fn (): AcademicYear => AcademicYear::firstOrFail());
        $sourceSubject = $this->inOrganization($source, fn (): Subject => Subject::firstOrFail());
        $backup = $this->freshBackup();

        $destination = Organization::factory()->withMember($teacher)->create(['owner_id' => $teacher->id, 'name' => 'Destino vazio']);
        $import = $this->upload($backup, $destination, $teacher);
        $preview = $this->actingAs($teacher)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");

        $preview->assertOk();
        foreach ([
            'academic_years' => ['new' => 1, 'invalid' => 0],
            'subjects' => ['new' => 1, 'invalid' => 0],
            'classes' => ['new' => 1, 'invalid' => 0],
            'students' => ['new' => 1],
            'enrollments' => ['new' => 1, 'invalid' => 0],
            'academic_periods' => ['new' => 1, 'invalid' => 0],
            'domains' => ['new' => 1, 'invalid' => 0],
        ] as $domain => $counts) {
            foreach ($counts as $classification => $minimum) {
                $actual = data_get($preview->viewData('page'), "props.plan.counts.{$domain}.{$classification}", -1);
                $minimum === 0
                    ? $this->assertSame(0, $actual, "{$domain}.{$classification}")
                    : $this->assertGreaterThanOrEqual($minimum, $actual, "{$domain}.{$classification}");
            }
        }

        $this->confirm($import, $destination, $teacher);

        $destinationYear = $this->inOrganization($destination, fn (): AcademicYear => AcademicYear::firstOrFail());
        $destinationSubject = $this->inOrganization($destination, fn (): Subject => Subject::firstOrFail());
        $this->assertSame($sourceYear->label, $destinationYear->label);
        $this->assertSame($sourceYear->starts_on->toDateString(), $destinationYear->starts_on->toDateString());
        $this->assertSame($sourceYear->ends_on->toDateString(), $destinationYear->ends_on->toDateString());
        $this->assertSame($sourceYear->status, $destinationYear->status);
        $this->assertSame($sourceSubject->name, $destinationSubject->name);
        $this->assertSame($sourceSubject->code, $destinationSubject->code);

        $destinationClass = $this->inOrganization($destination, fn (): SchoolClass => SchoolClass::firstOrFail());
        $destinationProgression = $this->progressionFor($destination, $destinationClass);
        $this->assertSame($this->semantic($sourceProgression), $this->semantic($destinationProgression));
    }

    #[Test]
    public function a_complete_backup_clones_to_another_organization_and_is_idempotent(): void
    {
        $teacher = $this->teacher();
        $source = $teacher->personalOrganization();
        $sourceClass = $this->completeScenario();
        $sourceProgression = $this->progressionFor($source, $sourceClass);
        $sourceUlids = $this->ulidsByModel($source);
        $sourceCounts = $this->countsByModel($source);
        $backup = $this->freshBackup();

        $destination = Organization::factory()->withMember($teacher)->create(['owner_id' => $teacher->id, 'name' => 'Destino pessoal']);
        $this->seedRequiredReferences($source, $destination);
        $import = $this->upload($backup, $destination, $teacher);

        $cloneDomains = [
            'academic_periods', 'domains', 'assessment_profiles', 'assessment_profile_versions',
            'classes', 'students', 'enrollments', 'instruments', 'instrument_groups', 'instrument_items',
            'classifications', 'self_assessment_templates', 'self_assessments', 'interim_assessments',
            'evidence_records', 'interventions', 'intervention_reviews', 'reports',
        ];

        $preview = $this->actingAs($teacher)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");
        $preview->assertOk();
        foreach ($cloneDomains as $domain) {
            $this->assertGreaterThan(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.new", 0), $domain);
            $this->assertSame(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.invalid", 0), $domain);
        }

        $this->confirm($import, $destination, $teacher);

        $this->assertSame($sourceCounts, $this->countsByModel($source));
        $destinationUlids = $this->ulidsByModel($destination);
        foreach ($sourceUlids as $model => $ulids) {
            $this->assertSame([], array_values(array_intersect($ulids, $destinationUlids[$model])), $model);
        }

        $destinationClass = $this->inOrganization($destination, fn (): SchoolClass => SchoolClass::with('enrollments.student')->firstOrFail());
        foreach ($destinationClass->enrollments as $enrollment) {
            $this->assertSame($destination->id, $enrollment->organization_id);
            $this->assertSame($destination->id, $enrollment->student->organization_id);
            $this->assertSame($destinationClass->id, $enrollment->class_id);
        }

        $destinationProgression = $this->progressionFor($destination, $destinationClass);
        $this->assertSame($this->semantic($sourceProgression), $this->semantic($destinationProgression));

        $secondImport = $this->upload($this->freshBackup(), $destination, $teacher);
        $secondPreview = $this->actingAs($teacher)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$secondImport->ulid}");
        foreach ($cloneDomains as $domain) {
            $this->assertSame(0, data_get($secondPreview->viewData('page'), "props.plan.counts.{$domain}.new", -1), $domain);
        }
        $beforeSecondConfirm = $this->countsByModel($destination);
        $this->confirm($secondImport, $destination, $teacher);
        $this->assertSame($beforeSecondConfirm, $this->countsByModel($destination));
    }

    #[Test]
    public function cloning_never_mutates_the_source_and_direct_destination_ulid_conflicts_survive(): void
    {
        $sourceTeacher = $this->teacher();
        $source = $sourceTeacher->personalOrganization();
        $this->completeScenario();
        $backup = $this->freshBackup();
        $sourceCounts = $this->countsByModel($source);
        $sourceDomain = $this->inOrganization($source, fn (): Domain => Domain::firstOrFail());
        $backedUpUlid = $sourceDomain->ulid;
        $this->inOrganization($source, fn () => $sourceDomain->forceFill(['ulid' => (string) str()->ulid()])->save());

        $attacker = User::factory()->create();
        $destination = $attacker->personalOrganization();
        $this->seedRequiredReferences($source, $destination);
        $localDomain = $this->inOrganization($destination, fn (): Domain => Domain::factory()->create([
            'organization_id' => $destination->id,
            'ulid' => $backedUpUlid,
            'name' => 'Edição local protegida',
            'code' => $sourceDomain->code,
        ]));

        $import = $this->upload($backup, $destination, $attacker);
        $preview = $this->actingAs($attacker)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");
        $this->assertGreaterThan(0, data_get($preview->viewData('page'), 'props.plan.counts.domains.conflict', 0));
        $this->confirm($import, $destination, $attacker);

        $this->assertSame($sourceCounts, $this->countsByModel($source));
        $this->assertSame('Edição local protegida', $this->inOrganization($destination, fn (): string => $localDomain->fresh()->name));
    }

    /**
     * Option 1 of the authorship contract (§30-31): the confirming user IS
     * the same real person who authored the pedagogical records, just
     * restoring into a second, different organization of their own rather
     * than the one they were originally written in. The source is left
     * intact -- this is a clone, not a delete-then-restore -- and every
     * personally-authored row (evidence, interventions, their reviews,
     * reports) still maps to that same account, because its email matches
     * every row's recorded author email directly. No new mechanism: this is
     * `resolveAuthor()` exactly as it already stood, exercised against a
     * genuinely different destination for the first time.
     */
    #[Test]
    public function cloning_into_a_second_organization_of_the_same_exporter_maps_historical_authorship(): void
    {
        $teacher = $this->teacher();
        $this->completeScenario();
        $backup = $this->freshBackup();
        // Source deliberately left intact.

        $destination = Organization::factory()->withMember($teacher)->create(['owner_id' => $teacher->id, 'name' => 'Segunda organização pessoal']);
        $import = $this->upload($backup, $destination, $teacher);

        $preview = $this->actingAs($teacher)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");
        foreach (['evidence_records', 'interventions', 'intervention_reviews', 'reports'] as $domain) {
            $this->assertGreaterThan(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.new", 0), $domain);
            $this->assertSame(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.invalid", 0), $domain);
        }

        $this->confirm($import, $destination, $teacher);

        $evidence = $this->inOrganization($destination, fn (): EvidenceRecord => EvidenceRecord::firstOrFail());
        $intervention = $this->inOrganization($destination, fn (): Intervention => Intervention::firstOrFail());
        $review = $this->inOrganization($destination, fn (): InterventionReview => InterventionReview::firstOrFail());
        $report = $this->inOrganization($destination, fn (): Report => Report::firstOrFail());

        $this->assertSame($teacher->id, $evidence->created_by);
        $this->assertSame($teacher->id, $intervention->created_by);
        $this->assertSame($teacher->id, $review->reviewed_by);
        $this->assertSame($teacher->id, $report->created_by);
    }

    /**
     * Option 2: the confirming user is a genuinely different real person
     * (a colleague the exporter shared the backup with). Since 0.101.4 every
     * personally authored domain -- evidence, interventions, their reviews,
     * reports -- IS cloned, and arrives with an empty author rather than
     * with the importer's name on it. That is the whole correction: a
     * record's author is a historical fact about it, never a permission
     * slip it has to produce in order to exist, and refusing the row
     * punished exactly the legitimate cases (a changed email, a class that
     * changed teacher, a school transferring responsibility).
     *
     * What must still hold, and is what this test now pins: nothing is
     * attributed to the importer, and the source organization is never
     * written.
     */
    #[Test]
    public function cloning_to_a_different_teacher_never_invents_personal_authorship(): void
    {
        $exporter = $this->teacher();
        $source = $exporter->personalOrganization();
        $this->completeScenario();
        $backup = $this->freshBackup();
        $sourceCounts = $this->countsByModel($source);
        // Source deliberately left intact.

        $importer = User::factory()->create(['email' => 'colega@example.test']);
        $destination = $importer->personalOrganization();
        $this->seedRequiredReferences($source, $destination);

        $import = $this->upload($backup, $destination, $importer);
        $preview = $this->actingAs($importer)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");

        foreach (['evidence_records', 'interventions', 'intervention_reviews', 'reports'] as $domain) {
            $this->assertGreaterThan(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.new", 0), $domain);
            $this->assertSame(0, data_get($preview->viewData('page'), "props.plan.counts.{$domain}.invalid", -1), $domain);
        }
        $this->assertGreaterThan(0, data_get($preview->viewData('page'), 'props.plan.counts.classifications.new', 0));

        $this->confirm($import, $destination, $importer);

        foreach ([EvidenceRecord::class, Intervention::class, InterventionReview::class, Report::class] as $model) {
            $this->assertGreaterThan(0, $model::withoutGlobalScopes()->where('organization_id', $destination->id)->count(), $model);
        }

        $evidence = EvidenceRecord::withoutGlobalScopes()->where('organization_id', $destination->id)->firstOrFail();
        $intervention = Intervention::withoutGlobalScopes()->where('organization_id', $destination->id)->firstOrFail();
        $review = InterventionReview::withoutGlobalScopes()->where('organization_id', $destination->id)->firstOrFail();
        $report = Report::withoutGlobalScopes()->where('organization_id', $destination->id)->firstOrFail();

        foreach ([$evidence->created_by, $intervention->created_by, $review->reviewed_by, $report->created_by, $report->finalized_by] as $author) {
            $this->assertNull($author);
        }

        $this->assertSame($sourceCounts, $this->countsByModel($source));
    }

    /**
     * The importer sharing a display name with the exporter must never be
     * enough -- only an exact email match resolves an author. Proves no
     * name-based fallback exists anywhere in the pipeline: the records are
     * restored (they are genuine records), and the impostor's id appears on
     * none of them.
     */
    #[Test]
    public function an_author_is_never_matched_by_name_only_by_exact_email(): void
    {
        $exporter = $this->teacher();
        $source = $exporter->personalOrganization();
        $this->completeScenario();
        $backup = $this->freshBackup();

        $impostor = User::factory()->create(['name' => $exporter->name, 'email' => 'nao-e-'.$exporter->email]);
        $destination = $impostor->personalOrganization();
        $this->seedRequiredReferences($source, $destination);

        $import = $this->upload($backup, $destination, $impostor);
        $preview = $this->actingAs($impostor)->withSession(['organization_id' => $destination->id])->get("/data-imports/{$import->ulid}");

        $this->assertGreaterThan(0, data_get($preview->viewData('page'), 'props.plan.counts.evidence_records.new', 0));
        $this->assertSame(0, data_get($preview->viewData('page'), 'props.plan.counts.evidence_records.invalid', -1));

        $this->confirm($import, $destination, $impostor);

        $evidence = EvidenceRecord::withoutGlobalScopes()->where('organization_id', $destination->id)->get();
        $this->assertGreaterThan(0, $evidence->count());
        $this->assertTrue($evidence->every(fn (EvidenceRecord $record): bool => $record->created_by === null));
    }

    private function teacher(): User
    {
        $property = new ReflectionProperty(PedagogicalRoundTripTest::class, 'teacher');

        return $property->getValue($this);
    }

    private function completeScenario(): SchoolClass
    {
        return (new ReflectionMethod(PedagogicalRoundTripTest::class, 'scenario'))->invoke($this, 'complete', true);
    }

    private function freshBackup(): UploadedFile
    {
        return (new ReflectionMethod(PedagogicalRoundTripTest::class, 'backupUpload'))->invoke($this);
    }

    /** @param array<string, mixed> $progression */
    private function semantic(array $progression): array
    {
        return (new ReflectionMethod(PedagogicalRoundTripTest::class, 'semanticProgression'))->invoke($this, $progression);
    }

    /** @return array<string, mixed> */
    private function progressionFor(Organization $organization, SchoolClass $class): array
    {
        return $this->inOrganization($organization, fn (): array => app(BuildResultsProgression::class)->for($class->fresh()));
    }

    private function seedRequiredReferences(Organization $source, Organization $destination): void
    {
        $years = $this->inOrganization($source, fn () => AcademicYear::all());
        $subjects = $this->inOrganization($source, fn () => Subject::all());
        $this->inOrganization($destination, function () use ($destination, $years, $subjects): void {
            foreach ($years as $year) {
                AcademicYear::factory()->create(['organization_id' => $destination->id, 'label' => $year->label, 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on]);
            }
            foreach ($subjects as $subject) {
                Subject::factory()->create(['organization_id' => $destination->id, 'name' => $subject->name, 'code' => $subject->code]);
            }
        });
    }

    private function upload(UploadedFile $backup, Organization $destination, User $user): DataImport
    {
        // The restore wizard is Pro and Institucional since the Base/Pro
        // realignment (Matriz §7). This test is about what a clone DOES, so
        // the destination is put on a plan that reaches the wizard; the gate
        // itself is asserted in DataImportEntitlementTest.
        if (! app(Entitlements::class)->allowsFor($destination, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($destination, 'pro');
        }

        $this->actingAs($user)->withSession(['organization_id' => $destination->id])->post('/data-imports', ['file' => $backup])->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')->where('organization_id', $destination->id)->latest('id')->firstOrFail();
    }

    private function confirm(DataImport $import, Organization $destination, User $user): void
    {
        $this->actingAs($user)->withSession(['organization_id' => $destination->id])->post("/data-imports/{$import->ulid}/confirm")->assertSessionHasNoErrors();
    }

    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /** @return array<class-string, int> */
    private function countsByModel(Organization $organization): array
    {
        return collect($this->models())->mapWithKeys(fn (string $model): array => [$model => $model::withoutGlobalScopes()->where('organization_id', $organization->id)->count()])->all();
    }

    /** @return array<class-string, list<string>> */
    private function ulidsByModel(Organization $organization): array
    {
        return collect($this->models())->mapWithKeys(fn (string $model): array => [$model => $model::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('ulid')->all()])->all();
    }

    /** @return list<class-string> */
    private function models(): array
    {
        return [AcademicPeriod::class, Scale::class, InstrumentType::class, Domain::class, AssessmentProfile::class, AssessmentProfileVersion::class,
            SchoolClass::class, Student::class, Enrollment::class, Instrument::class, InstrumentGroup::class, InstrumentItem::class,
            Classification::class, SelfAssessmentTemplate::class, SelfAssessment::class, InterimAssessment::class,
            EvidenceRecord::class, Intervention::class, InterventionReview::class, Report::class];
    }
}
