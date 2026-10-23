<?php

namespace Tests\Feature\Assessment\Advanced;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * O Base responde hoje o que respondia ANTES da Fatia D.
 *
 * NÃO BASTA QUE ABRIR O PRO NÃO ESCREVA NA BASE DE DADOS. O que um professor do plano Base vê — as páginas,
 * os documentos, os ficheiros — tinha de continuar a ser, byte a byte, o que era. Este teste compara, para
 * cada superfície do Base ligada à avaliação, a RESPOSTA normalizada com a que o código anterior à Fatia D
 * deu sobre os mesmos dados (a `main` na 0.175.0, antes da D0): as props de cada página, o CSV da Pauta de
 * hoje e de um momento guardado, e o texto do PDF da grelha.
 *
 * COMO SE LÊ. `tests/Fixtures/AdvancedAnalysis/base-surfaces.json` guarda um sha256 por superfície. Foi
 * CAPTURADO com a árvore de código da 0.175.0 (`BASE_SURFACES_CAPTURE=<ficheiro>`), nunca com esta: se um dia
 * uma superfície do Base mudar de propósito, regenera-se o ficheiro na mesma PR e diz-se. Falhar aqui diz
 * QUAL superfície mudou.
 *
 * NORMALIZADO apenas o que muda sem o conteúdo mudar: ULIDs, ids numéricos (a sequência da BD não é a do
 * teste anterior), datas/horas e o número de versão da aplicação.
 */
class BaseSurfacesUnchangedTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../../Fixtures/AdvancedAnalysis/base-surfaces.json';

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed name: the factory's is random, and the teacher's name is printed on several surfaces (the author of a
        // kept moment, the header of the documents) — a different one per process would be a difference that is not one.
        $this->teacher = User::factory()->create(['name' => 'Ana Martins', 'email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        // The Base plan: what these surfaces are asked for.
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->teacher->personalOrganization()->id],
            ['plan_id' => Plan::where('key', 'base')->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::parse('2026-01-01 00:00:00'), 'ends_at' => null],
        );
        app(Entitlements::class)->flush();

        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            foreach ($class->academicYear->periods()->orderBy('sequence')->get() as $period) {
                app(ProposeClassifications::class)->forPeriod($class, $period);
            }
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    // ================================================================= normalização

    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            // A map keyed by database ids is told by position.
            if (! array_is_list($value) && $value !== [] && array_reduce(array_keys($value), fn (bool $all, int|string $key): bool => $all && is_numeric($key), true)) {
                ksort($value);
                $value = array_values($value);
            }

            $normalised = [];

            foreach ($value as $key => $item) {
                $isId = is_string($key) && preg_match('/(^|_)ids?$/', $key) === 1;

                $normalised[$key] = $isId && (is_int($item) || (is_array($item) && array_is_list($item) && array_filter($item, 'is_int') === $item))
                    ? (is_array($item) ? array_fill(0, count($item), 'ID') : 'ID')
                    : $this->normalise($item);
            }

            return $normalised;
        }

        return is_string($value) ? $this->normaliseText($value) : $value;
    }

    private function normaliseText(string $text): string
    {
        $version = preg_quote((string) config('app.version'), '/');

        $text = preg_replace('/\b[0-9a-z]{26}\b/', 'ULID', $text) ?? $text;
        $text = preg_replace(['/\bperiod-\d+\b/', '/([?&](?:enrollment_id|enrollment|period_id|period|student_id|student|class_id|class)=)\d+/'], ['period-ID', '${1}ID'], $text) ?? $text;
        $text = preg_replace('/\d{4}-\d\d-\d\d[T ][\d:.]+(Z|[+-]\d\d:?\d\d)?/', 'DATETIME', $text) ?? $text;

        return preg_replace('/(?<![\d.])'.$version.'(?![\d.])/', 'VERSION', $text) ?? $text;
    }

    // ================================================================= as superfícies

    /**
     * @return array<string, array{string, string}> label → [kind, url]
     */
    private function surfaces(): array
    {
        [$class, $first, $second, $carolina, $diogo] = $this->asTenant(function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $periods = $class->academicYear->periods()->orderBy('sequence')->get();
            $enrollments = $class->enrollments()->with('student.identity')->get();

            return [
                $class->ulid, $periods[0]->ulid, $periods[1]->ulid,
                $enrollments->first(fn ($enrollment) => $enrollment->student->identity->display_name === 'Carolina Nunes')->ulid,
                $enrollments->first(fn ($enrollment) => $enrollment->student->identity->display_name === 'Diogo Ferreira')->ulid,
            ];
        });

        $this->actingAs($this->teacher)->post("/classes/{$class}/pauta-avaliacao/{$first}/guardar", [
            'moment_label' => 'Momento de referência', 'effective_at' => '2026-12-15', 'scope' => ClassificationScope::Period->value,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $export = $this->asTenant(fn (): string => EvaluationSheetExport::query()->orderByDesc('id')->firstOrFail()->ulid);

        return [
            'dashboard (props, com as partilhadas)' => ['inertia-shared', '/dashboard'],
            'resultados' => ['inertia', "/classes/{$class}/results"],
            'resultados, 1.º período' => ['inertia', "/classes/{$class}/results/{$first}"],
            'resultados, 2.º período' => ['inertia', "/classes/{$class}/results/{$second}"],
            'estatística, 1.º período' => ['inertia', "/classes/{$class}/results/estatistica/{$first}"],
            'estatística, 2.º período' => ['inertia', "/classes/{$class}/results/estatistica/{$second}"],
            'quadro síntese' => ['inertia', "/classes/{$class}/results/quadro-sintese"],
            'pauta, 1.º período' => ['inertia', "/classes/{$class}/pauta-avaliacao/{$first}"],
            'pauta, 2.º período' => ['inertia', "/classes/{$class}/pauta-avaliacao/{$second}"],
            'histórico da pauta' => ['inertia', "/classes/{$class}/pauta-avaliacao/historico"],
            'pauta guardada' => ['inertia', "/classes/{$class}/pauta-avaliacao/historico/{$export}"],
            'grelha documental, 2.º período' => ['inertia', "/classes/{$class}/pauta-avaliacao/impressao/{$second}"],
            'grelha documental, momento guardado' => ['inertia', "/classes/{$class}/pauta-avaliacao/historico/{$export}/impressao"],
            'grelha documental em PDF (texto)' => ['pdf', "/classes/{$class}/pauta-avaliacao/impressao/{$second}/pdf"],
            'grelha do momento guardado em PDF (texto)' => ['pdf', "/classes/{$class}/pauta-avaliacao/historico/{$export}/impressao/pdf"],
            'csv da pauta, 1.º período' => ['body', "/classes/{$class}/pauta-avaliacao/csv/{$first}"],
            'csv da pauta, 2.º período' => ['body', "/classes/{$class}/pauta-avaliacao/csv/{$second}"],
            'csv do momento guardado' => ['body', "/classes/{$class}/pauta-avaliacao/historico/{$export}/csv"],
            'evolução do aluno, índice' => ['inertia', '/evolucao'],
            'evolução da turma' => ['inertia', "/classes/{$class}/evolucao"],
            'evolução do aluno (Carolina)' => ['inertia', "/classes/{$class}/evolucao/{$carolina}"],
            'evolução do aluno (Diogo)' => ['inertia', "/classes/{$class}/evolucao/{$diogo}"],
        ];
    }

    /**
     * What one surface answers, normalised and reduced to a hash.
     */
    private function answer(string $kind, string $url): string
    {
        $response = $this->actingAs($this->teacher)->get($url)->assertOk();

        if ($kind === 'body') {
            $normalised = $this->normaliseText((string) $response->getContent());
        } elseif ($kind === 'pdf') {
            $text = (new Parser)->parseContent((string) $response->getContent())->getText();
            $normalised = $this->normaliseText(preg_replace('/\s+/u', ' ', $text) ?? $text);
        } else {
            $props = null;
            $response->assertInertia(function ($page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

            if ($kind === 'inertia') {
                // The page's own props: the ones every page shares are the shell's, compared once, on the dashboard.
                foreach (array_keys(app(HandleInertiaRequests::class)->share(app('request'))) as $key) {
                    unset($props[$key]);
                }

                unset($props['errors'], $props['flash']);
            }

            $normalised = (string) json_encode($this->normalise($props), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }

        // For the day a surface changes: BASE_SURFACES_DUMP=<dir> writes what each one answered, to be diffed.
        $dump = getenv('BASE_SURFACES_DUMP');

        if (is_string($dump) && $dump !== '') {
            file_put_contents($dump.'/'.preg_replace('/[^a-z0-9]+/i', '-', $url).'.txt', $normalised);
        }

        return hash('sha256', $normalised);
    }

    /**
     * @param  array<string, array{string, string}>|null  $surfaces  the surfaces to ask (a pass creates the kept moment once)
     * @return array<string, string>
     */
    private function capture(?array $surfaces = null): array
    {
        $answers = [];

        foreach ($surfaces ?? $this->surfaces() as $label => [$kind, $url]) {
            $answers[$label] = $this->answer($kind, $url);
        }

        return $answers;
    }

    #[Test]
    public function every_base_surface_answers_what_it_answered_before_the_fatia_d(): void
    {
        $answers = $this->capture();

        $target = getenv('BASE_SURFACES_CAPTURE');

        if (is_string($target) && $target !== '') {
            file_put_contents($target, json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('Captured: '.count($answers).' surfaces.');
        }

        $this->assertFileExists(self::FIXTURE);
        $expected = json_decode((string) file_get_contents(self::FIXTURE), true);

        $this->assertSame(array_keys($expected), array_keys($answers), 'the surfaces compared are the surfaces captured');

        $changed = array_keys(array_filter($answers, fn (string $hash, string $label): bool => $hash !== $expected[$label], ARRAY_FILTER_USE_BOTH));

        $this->assertSame([], $changed, 'These Base surfaces no longer answer what they answered before the Fatia D. If that is deliberate, regenerate the fixture in the same PR and say so.');
    }

    #[Test]
    public function the_surfaces_are_deterministic_so_the_comparison_means_something(): void
    {
        // The kept moment is made once: both passes ask the very same surfaces.
        $surfaces = $this->surfaces();
        $first = $this->capture($surfaces);

        // A second pass over the same data, same clock: the same answers. Without this, a «same» above could be luck.
        $second = $this->capture($surfaces);

        $this->assertSame($first, $second);
        $this->assertCount(22, $first);
        $this->assertCount(22, array_unique(array_keys($first)));
    }

    #[Test]
    public function diag_dump(): void
    {
        $surfaces = $this->surfaces();
        $out = '';

        foreach (['quadro síntese', 'pauta guardada'] as $label) {
            [$kind, $url] = $surfaces[$label];
            $dir = sys_get_temp_dir().'/diag';
            @mkdir($dir);
            putenv('BASE_SURFACES_DUMP='.$dir);
            $this->answer($kind, $url);
            $file = $dir.'/'.preg_replace('/[^a-z0-9]+/i', '-', $url).'.txt';
            $out .= "
<<<SURFACE {$label}>>>
".file_get_contents($file)."
<<<END>>>";
        }

        $this->fail($out);
    }
}
