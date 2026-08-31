<?php

namespace Tests\Feature\DataImports;

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
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\InterimAssessment;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionEffectiveness;
use App\Models\InterventionReview;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\ItemDomainAllocation;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;

/**
 * PORTABILITY OF A BACKUP BETWEEN ACCOUNTS.
 *
 * A record's author is a historical fact ABOUT the record. It was never
 * meant to be a permission slip the record has to produce in order to be
 * restored — and while it was treated as one, four entirely legitimate
 * situations lost their pedagogical data at the door: a teacher who
 * changed email, a class that changed teacher, a school transferring
 * responsibility between colleagues, and a restore into a different but
 * authorised account.
 *
 * The whole point of this file is to pin BOTH halves of the correction at
 * once, because either half alone is a bug:
 *
 *   - the record is imported, whoever wrote it (portability);
 *   - the record is never signed by whoever imported it (no invention).
 *
 * And a third that is not negotiable either: none of this widens who can
 * READ anything. Authorship is not an access-control mechanism here —
 * every pedagogical record is authorised through its class — so a restore
 * that leaves authorship empty must not, and does not, hand the importer
 * anything they could not already reach.
 */
class ImportAuthorshipPortabilityTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * CASE 1 — THE SAME ACCOUNT. Nothing about this changed and nothing
     * about it may change: when the confirming account IS the recorded
     * author, every author field is restored, in full, to that account.
     * This is the control case for everything below — an importer that
     * lost real authorship in the name of portability would be just as
     * wrong as one that invented it.
     */
    #[Test]
    public function the_same_account_keeps_its_own_authorship_on_every_record(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $destination = $this->secondOrganizationOf($teacher);
        $this->confirm($destination, $teacher, $this->uploadInto($destination, $teacher, $backup));

        $this->assertSame($teacher->id, $this->restored(EvidenceRecord::class, $destination)->created_by);
        $this->assertSame($teacher->id, $this->restored(Intervention::class, $destination)->created_by);
        $this->assertSame($teacher->id, $this->restored(InterventionReview::class, $destination)->reviewed_by);
        $this->assertSame($teacher->id, $this->restored(InterimAssessment::class, $destination)->created_by);
        $this->assertSame($teacher->id, $this->restored(Report::class, $destination)->created_by);

        $classification = $this->restored(Classification::class, $destination);
        $this->assertSame(ClassificationStatus::Confirmed, $classification->status);
        $this->assertSame($teacher->id, $classification->confirmed_by);
    }

    /**
     * CASE 2 — THE TEACHER CHANGED EMAIL. The person is the same person;
     * the identifier the backup recorded is not the one they log in with
     * any more. Nothing in this application can prove those two are the
     * same human being, so the authorship is left empty rather than
     * assumed — and the records are restored regardless, which is the
     * entire correction. This used to be a total loss of the pedagogical
     * tier for the account's own data.
     */
    #[Test]
    public function a_teacher_who_changed_email_still_restores_every_record(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $teacher->forceFill(['email' => 'novo-endereco@example.test'])->save();
        $destination = $this->secondOrganizationOf($teacher);
        $import = $this->uploadInto($destination, $teacher, $backup);

        $this->previewOf($destination, $teacher, $import);
        $this->confirm($destination, $teacher, $import);

        $this->assertEveryPedagogicalRecordWasRestored($destination);
        $this->assertNoRecordIsAttributedTo($teacher, $destination);
    }

    /**
     * CASE 3 — ANOTHER AUTHORISED TEACHER. A colleague the exporter shared
     * the backup with, importing into their own organization. Everything
     * is cloned and nothing carries the importer's name.
     */
    #[Test]
    public function another_authorised_teacher_restores_everything_without_taking_the_authorship(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'colega@example.test']);
        $destination = $colleague->personalOrganization();

        $this->confirm($destination, $colleague, $this->uploadInto($destination, $colleague, $backup));

        $this->assertEveryPedagogicalRecordWasRestored($destination);
        $this->assertNoRecordIsAttributedTo($colleague, $destination);
    }

    /**
     * CASE 4 — AN AUTHOR WHO IS NOBODY. The recorded email belongs to no
     * account in this installation at all. No account is created, none is
     * invented, and the record is still restored.
     */
    #[Test]
    public function an_author_email_belonging_to_no_account_never_creates_one(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);
        $destination = $this->secondOrganizationOf($teacher);

        $import = $this->uploadInto($destination, $teacher, $backup);
        $usersBefore = User::query()->count();
        $this->rewriteAuthorEmails($import, 'ninguem-com-esta-conta@example.test');

        $this->confirm($destination, $teacher, $import);

        $this->assertSame($usersBefore, User::query()->count());
        $this->assertEveryPedagogicalRecordWasRestored($destination);
        $this->assertNoRecordIsAttributedTo($teacher, $destination);
    }

    /**
     * CASE 5 — NO AUTHOR AT ALL. The backup simply carries no author for
     * these rows (an older export, or an author that was already empty).
     * Absence is not an error: the record is restored with an empty
     * author, which is exactly what the source said.
     */
    #[Test]
    public function a_backup_with_no_recorded_author_restores_with_an_empty_author(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);
        $destination = $this->secondOrganizationOf($teacher);

        $import = $this->uploadInto($destination, $teacher, $backup);
        $this->rewriteAuthorEmails($import, null);

        $this->confirm($destination, $teacher, $import);

        $this->assertEveryPedagogicalRecordWasRestored($destination);
        $this->assertNoRecordIsAttributedTo($teacher, $destination);
    }

    /**
     * CASE 6 — THE BUG THIS FILE EXISTS FOR.
     *
     * `classifications_confirmed_has_author_check` refuses `confirmed`
     * with an empty `confirmed_by`. A backup whose confirmer cannot be
     * resolved therefore used to reach the writer as an impossible row and
     * take the WHOLE transaction down with it — every other record in the
     * file lost to one unmappable email. Worse, it was invisible here:
     * that CHECK is only added on MySQL, and this suite is SQLite.
     *
     * The safe and consistent behaviour: the values the teacher recorded
     * are restored exactly as they were, and only the CONFIRMATION goes
     * back to the teacher (§3.3 — the system proposes, the teacher
     * confirms). The row is asserted against the constraint's own
     * predicate so the fix is pinned by what the database actually
     * demands, not by what this driver happens to tolerate.
     */
    #[Test]
    public function a_confirmed_classification_with_an_unresolvable_confirmer_is_restored_unconfirmed(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'outro-professor@example.test']);
        $destination = $colleague->personalOrganization();
        $import = $this->uploadInto($destination, $colleague, $backup);

        $this->previewOf($destination, $colleague, $import)->assertInertia(fn ($page) => $page
            ->where('plan.counts.classifications.new', 1)
            ->where('plan.counts.classifications.invalid', 0));

        $this->confirm($destination, $colleague, $import);

        $classification = $this->restored(Classification::class, $destination);
        $this->assertSame(ClassificationStatus::Proposed, $classification->status);
        $this->assertNull($classification->confirmed_by);
        $this->assertNull($classification->confirmed_at);

        // The decision itself is not lost — only its confirmation is.
        $this->assertSame('4.000', (string) $classification->final_value);
        $this->assertSame('4.000', (string) $classification->proposed_value);

        // The CHECK the old behaviour violated, asserted as the database
        // states it: status <> 'confirmed' OR confirmed_by IS NOT NULL.
        $this->assertTrue(
            $classification->status !== ClassificationStatus::Confirmed || $classification->confirmed_by !== null,
            'An imported classification must never be confirmed with an empty confirmed_by.',
        );

        $this->assertSame(1, $import->fresh()->summary['classifications_left_unconfirmed']);
    }

    /**
     * The demotion must not turn a re-run into a conflict. `ExecuteDataImport`
     * rebuilds the plan and compares the backup against what is already
     * there; comparing the SOURCE status against the row this same importer
     * deliberately wrote as `proposed` would report a conflict on every
     * re-import and break idempotency (§11, §44).
     */
    #[Test]
    public function re_importing_a_demoted_classification_is_still_idempotent(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'reimporta@example.test']);
        $destination = $colleague->personalOrganization();
        $this->confirm($destination, $colleague, $this->uploadInto($destination, $colleague, $backup));

        $second = $this->uploadInto($destination, $colleague, $backup);
        $this->previewOf($destination, $colleague, $second)->assertInertia(fn ($page) => $page
            ->where('plan.counts.classifications.new', 0)
            ->where('plan.counts.classifications.conflict', 0)
            ->where('plan.counts.classifications.existing', 1));

        $this->assertSame(1, $this->tenantCount(Classification::class, $destination));
    }

    /**
     * THE SECOND CROSS-ACCOUNT IMPORT, ON THE PEDAGOGICAL TIER.
     *
     * Idempotency was already pinned for a clone whose authorship RESOLVES
     * (`CrossOrganizationCloneTest::a_complete_backup_clones_to_another_
     * organization_and_is_idempotent`, all eighteen domains) and, above,
     * for the one classification this correction rewrites. What was left
     * standing on inference alone is the case this file is actually about:
     * the five domains whose author column is now empty, re-imported by the
     * same foreign account.
     *
     * It is not obvious that it holds, which is why inference was not good
     * enough. The source organization is still standing, so its ULIDs are
     * taken and the first import cloned every row under a FRESH ULID —
     * meaning the second import cannot recognise anything by identity at
     * all. It has to land on the business key of each domain (class +
     * enrollment + period + domain + kind + date + description for a
     * record; class + document_hash for a report; and so on), and an empty
     * author must not disturb any of those. If it did, every re-import
     * would either duplicate the whole pedagogical tier or report it as a
     * conflict.
     */
    #[Test]
    public function a_second_cross_account_import_duplicates_no_pedagogical_record(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'segunda-passagem@example.test']);
        $destination = $colleague->personalOrganization();

        $this->confirm($destination, $colleague, $this->uploadInto($destination, $colleague, $backup));

        $countsAfterFirst = $this->pedagogicalCounts($destination);
        $this->assertEveryPedagogicalRecordWasRestored($destination);
        $this->assertNoRecordIsAttributedTo($colleague, $destination);

        $second = $this->uploadInto($destination, $colleague, $backup);
        $page = $this->previewOf($destination, $colleague, $second)->viewData('page');

        foreach (['interim_assessments', 'evidence_records', 'interventions', 'intervention_reviews', 'reports', 'classifications'] as $domain) {
            $this->assertSame(0, data_get($page, "props.plan.counts.{$domain}.new", -1), "{$domain}.new");
            $this->assertSame(0, data_get($page, "props.plan.counts.{$domain}.conflict", -1), "{$domain}.conflict");
            $this->assertSame(1, data_get($page, "props.plan.counts.{$domain}.existing", -1), "{$domain}.existing");
            $this->assertSame(0, data_get($page, "props.plan.counts.{$domain}.invalid", -1), "{$domain}.invalid");
        }

        $this->confirm($destination, $colleague, $second);

        // Nothing was written the second time, and nothing was rewritten.
        $this->assertSame($countsAfterFirst, $this->pedagogicalCounts($destination));
        $this->assertNoRecordIsAttributedTo($colleague, $destination);

        $classification = $this->restored(Classification::class, $destination);
        $this->assertSame(ClassificationStatus::Proposed, $classification->status);
        $this->assertNull($classification->confirmed_by);
        $this->assertNull($classification->confirmed_at);
        $this->assertSame('4.000', (string) $classification->final_value);

        $summary = $second->fresh()->summary;
        $this->assertSame(0, $summary['evidence_records_created']);
        $this->assertSame(0, $summary['interventions_created']);
        $this->assertSame(0, $summary['reports_created']);
        $this->assertSame(0, $summary['interim_assessments_created']);
        $this->assertSame(0, $summary['classifications_created']);
        $this->assertSame(0, $summary['records_without_original_author']);
        $this->assertSame(0, $summary['classifications_left_unconfirmed']);
    }

    /**
     * PARTIAL IMPORT. One row that genuinely cannot be restored — here an
     * evidence record pointing at a class that is not in the backup — stays
     * `invalid` and takes nothing else with it. Authorship, meanwhile,
     * invalidates nothing at all any more: the remaining records land.
     */
    #[Test]
    public function a_genuinely_broken_row_never_costs_the_rest_of_the_import(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'parcial@example.test']);
        $destination = $colleague->personalOrganization();
        $import = $this->uploadInto($destination, $colleague, $backup);

        $snapshot = $import->canonical_snapshot;
        $snapshot['evidence_records'][0]['class_ulid'] = (string) Str::ulid();
        $import->forceFill(['canonical_snapshot' => $snapshot])->save();

        $this->previewOf($destination, $colleague, $import)->assertInertia(fn ($page) => $page
            ->where('plan.counts.evidence_records.invalid', 1)
            ->where('plan.counts.evidence_records.new', 0)
            ->where('plan.counts.interventions.new', 1)
            ->where('plan.counts.reports.new', 1));

        $this->confirm($destination, $colleague, $import);

        $this->assertSame(0, $this->tenantCount(EvidenceRecord::class, $destination));
        $this->assertSame(1, $this->tenantCount(Intervention::class, $destination));
        $this->assertSame(1, $this->tenantCount(Report::class, $destination));
        $this->assertSame('imported', $import->fresh()->status->value);
    }

    /**
     * The preview says so, and says it as information rather than as a
     * refusal (§11 of the correction brief): the import can still be
     * confirmed, and the message is one a teacher can act on.
     */
    #[Test]
    public function the_preview_reports_unresolved_authorship_without_blocking_the_import(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        $colleague = User::factory()->create(['email' => 'preview@example.test']);
        $destination = $colleague->personalOrganization();

        $page = $this->previewOf($destination, $colleague, $this->uploadInto($destination, $colleague, $backup))
            ->viewData('page');

        $this->assertTrue(data_get($page, 'props.plan.can_confirm'));

        $rows = data_get($page, 'props.plan.rows.interventions', []);
        $notices = collect($rows)->pluck('notice')->filter()->values();

        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('não pôde ser associada à conta atual', (string) $notices->first());
        $this->assertStringNotContainsString('só pode ser confirmada com a conta que a criou', (string) $notices->first());

        foreach ($rows as $row) {
            $this->assertSame('new', $row['classification']);
            $this->assertNull($row['reason']);
        }
    }

    /**
     * TENANCY AND AUTHORISATION ARE UNTOUCHED. An institutional owner
     * restoring a colleague's backup gets the records into the school —
     * which is whose data it is — and gains nothing they could not already
     * see: pedagogical records are authorised through their class, and the
     * owner does not teach this one. Nothing lands outside the destination
     * organization either.
     */
    #[Test]
    public function restoring_a_colleagues_records_grants_the_importer_no_new_access(): void
    {
        [$teacher, $source] = $this->scenario();
        $backup = $this->backupUpload($source, $teacher);

        [$organization, $owner] = $this->institutionalOrganization();
        $this->confirm($organization, $owner, $this->uploadInto($organization, $owner, $backup));

        $restoredClass = SchoolClass::withoutGlobalScope('organization')->where('organization_id', $organization->id)->firstOrFail();
        $this->assertFalse($restoredClass->teachers()->exists());
        $this->assertFalse(Gate::forUser($owner)->allows('view', $restoredClass));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $restoredClass));

        $this->assertEveryPedagogicalRecordWasRestored($organization);
        $this->assertNoRecordIsAttributedTo($owner, $organization);

        // A report is the one pedagogical document with an authorship-aware
        // policy of its own. An empty author must fail closed, never open.
        $report = $this->restored(Report::class, $organization);
        $this->assertNull($report->created_by);
        $this->assertFalse(Gate::forUser($owner)->allows('view', $report));

        // And the source organization is untouched by any of it.
        foreach ([EvidenceRecord::class, Intervention::class, Report::class] as $model) {
            $this->assertSame(1, $this->tenantCount($model, $source));
            $this->assertSame(1, $this->tenantCount($model, $organization));
        }
    }

    // ------------------------------------------------------------------ //
    // Assertions shared by the matrix above.
    // ------------------------------------------------------------------ //

    private function assertEveryPedagogicalRecordWasRestored(Organization $destination): void
    {
        foreach ([InterimAssessment::class, EvidenceRecord::class, Intervention::class, InterventionReview::class, Report::class] as $model) {
            $this->assertSame(1, $this->tenantCount($model, $destination), $model);
        }
    }

    /**
     * The half that must never be traded away for portability: no record
     * anywhere in the destination claims this account as its author.
     */
    private function assertNoRecordIsAttributedTo(User $user, Organization $destination): void
    {
        $authors = [
            InterimAssessment::class => 'created_by',
            EvidenceRecord::class => 'created_by',
            Intervention::class => 'created_by',
            InterventionReview::class => 'reviewed_by',
            Report::class => 'created_by',
        ];

        foreach ($authors as $model => $column) {
            foreach ($model::withoutGlobalScopes()->where('organization_id', $destination->id)->get() as $row) {
                $this->assertNull($row->{$column}, "{$model}.{$column}");
                $this->assertNotSame($user->id, $row->{$column}, "{$model}.{$column}");
            }
        }

        $report = Report::withoutGlobalScopes()->where('organization_id', $destination->id)->first();
        $this->assertNull($report?->finalized_by);

        $classification = Classification::withoutGlobalScopes()->where('organization_id', $destination->id)->first();
        $this->assertNull($classification?->confirmed_by);
        $this->assertNull($classification?->overridden_by);

        $score = StudentItemScore::withoutGlobalScopes()->where('organization_id', $destination->id)->first();
        $this->assertNull($score?->assessed_by);
    }

    // ------------------------------------------------------------------ //
    // Fixture.
    // ------------------------------------------------------------------ //

    /** @return array{User, Organization} */
    private function scenario(): array
    {
        $teacher = User::factory()->create(['email' => 'autora@example.test']);
        $organization = $teacher->personalOrganization();

        $this->inTenant($organization, function () use ($organization, $teacher): void {
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $period = AcademicPeriod::factory()->recycle($organization)->for($year)->create([
                'label' => '1.º Período', 'sequence' => 1, 'starts_on' => '2025-09-01', 'ends_on' => '2025-12-31',
            ]);
            $subject = Subject::factory()->recycle($organization)->create(['name' => 'Matemática']);
            $scale = Scale::where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
            $profile = AssessmentProfile::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => 'Perfil de Matemática',
            ]);
            $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                'assessment_profile_id' => $profile->id, 'scale_id' => $scale->id, 'domain_weight_mode' => 'must_total_100',
            ]);
            $domains = collect([
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Conhecimento', 'code' => 'CON', 'sequence' => 1]),
                Domain::factory()->recycle($organization)->create(['subject_id' => $subject->id, 'name' => 'Raciocínio', 'code' => 'RAC', 'sequence' => 2]),
            ]);
            foreach ($domains as $index => $domain) {
                $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 50, 'sequence' => $index + 1]);
            }
            $version->periods()->create(['academic_period_id' => $period->id, 'contributes_to_accumulated' => true]);
            $version = app(ActivateProfileVersion::class)->activate($version->refresh(), $teacher);

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => '7.º A',
                'assessment_profile_version_id' => $version->id,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            $student = Student::factory()->recycle($organization)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $organization->id, 'display_name' => 'Ana Segura']);
            $enrollment = Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'class_number' => 1, 'enrolled_on' => '2025-09-01',
            ]);

            $instrument = Instrument::factory()->recycle($organization)->create([
                'class_id' => $class->id, 'academic_period_id' => $period->id, 'title' => 'Ficha de avaliação',
                'applied_on' => '2025-10-15', 'status' => 'completed', 'counts_toward_classification' => true, 'total_points' => 20,
            ]);
            $item = InstrumentItem::factory()->recycle($organization)->create([
                'instrument_id' => $instrument->id, 'code' => 'Q1', 'sequence' => 1, 'points_possible' => 20,
            ]);
            ItemDomainAllocation::create(['instrument_item_id' => $item->id, 'domain_id' => $domains[0]->id, 'allocation_percent' => 100]);
            StudentItemScore::create([
                'instrument_id' => $instrument->id, 'instrument_item_id' => $item->id, 'enrollment_id' => $enrollment->id,
                'result_state' => ResultState::Assessed, 'points_earned' => 14,
                'assessed_at' => '2025-10-20 12:00:00', 'assessed_by' => $teacher->id,
            ]);

            $level = $scale->levels->firstWhere('code', '4') ?? $scale->levels->sortByDesc('sequence')->firstOrFail();
            Classification::create([
                'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id, 'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $version->id, 'status' => ClassificationStatus::Confirmed,
                'proposed_normalized_value' => 70, 'proposed_value' => 4, 'proposed_scale_level_id' => $level->id,
                'final_value' => 4, 'final_scale_level_id' => $level->id, 'confirmed_by' => $teacher->id,
                'confirmed_at' => '2025-12-15 12:00:00',
            ]);

            EvidenceRecord::create([
                'class_id' => $class->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id,
                'domain_id' => $domains[0]->id, 'occurred_at' => '2025-10-21 09:00:00', 'kind' => EvidenceKind::Note,
                'description' => 'Resolve problemas com autonomia.', 'created_by' => $teacher->id,
            ]);

            $intervention = Intervention::create([
                'class_id' => $class->id, 'enrollment_id' => $enrollment->id, 'academic_period_id' => $period->id,
                'domain_id' => $domains[0]->id, 'target_type' => InterventionTargetType::Student,
                'domain_relation' => InterventionDomainRelation::Specific, 'title' => 'Treino orientado',
                'description' => 'Resolver dois problemas por semana.', 'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::InProgress, 'started_on' => '2025-10-22',
                'include_in_report' => true, 'available_for_reports' => true, 'created_by' => $teacher->id,
            ]);
            $intervention->participants()->attach($enrollment->id);
            InterventionReview::create([
                'intervention_id' => $intervention->id, 'reviewed_on' => '2025-11-30',
                'effectiveness' => InterventionEffectiveness::Effective, 'notes' => 'Maior autonomia.',
                'reviewed_by' => $teacher->id,
            ]);

            app(CaptureInterimAssessment::class)->capture(
                $class->fresh(), $period, Carbon::parse('2025-11-15'), $teacher,
                ['name' => 'Avaliação intercalar de novembro'],
            );

            Report::factory()->recycle($organization)->finalized()->create([
                'class_id' => $class->id, 'academic_year_id' => $year->id, 'academic_period_id' => $period->id,
                'title' => 'Relatório final do 1.º período', 'scope_label' => $period->label,
                'finalized_by' => $teacher->id, 'created_by' => $teacher->id,
            ]);
        });

        return [$teacher, $organization->fresh()];
    }

    /**
     * A second organization the same teacher owns — the shape of a restore
     * that is a CLONE rather than a delete-then-restore, so the source is
     * left standing and every assertion is about the destination.
     */
    private function secondOrganizationOf(User $teacher): Organization
    {
        return Organization::factory()->withMember($teacher)->create([
            'owner_id' => $teacher->id, 'name' => 'Segunda organização',
        ]);
    }

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    /**
     * Rewrites every author email the backup carries, so a case can be
     * about ONE variable — who the author is — without rebuilding the
     * scenario. The snapshot is what both the preview and the write read,
     * the same seam `an_unknown_scale_reference_...` already uses.
     */
    private function rewriteAuthorEmails(DataImport $import, ?string $email): void
    {
        $snapshot = $import->canonical_snapshot;

        foreach ($snapshot as $domain => $rows) {
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                foreach (array_keys($row) as $key) {
                    if (str_ends_with((string) $key, '_by_email')) {
                        $snapshot[$domain][$index][$key] = $email;
                    }
                }
            }
        }

        $import->forceFill(['canonical_snapshot' => $snapshot])->save();
    }

    private function backupUpload(Organization $organization, User $user): UploadedFile
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $user->id)->latest('id')->firstOrFail();

        $this->assertSame('ready', $export->status, (string) $export->failed_reason);

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }

    private function uploadInto(Organization $organization, User $user, UploadedFile $file): DataImport
    {
        // The restore wizard is Pro and Institucional since the Base/Pro
        // realignment (Matriz §7). This file is about what the wizard DOES;
        // the gate itself is asserted in DataImportEntitlementTest.
        if (! app(Entitlements::class)->allowsFor($organization, 'data_backup_restore')) {
            $this->subscribeOrganizationTo($organization, 'pro');
        }

        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post('/data-imports', ['file' => $file])->assertSessionHasNoErrors();

        return DataImport::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)->latest('id')->firstOrFail();
    }

    private function previewOf(Organization $organization, User $user, DataImport $import): TestResponse
    {
        return $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->get("/data-imports/{$import->ulid}");
    }

    private function confirm(Organization $organization, User $user, DataImport $import): void
    {
        $this->actingAs($user)->withSession(['organization_id' => $organization->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect()->assertSessionHasNoErrors();
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function restored(string $model, Organization $organization): Model
    {
        return $model::withoutGlobalScopes()->where('organization_id', $organization->id)->firstOrFail();
    }

    /**
     * The whole pedagogical tier plus the decision row, counted per model.
     * Compared before and after a re-import: the only honest way to say
     * "nothing was duplicated" is to count everything, not the one domain
     * the assertion happens to be about.
     *
     * @return array<string, int>
     */
    private function pedagogicalCounts(Organization $organization): array
    {
        return [
            'classifications' => $this->tenantCount(Classification::class, $organization),
            'interim_assessments' => $this->tenantCount(InterimAssessment::class, $organization),
            'evidence_records' => $this->tenantCount(EvidenceRecord::class, $organization),
            'interventions' => $this->tenantCount(Intervention::class, $organization),
            'intervention_reviews' => $this->tenantCount(InterventionReview::class, $organization),
            'reports' => $this->tenantCount(Report::class, $organization),
            'student_item_scores' => $this->tenantCount(StudentItemScore::class, $organization),
        ];
    }

    /** @param  class-string<Model>  $model */
    private function tenantCount(string $model, Organization $organization): int
    {
        return $model::withoutGlobalScopes()->where('organization_id', $organization->id)->count();
    }

    private function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }
}
