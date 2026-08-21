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
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionProperty;

class CrossOrganizationCloneTest extends PedagogicalRoundTripTest
{
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
