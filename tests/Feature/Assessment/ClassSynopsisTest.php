<?php

namespace Tests\Feature\Assessment;

use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SheetMomentKind;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Services\Assessment\DecideDomainAppreciation;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O QUADRO SÍNTESE COMO LEITURA LONGITUDINAL: o ano de uma turma, momento a
 * momento, sem que nada disto seja uma segunda fonte de verdade.
 *
 * As três afirmações que este ficheiro existe para defender:
 *
 *  1. UMA INTERCALAR É UMA FOTOGRAFIA. Aparece na cronologia, lê-se da pauta
 *     guardada, e NÃO entra na avaliação contínua.
 *  2. UMA FOTOGRAFIA NÃO SE RECALCULA. O que ela dizia em novembro continua a
 *     ser o que ela diz em junho, mesmo depois de as notas mudarem.
 *  3. A APRECIAÇÃO VIGENTE É A PROPOSTA ATÉ ALGUÉM DECIDIR OUTRA COISA — e não
 *     é preciso aprovar proposta nenhuma para que ela valha.
 */
class ClassSynopsisTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));
        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
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

    /** @return array<string, mixed> */
    private function synopsis(): array
    {
        return $this->asTenant(fn (): array => app(BuildClassSynopsis::class)->for(
            SchoolClass::where('label', '7.º A')->firstOrFail(),
        ));
    }

    /**
     * @param  array<string, mixed>  $synopsis
     * @return array<string, mixed>
     */
    private function student(array $synopsis, string $name): array
    {
        foreach ($synopsis['students'] as $student) {
            if ($student['name'] === $name) {
                return $student;
            }
        }

        $this->fail("O aluno «{$name}» não está no Quadro Síntese.");
    }

    /**
     * @param  array<string, mixed>  $student
     * @return array<string, mixed>
     */
    private function reading(array $student, string $momentKey): array
    {
        foreach ($student['moments'] as $reading) {
            if ($reading['moment_key'] === $momentKey) {
                return $reading;
            }
        }

        $this->fail("O momento «{$momentKey}» não está na leitura deste aluno.");
    }

    /** A matrícula de um aluno, pelo índice cego — `display_name` está cifrado. */
    private function enrollmentOf(SchoolClass $class, string $name): Enrollment
    {
        return Enrollment::query()
            ->where('class_id', $class->getKey())
            ->whereHas(
                'student.identity',
                fn ($query) => $query->where('display_name_index', BlindIndex::of($name)),
            )
            ->firstOrFail();
    }

    private function keyFor(int $sequence, SheetMomentKind $kind): string
    {
        return $this->asTenant(function () use ($sequence, $kind): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail();

            return BuildClassSynopsis::momentKey((int) $period->getKey(), $kind);
        });
    }

    private function keepInterim(int $sequence, string $on): void
    {
        $this->asTenant(function () use ($sequence, $on): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', $sequence)->firstOrFail();

            app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Momento intercalar do '.$period->label,
                Carbon::parse($on),
                $this->teacher,
                moment: SheetMomentKind::Interim,
            );
        });
    }

    // ------------------------------------------------------------- cronologia

    #[Test]
    public function the_chronology_holds_the_interim_and_the_formal_moment_of_every_configured_unit(): void
    {
        $synopsis = $this->synopsis();

        // Dois semestres × dois momentos. Nada aqui sabe o que é um semestre —
        // os rótulos saem da configuração do próprio ano (§6, §9).
        $this->assertCount(4, $synopsis['moments']);
        $this->assertSame([
            'Intercalar 1.º Semestre',
            '1.º Semestre',
            'Intercalar 2.º Semestre',
            '2.º Semestre',
        ], array_column($synopsis['moments'], 'label'));

        // E cada um diz, em dados, se é uma fotografia ou o resultado formal da
        // unidade — nunca só por cor (§9, §25).
        $this->assertSame([false, true, false, true], array_column($synopsis['moments'], 'is_formal'));
    }

    #[Test]
    public function an_interim_moment_nobody_kept_is_empty_rather_than_invented(): void
    {
        $synopsis = $this->synopsis();
        $carolina = $this->student($synopsis, 'Carolina Nunes');

        $interim = $this->reading($carolina, $this->keyFor(1, SheetMomentKind::Interim));

        // O momento existe na cronologia; o que não existe é uma fotografia
        // dele. Preenchê-lo com os números de hoje seria dizer que em novembro
        // se sabia o que só se sabe agora (§15, §29).
        $this->assertFalse($interim['available']);
        $this->assertNull($interim['overall']);
        $this->assertNull($interim['trend']);
    }

    #[Test]
    public function a_kept_interim_reads_from_the_photograph(): void
    {
        $this->keepInterim(1, '2026-11-20');

        $synopsis = $this->synopsis();
        $moments = collect($synopsis['moments'])->keyBy('key');
        $key = $this->keyFor(1, SheetMomentKind::Interim);

        $this->assertSame('snapshot', $moments[$key]['source']);
        $this->assertSame('2026-11-20', $moments[$key]['snapshot']['effective_at']);

        $carolina = $this->student($synopsis, 'Carolina Nunes');
        $interim = $this->reading($carolina, $key);

        $this->assertTrue($interim['available']);
        $this->assertSame('91.302083', $interim['overall']['normalized_value']);
    }

    #[Test]
    public function the_formal_moment_reads_the_live_sheet_because_a_pauta_stays_editable(): void
    {
        $synopsis = $this->synopsis();
        $moments = collect($synopsis['moments'])->keyBy('key');

        foreach ($moments as $moment) {
            $this->assertSame(
                $moment['is_formal'] ? 'live' : 'none',
                $moment['source'],
                'Um momento formal lê o estado de hoje; um intercalar por guardar não lê nada.',
            );
        }
    }

    // --------------------------------------- a fotografia não se recalcula

    #[Test]
    public function a_photograph_keeps_saying_what_it_said_after_the_marks_move(): void
    {
        $this->keepInterim(1, '2026-11-20');

        $before = $this->reading(
            $this->student($this->synopsis(), 'Carolina Nunes'),
            $this->keyFor(1, SheetMomentKind::Interim),
        );

        // A pauta de hoje muda: o professor decide uma apreciação diferente num
        // domínio. A fotografia de novembro não pode mexer-se por causa disso.
        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $enrollment = $this->enrollmentOf($class, 'Carolina Nunes');

            $domain = Domain::query()->where('name', 'Leitura')->firstOrFail();
            $level = ScaleLevel::query()
                ->where('scale_id', $class->profileVersion->scale_id)
                ->orderBy('sequence')
                ->firstOrFail();

            // PELO CAMINHO CANÓNICO, e não escrevendo a linha à mão: é o mesmo
            // serviço que o botão da Pauta chama, com a mesma validação.
            app(DecideDomainAppreciation::class)->decide(
                $class,
                $period,
                ClassificationScope::Period,
                $enrollment,
                $domain,
                (int) $level->getKey(),
                $this->teacher,
            );
        });

        $after = $this->reading(
            $this->student($this->synopsis(), 'Carolina Nunes'),
            $this->keyFor(1, SheetMomentKind::Interim),
        );

        $this->assertEquals($before['domains'], $after['domains'], 'Uma fotografia não é recalculada (§15).');

        // E o momento FORMAL, esse, mostra a decisão de agora — porque a pauta
        // atual é reeditável e é isso que ela é.
        $formal = $this->reading(
            $this->student($this->synopsis(), 'Carolina Nunes'),
            $this->keyFor(1, SheetMomentKind::Final),
        );

        $decided = collect($formal['domains'])->firstWhere('decided', '!=', null);
        $this->assertNotNull($decided, 'O momento formal reflete a decisão escrita hoje.');
    }

    // --------------------------------------------- proposta, decisão, vigente

    #[Test]
    public function the_proposal_is_what_stands_until_the_teacher_decides_otherwise(): void
    {
        $synopsis = $this->synopsis();
        $carolina = $this->student($synopsis, 'Carolina Nunes');
        $formal = $this->reading($carolina, $this->keyFor(1, SheetMomentKind::Final));

        $withValue = collect($formal['domains'])->first(fn (array $cell): bool => $cell['current']['text'] !== null);

        $this->assertNotNull($withValue);
        // SEM APROVAÇÃO NENHUMA. Não há linha de decisão, não há pendência, e a
        // proposta vigora (§18).
        $this->assertSame('proposed', $withValue['current']['origin']);
        $this->assertNull($withValue['decided']);
        $this->assertSame($withValue['proposed']['code'], $withValue['current']['code']);
    }

    #[Test]
    public function a_decision_takes_over_without_erasing_the_proposal_or_the_quantitative(): void
    {
        $quantitativeBefore = null;
        $proposedBefore = null;

        $before = $this->reading(
            $this->student($this->synopsis(), 'Carolina Nunes'),
            $this->keyFor(1, SheetMomentKind::Final),
        );

        foreach ($before['domains'] as $cell) {
            if ($cell['normalized_value'] !== null) {
                $quantitativeBefore = $cell['normalized_value'];
                $proposedBefore = $cell['proposed']['code'];
                $domainId = $cell['domain_id'];

                break;
            }
        }

        $this->assertNotNull($quantitativeBefore);

        $chosen = $this->asTenant(function () use ($domainId): string {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $enrollment = $this->enrollmentOf($class, 'Carolina Nunes');

            $level = ScaleLevel::query()
                ->where('scale_id', $class->profileVersion->scale_id)
                ->orderBy('sequence')
                ->firstOrFail();

            app(DecideDomainAppreciation::class)->decide(
                $class,
                $period,
                ClassificationScope::Period,
                $enrollment,
                Domain::findOrFail($domainId),
                (int) $level->getKey(),
                $this->teacher,
            );

            return (string) $level->code;
        });

        $after = $this->reading(
            $this->student($this->synopsis(), 'Carolina Nunes'),
            $this->keyFor(1, SheetMomentKind::Final),
        );

        $cell = collect($after['domains'])->firstWhere('domain_id', $domainId);

        $this->assertSame('decided', $cell['current']['origin']);
        $this->assertSame($chosen, $cell['current']['code']);
        // As três coisas que NÃO mudam: o quantitativo, a proposta, e o facto de
        // ambas continuarem consultáveis (§19).
        $this->assertSame($quantitativeBefore, $cell['normalized_value']);
        $this->assertSame($proposedBefore, $cell['proposed']['code']);
    }

    // ------------------------------------------------------------- tendência

    #[Test]
    public function the_trend_compares_the_appreciation_that_stands_at_each_structural_moment(): void
    {
        $this->keepInterim(1, '2026-11-20');

        $synopsis = $this->synopsis();
        $carolina = $this->student($synopsis, 'Carolina Nunes');

        // O primeiro momento com dados nunca tem tendência: não há nada antes
        // dele que se pudesse ter movido (§29).
        $interim = $this->reading($carolina, $this->keyFor(1, SheetMomentKind::Interim));
        $this->assertNull($interim['trend']);

        // O momento seguinte compara-se com ele, e o resultado é dito por
        // palavras além da direção — uma seta sozinha não é informação (§27).
        $formal = $this->reading($carolina, $this->keyFor(1, SheetMomentKind::Final));

        if ($formal['trend'] !== null) {
            $this->assertContains($formal['trend']['direction'], ['up', 'flat', 'down']);
            $this->assertContains($formal['trend']['label'], ['Evolução', 'Manutenção', 'Regressão']);
            $this->assertSame('Intercalar 1.º Semestre', $formal['trend']['from_moment']);
        }
    }

    #[Test]
    public function a_freely_titled_snapshot_never_becomes_a_moment_in_the_chronology(): void
    {
        // Uma pauta com título livre é um registo, não um momento estrutural: o
        // seu sentido e a sua data são o que o professor quis, e uma sequência
        // construída sobre isso não seria uma sequência (§23, §28).
        $this->asTenant(function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(CaptureEvaluationSheet::class)->capture(
                $class,
                $period,
                ClassificationScope::Period,
                'Reunião intercalar com a Diretora de Turma',
                Carbon::parse('2026-11-25'),
                $this->teacher,
                moment: SheetMomentKind::Final,
            );
        });

        $synopsis = $this->synopsis();

        // Quatro momentos, os mesmos de sempre. A pauta guardada não acrescentou
        // um quinto — vive no Histórico, que é onde um registo vive.
        $this->assertCount(4, $synopsis['moments']);
    }

    // ------------------------------------------- avaliação contínua no quadro

    #[Test]
    public function the_continuous_average_ignores_the_interim_even_when_it_was_kept(): void
    {
        $this->keepInterim(1, '2026-11-20');

        // A intercalar do 2.º semestre só pode ser guardada dentro dele: a data
        // de referência é validada contra o `starts_on`/`ends_on` do período.
        $this->travelTo(Carbon::parse('2027-03-10 10:00:00'));
        $this->keepInterim(2, '2027-03-05');

        $synopsis = $this->synopsis();
        $carolina = $this->student($synopsis, 'Carolina Nunes');

        // Duas unidades formais e apenas duas, mesmo com duas fotografias
        // guardadas na cronologia (§7, §56).
        $this->assertCount(2, $synopsis['continuous']['units']);
        $this->assertSame(2, $carolina['continuous']['counted_units']);
        $this->assertSame('89.4010415000', $carolina['continuous']['normalized_value']);
    }

    // -------------------------------------------------------------- elementos

    #[Test]
    public function every_element_travels_with_its_domain_its_date_and_what_happened_to_each_student(): void
    {
        $synopsis = $this->synopsis();

        $this->assertNotEmpty($synopsis['elements']);

        $element = $synopsis['elements'][0];
        foreach (['title', 'applied_on', 'domains', 'counts_toward_classification', 'weight'] as $key) {
            $this->assertArrayHasKey($key, $element);
        }

        // Filipe entrou depois do primeiro elemento: esse elemento não é dele, e
        // o Quadro diz isso por palavras em vez de lhe atribuir um zero (§11).
        $filipe = $this->student($synopsis, 'Filipe Andrade');
        $first = collect($filipe['elements'])->firstWhere('instrument_id', $element['instrument_id']);

        $this->assertFalse($first['applicable']);
        $this->assertNull($first['normalized_value']);
        $this->assertSame('Não aplicável — fora do período de matrícula', $first['state_label']);
    }

    // -------------------------------------------------- visão de turma e links

    #[Test]
    public function every_student_of_the_class_has_a_row_even_without_any_evidence(): void
    {
        $synopsis = $this->synopsis();

        $names = array_column($synopsis['students'], 'name');
        $this->assertContains('Diogo Ferreira', $names);

        // E cada linha traz o endereço do Relatório do aluno — o Quadro liga-se
        // ao que já existe em vez de repetir a análise individual (§14).
        foreach ($synopsis['students'] as $student) {
            $this->assertNotNull($student['enrollment_ulid']);
        }
    }
}
