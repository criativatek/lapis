<?php

namespace Tests\Concerns;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\RectificationReason;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * A teacher's class with REAL chains of rectified classifications, and the
 * backup round trip over it — shared by the SQLite test (the suite) and the
 * MySQL one (the only place the CHECKs and the «one live row» index exist).
 *
 * The chains are written with the sequence `RectifyClassification` follows —
 * the old row steps aside pointing at itself, the new one goes in, the pointer
 * is moved — because it is the only sequence MySQL accepts; so the fixtures are
 * valid on the real schema, not just on SQLite.
 *
 * The class has three students: «Ana» with a chain of three decisions
 * (3 → 4 → 5), «Bruno» with a chain of two (2 → 3) and «Carla» with one plain
 * published classification, all in the same period.
 */
trait BuildsRectificationChainBackups
{
    protected User $teacher;

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod, enrollments: array<string, Enrollment>}
     */
    protected function chainWorld(User $teacher): array
    {
        $organization = $teacher->personalOrganization();

        return $this->inTenant($organization, function () use ($teacher, $organization): array {
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período', 'sequence' => 1, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Perfil de Matemática',
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id, 'scale_id' => $scale->id, 'domain_weight_mode' => 'must_total_100',
            ]);
            $domain = Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Conhecimento', 'code' => 'CON', 'sequence' => 1]);
            $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);
            $version->periods()->create(['academic_period_id' => $period->id, 'contributes_to_accumulated' => true]);
            $version = app(ActivateProfileVersion::class)->activate($version->refresh(), $teacher);

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A',
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            $enrollments = [];

            foreach (['Ana', 'Bruno', 'Carla'] as $index => $name) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => $name]);
                $enrollments[$name] = Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->id, 'student_id' => $student->id, 'class_number' => $index + 1, 'enrolled_on' => '2025-09-01',
                ]);
            }

            $this->publishedChain($enrollments['Ana'], $period, $version, $teacher, [
                ['level' => '3'],
                ['level' => '4', 'reason' => RectificationReason::EntryError, 'note' => null, 'at' => '2025-12-18 10:30:00'],
                ['level' => '5', 'reason' => RectificationReason::Other, 'note' => 'Reapreciação em conselho de turma.', 'at' => '2025-12-20 16:45:00'],
            ]);
            $this->publishedChain($enrollments['Bruno'], $period, $version, $teacher, [
                ['level' => '2'],
                ['level' => '3', 'reason' => RectificationReason::CalculationError, 'note' => 'Falhou uma pontuação.', 'at' => '2025-12-19 09:00:00'],
            ]);
            $this->publishedChain($enrollments['Carla'], $period, $version, $teacher, [['level' => '4']]);

            return ['class' => $class->fresh(), 'period' => $period, 'enrollments' => $enrollments];
        });
    }

    /**
     * One cell's decisions, oldest first, written the way the service does.
     *
     * @param  list<array{level: string, reason?: RectificationReason, note?: string|null, at?: string}>  $steps
     * @return list<Classification>
     */
    protected function publishedChain(Enrollment $enrollment, AcademicPeriod $period, AssessmentProfileVersion $version, User $author, array $steps): array
    {
        $rows = [];
        $previous = null;

        foreach ($steps as $step) {
            $level = $version->scale->levels()->where('code', $step['level'])->firstOrFail();
            $proposed = $version->scale->levels()->where('code', '4')->firstOrFail();
            $differs = $level->id !== $proposed->id;
            $at = $step['at'] ?? '2025-12-15 12:00:00';

            if ($previous !== null) {
                // (a) the previous decision steps aside, pointing at itself.
                $previous->forceFill([
                    'status' => ClassificationStatus::Superseded, 'superseded_by_id' => $previous->getKey(),
                    'lock_version' => $previous->lock_version + 1,
                ])->save();
            }

            // (b) the new one goes in, already published.
            $row = (new Classification)->forceFill([
                'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id, 'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $version->id, 'status' => ClassificationStatus::Published,
                'proposed_normalized_value' => 70, 'proposed_value' => 4, 'proposed_scale_level_id' => $proposed->id,
                'final_value' => (int) $step['level'], 'final_scale_level_id' => $level->id,
                'override_reason' => $differs ? ($step['note'] ?? 'Decisão do professor.') : null,
                'overridden_by' => $differs ? $author->id : null, 'overridden_at' => $differs ? $at : null,
                'confirmed_by' => $author->id, 'confirmed_at' => $at, 'published_at' => $at,
                'rectifies_id' => $previous?->getKey(),
                'rectification_reason' => $step['reason'] ?? null,
                'rectification_note' => $step['note'] ?? null,
                'rectified_by' => $previous === null ? null : $author->id,
                'rectified_at' => $previous === null ? null : $at,
            ]);
            $row->save();

            // (c) the pointer takes its real value.
            if ($previous !== null) {
                $previous->forceFill(['superseded_by_id' => $row->getKey()])->save();
            }

            $rows[] = $row;
            $previous = $row;
        }

        return $rows;
    }

    // ------------------------------------------------------- the round trip

    protected function exportBackup(User $teacher, Organization $organization): UploadedFile
    {
        $this->actingAs($teacher)->withSession(['organization_id' => $organization->id])->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    /** @return array<string, mixed> */
    protected function backupJson(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $zip->close();

        return $json;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutate
     */
    protected function rewriteBackup(UploadedFile $file, callable $mutate): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'lapis-chain-backup-').'.zip';
        copy($file->getPathname(), $copy);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));
        $zip->addFromString('backup-lapis.json', (string) json_encode($mutate(json_decode((string) $zip->getFromName('backup-lapis.json'), true))));
        $zip->close();

        return new UploadedFile($copy, 'backup.zip', 'application/zip', null, true);
    }

    protected function uploadInto(UploadedFile $file, User $actor, Organization $organization): DataImport
    {
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])
            ->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->where('requested_by', $actor->id)
            ->latest('id')->firstOrFail();
    }

    protected function confirmImport(DataImport $import, User $actor, Organization $organization): void
    {
        $this->actingAs($actor)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * The decisions of an organization as a person reads them, one chain per
     * student: oldest first, with what each row says about itself and about its
     * neighbours — never an id, so two organizations can be compared.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function chainsOf(Organization $organization): array
    {
        return $this->inTenant($organization, function (): array {
            $rows = Classification::query()->with(['enrollment.student.identity', 'finalScaleLevel'])->orderBy('id')->get();
            $byId = $rows->keyBy('id');
            $chains = [];

            foreach ($rows as $row) {
                $name = (string) $row->enrollment->student->identity->display_name;
                $emailOf = fn (?int $id): ?string => $id === null ? null : User::query()->find($id)?->email;

                $chains[$name][] = [
                    'status' => $row->status->value,
                    'level' => $row->finalScaleLevel?->code,
                    'final_value' => (string) (float) $row->final_value,
                    'rectifies' => $row->rectifies_id === null ? null : (string) (float) $byId[$row->rectifies_id]->final_value,
                    'superseded_by' => $row->superseded_by_id === null ? null : (string) (float) $byId[$row->superseded_by_id]->final_value,
                    'reason' => $row->rectification_reason?->value,
                    'note' => $row->rectification_note,
                    'rectified_at' => $row->rectified_at?->format('Y-m-d H:i:s'),
                    'rectified_by' => $emailOf($row->rectified_by),
                    'confirmed_by' => $emailOf($row->confirmed_by),
                    'published_at' => $row->published_at?->format('Y-m-d H:i:s'),
                    'override_reason' => $row->override_reason,
                    'lock_version' => $row->lock_version,
                ];
            }

            ksort($chains);

            return $chains;
        });
    }

    protected function selfPointers(Organization $organization): int
    {
        return $this->inTenant($organization, fn (): int => Classification::query()->whereColumn('superseded_by_id', 'id')->count());
    }
}
