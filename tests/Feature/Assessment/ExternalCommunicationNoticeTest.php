<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ExternalCommunicationNotice;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The notice shown before rectifying a published classification (spec §7).
 *
 * The generic sentence is unconditional. The export line only appears for a
 * photograph of the same class, period and scope, taken at or after the
 * publication, that actually holds this student.
 */
class ExternalCommunicationNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $row = Classification::query()
                ->where('academic_period_id', $this->period->id)
                ->where('scope', ClassificationScope::Period)
                ->get()
                ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');
            $level = $this->class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();

            app(ConfirmClassification::class)->confirm($row, $this->ana, $level->id);
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $row->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback, ?Organization $organization = null): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization ?? Organization::query()->findOrFail($this->organization->id), $callback);
    }

    /** Keeps a photograph holding Carolina (or not), `$minutes` after the publication unless told otherwise. */
    private function export(array $overrides = [], int $minutes = 60, bool $holdsCarolina = true): EvaluationSheetExport
    {
        $payload = ['version' => 3, 'students' => $holdsCarolina
            ? [['enrollment_id' => $this->carolina->enrollment_id], ['enrollment_id' => $this->carolina->enrollment_id + 1000]]
            : [['enrollment_id' => $this->carolina->enrollment_id + 1000]]];

        return $this->asTenant(fn () => EvaluationSheetExport::create($overrides + [
            'class_id' => $this->class->id,
            'academic_period_id' => $this->period->id,
            'scope' => ClassificationScope::Period,
            'adapter' => 'inovar',
            'moment_label' => 'Semestre — 1.º Semestre',
            'effective_at' => '2026-12-15',
            'payload' => $payload,
            'payload_hash' => CanonicalPayload::hash($payload),
            'exported_with_warnings' => false,
            'warning_count' => 0,
            'file_disk' => 'local',
            'exported_by' => $this->ana->id,
            'exported_at' => $this->carolina->published_at->copy()->addMinutes($minutes),
        ]));
    }

    private function notice(?Classification $classification = null): array
    {
        return $this->asTenant(fn () => app(ExternalCommunicationNotice::class)->for($classification ?? $this->carolina));
    }

    #[Test]
    public function without_an_export_only_the_generic_sentence(): void
    {
        $notice = $this->notice();

        $this->assertSame(
            'Esta classificação já foi publicada. Se a comunicou fora do Lapispro (Inovar, pauta impressa, PDF), terá de atualizar esses registos.',
            $notice['generic'],
        );
        $this->assertNull($notice['export']);
    }

    #[Test]
    public function an_export_after_the_publication_is_reported(): void
    {
        $export = $this->export();

        $this->assertSame([
            'at' => $export->exported_at->toIso8601String(),
            'adapter' => 'inovar',
            'moment_label' => 'Semestre — 1.º Semestre',
        ], $this->notice()['export']);
    }

    #[Test]
    public function an_export_before_the_publication_does_not_count(): void
    {
        $this->export(minutes: -1);

        $this->assertNull($this->notice()['export']);
    }

    #[Test]
    public function an_export_of_another_class_does_not_count(): void
    {
        $other = $this->asTenant(fn () => tap($this->class->replicate(), fn (SchoolClass $copy) => $copy->forceFill(['label' => '7.º Z'])->save()));
        $this->export(['class_id' => $other->id]);

        $this->assertNull($this->notice()['export']);
    }

    #[Test]
    public function an_export_of_another_period_does_not_count(): void
    {
        $other = $this->asTenant(fn () => $this->class->academicYear->periods()->where('sequence', 2)->firstOrFail());
        $this->export(['academic_period_id' => $other->id]);

        $this->assertNull($this->notice()['export']);
    }

    #[Test]
    public function an_export_of_another_scope_does_not_count(): void
    {
        $this->export(['scope' => ClassificationScope::Accumulated]);

        $this->assertNull($this->notice()['export']);
    }

    #[Test]
    public function an_export_that_does_not_hold_the_student_does_not_count(): void
    {
        $this->export(holdsCarolina: false);

        $this->assertNull($this->notice()['export']);
    }

    #[Test]
    public function the_most_recent_export_wins(): void
    {
        $this->export(['moment_label' => 'Antiga'], 10);
        $latest = $this->export(['moment_label' => 'Recente', 'adapter' => 'snapshot'], 120);
        $this->export(['moment_label' => 'Do meio'], 60);

        $export = $this->notice()['export'];

        $this->assertSame('Recente', $export['moment_label']);
        $this->assertSame('snapshot', $export['adapter']);
        $this->assertSame($latest->exported_at->toIso8601String(), $export['at']);
    }

    #[Test]
    public function it_works_whichever_organization_is_current(): void
    {
        $this->export();
        $other = User::factory()->create();

        $notice = $this->asTenant(
            fn () => app(ExternalCommunicationNotice::class)->for($this->carolina),
            $other->personalOrganization(),
        );

        $this->assertNotNull($notice['export']);
        $this->assertSame('inovar', $notice['export']['adapter']);
    }
}
