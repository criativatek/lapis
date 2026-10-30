<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\ImportMapping;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\StudentItemScore;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use App\Services\Assessment\RecordScores;
use App\Support\Tenancy\CurrentOrganization;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Import\IntuitivoWorkbookBuilder;

/**
 * O mesmo cenário da turma de 23 com 21 alunos no ficheiro, agora com a
 * estrutura de uma ficha real: quatro grupos de quatro perguntas e um GRUPO V
 * de uma só coluna (24 pontos), 17 perguntas, 100 no total.
 *
 * Herda todos os testes de IntuitivoPartialRosterTest — pré-visualização,
 * emparelhamento por nome, 357 notas nos sítios certos, preenchimento manual
 * dos dois alunos em papel, reimportação sem CONFLICT_IMPORT, exportações — e só
 * muda o que o ficheiro é. Acrescenta o modo por grupos, as cotações vindas do
 * ficheiro e a reconciliação.
 */
class IntuitivoSingleColumnGroupRosterTest extends IntuitivoPartialRosterTest
{
    protected function maxima(): array
    {
        return [4, 4, 4, 4, 4, 4, 4, 6, 5, 5, 5, 5, 6, 7, 4, 5, 24];
    }

    protected function declareGroups(IntuitivoWorkbookBuilder $builder): IntuitivoWorkbookBuilder
    {
        return $builder
            ->group('GRUPO I', [4, 4, 4, 4])
            ->group('GRUPO II', [4, 4, 4, 6])
            ->group('GRUPO III', [5, 5, 5, 5])
            ->group('GRUPO IV', [6, 7, 4, 5])
            ->singleColumnGroup('GRUPO V', 24);
    }

    protected function domainNameFor(int $question): string
    {
        // Vários grupos no mesmo domínio, e o GRUPO V (uma pergunta) no seu.
        return match (true) {
            $question < 4, $question >= 12 && $question < 16 => 'Leitura',
            $question < 8 => 'Gramática',
            default => 'Escrita',
        };
    }

    /**
     * @return list<int> o domínio de cada um dos cinco grupos
     */
    protected function groupDomainIds(): array
    {
        return [
            $this->domains['Leitura'], $this->domains['Gramática'], $this->domains['Escrita'],
            $this->domains['Leitura'], $this->domains['Escrita'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function groupedMapping(array $preview): array
    {
        $mapping = $this->createMapping($preview);
        $mapping['result_mode'] = ImportMapping::RESULT_PER_GROUP;
        unset($mapping['points'], $mapping['domains']);

        foreach ($this->groupDomainIds() as $index => $domainId) {
            $mapping['group_domains']['group:'.($index + 1)] = [['domain_id' => $domainId, 'allocation_percent' => '100']];
        }

        return $mapping;
    }

    /** @return list<int> a soma dos pontos de cada grupo para um aluno */
    protected function groupSums(int $classNumber): array
    {
        $sizes = [4, 4, 4, 4, 1];
        $sums = [];
        $question = 0;

        foreach ($sizes as $size) {
            $sum = 0;

            for ($i = 0; $i < $size; $i++, $question++) {
                $sum += $this->markFor($classNumber, $question);
            }

            $sums[] = $sum;
        }

        return $sums;
    }

    // ------------------------------------------------ a. a leitura do ficheiro

    #[Test]
    public function the_preview_shows_five_groups_and_seventeen_questions_with_the_cotacoes_of_the_file(): void
    {
        $preview = $this->preview($this->uploadWorkbook());

        $this->assertCount(5, $preview['groups']);
        $this->assertSame(['GRUPO I', 'GRUPO II', 'GRUPO III', 'GRUPO IV', 'GRUPO V'], array_column($preview['groups'], 'label'));
        $this->assertSame([4, 4, 4, 4, 1], array_column($preview['groups'], 'questions'));
        $this->assertSame(['16', '18', '20', '22', '24'], array_column($preview['groups'], 'points_possible'));

        $this->assertCount(17, $preview['items']);
        $this->assertSame(['4', '4', '4', '4', '4', '4', '4', '6', '5', '5', '5', '5', '6', '7', '4', '5', '24'], array_column($preview['items'], 'points'));
        $this->assertSame(array_column($preview['items'], 'points'), array_column($preview['items'], 'source_points'));

        // Nada a reconciliar que não bata: totais certos, nenhum aviso.
        $this->assertSame('100', $preview['reconciliation']['groups_total']);
        $this->assertTrue($preview['reconciliation']['maximum_agrees']);
        $this->assertSame(0, $preview['reconciliation']['students_disagreeing']);

        $codes = array_column($preview['issues'], 'code');
        $this->assertNotContains('source_total_mismatch', $codes);
        $this->assertNotContains('missing_points', $codes);
        $this->assertSame(self::MESSAGE_21_2, $this->missingIssue($preview)['message']);
    }

    #[Test]
    public function a_total_that_is_wrong_in_the_file_is_still_a_visible_warning(): void
    {
        $builder = $this->workbookBuilder()->overridingTotalOf(0, 7.0);

        $import = $this->uploadWorkbook($builder);
        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->groupedMapping($this->preview($import)));
        $preview = $this->preview($import);

        $this->assertSame(1, $preview['reconciliation']['students_disagreeing']);
        $this->assertContains('source_total_mismatch', array_column($preview['issues'], 'code'));
        $this->assertTrue($preview['can_confirm'], 'É um aviso, não um bloqueio.');
    }

    // ----------------------------------------------------- b. cotações do ficheiro

    #[Test]
    public function an_empty_cotacao_from_the_client_falls_back_to_the_file_in_preview_and_in_confirmation(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $mapping = $this->createMapping($preview);
        $mapping['points'] = array_fill_keys(array_column($preview['items'], 'source_key'), '');

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $after = $this->preview($import);

        $this->assertSame(array_column($preview['items'], 'source_points'), array_column($after['items'], 'points'));
        $this->assertNotContains('missing_points', array_column($after['issues'], 'code'));
        $this->assertTrue($after['can_confirm']);

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        $stored = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => InstrumentItem::where('instrument_id', $this->instrument()->getKey())->orderBy('sequence')->pluck('points_possible')->map(fn ($p): string => (string) (float) $p)->all(),
        );

        $this->assertSame(['4', '4', '4', '4', '4', '4', '4', '6', '5', '5', '5', '5', '6', '7', '4', '5', '24'], $stored);
    }

    #[Test]
    public function a_cotacao_changed_by_the_teacher_wins_over_the_file(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $mapping = $this->createMapping($preview);
        $mapping['points'][$preview['items'][16]['source_key']] = '20';

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $after = $this->preview($import);

        $this->assertSame('20', $after['items'][16]['points']);
        $this->assertSame('24', $after['items'][16]['source_points']);
    }

    // ---------------------------------------------------------- c. modo por grupos

    #[Test]
    public function by_groups_each_of_the_five_groups_is_confirmed_with_a_domain_and_keeps_the_file_places(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $this->assertSame(ImportMapping::RESULT_PER_GROUP, $preview['result_mode']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $this->groupedMapping($preview));

        $after = $this->preview($import);
        $this->assertTrue($after['can_confirm']);

        foreach ($after['groups'] as $index => $group) {
            $this->assertSame($this->groupDomainIds()[$index], $group['domains'][0]['domain_id'], "{$group['label']} sem o domínio escolhido.");
        }

        // Os resultados por grupo da pré-visualização batem com o ficheiro.
        $row = collect($after['students'])->first(fn (array $student): bool => $student['display_name'] === $this->names[5]);
        $this->assertSame(
            array_map('strval', $this->groupSums(5)),
            array_map(fn (array $group): string => (string) (float) $group['earned'], $row['group_results']),
        );

        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        $instrument = $this->instrument();
        $items = $this->itemIds($instrument);

        $this->assertCount(5, $items);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $this->assertSame(1, Instrument::count());
            $this->assertSame(
                [16.0, 18.0, 20.0, 22.0, 24.0],
                InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->pluck('points_possible')->map(fn ($p): float => (float) $p)->all(),
            );
        });

        $scores = $this->scoresOf($instrument);
        $this->assertCount(21 * 5, $scores);

        foreach ($this->names as $number => $name) {
            $sums = $this->groupSums($number);

            foreach ($items as $index => $itemId) {
                $key = $this->enrollmentByNumber[$number].':'.$itemId;

                if (in_array($number, self::PAPER_NUMBERS, true)) {
                    $this->assertArrayNotHasKey($key, $scores);

                    continue;
                }

                $this->assertEqualsWithDelta($sums[$index], (float) $scores[$key]->points_earned, 0.0001, "{$name}, grupo ".($index + 1));
            }
        }

        // A grelha é da turma: 23 alunos pela ordem oficial.
        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $students = $page->toArray()['props']['students'];

                $this->assertSame(range(1, 23), array_column($students, 'class_number'));
            });

        // Os dois do papel: nenhuma linha.
        foreach (self::PAPER_NUMBERS as $number) {
            $this->assertSame(0, app(CurrentOrganization::class)->runFor(
                $this->organization,
                fn () => StudentItemScore::where('enrollment_id', $this->enrollmentByNumber[$number])->count(),
            ));
        }

        // Preenchimento à mão do GRUPO V (a pergunta única) de um dos dois, sem
        // tocar nas notas importadas.
        $before = $this->scoresOf($instrument);

        app(CurrentOrganization::class)->runFor($this->organization, fn () => app(RecordScores::class)->save($instrument, [[
            'enrollment_id' => $this->enrollmentByNumber[7],
            'instrument_item_id' => $items[4],
            'result_state' => 'assessed',
            'points_earned' => 18.0,
            'lock_version' => 0,
        ]], $this->teacher));

        $after = $this->scoresOf($instrument);
        $this->assertCount((21 * 5) + 1, $after);

        foreach ($before as $key => $score) {
            $this->assertSame($score->lock_version, $after[$key]->lock_version, 'Uma nota importada foi reescrita.');
        }

        $manual = $after[$this->enrollmentByNumber[7].':'.$items[4]];
        $this->assertEqualsWithDelta(18.0, (float) $manual->points_earned, 0.0001);

        // Os totais calculam-se com a célula preenchida e sem inventar as outras.
        $official = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => app(BuildResultsAnalysis::class)->officialCells($instrument->fresh()),
        )['students'];

        $this->assertCount(23, $official);
        $this->assertNotEmpty($official[$this->enrollmentByNumber[5]]['global']);
    }

    #[Test]
    public function by_groups_a_group_without_a_domain_blocks_the_confirmation(): void
    {
        $import = $this->uploadWorkbook();
        $mapping = $this->groupedMapping($this->preview($import));
        unset($mapping['group_domains']['group:5']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $preview = $this->preview($import);

        $this->assertFalse($preview['can_confirm']);
        $this->assertStringContainsString('GRUPO V', implode(' ', array_column($preview['issues'], 'message')));
    }

    // ------------------------------------------- d. modo por perguntas, com grupos

    #[Test]
    public function by_questions_the_five_groups_are_still_shown_and_the_single_question_group_is_mapped(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        $mapping = $this->createMapping($preview);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);

        $after = $this->preview($import);

        $this->assertSame(ImportMapping::RESULT_PER_QUESTION, $after['result_mode']);
        $this->assertCount(5, $after['groups']);

        // A pergunta do GRUPO V leva o domínio escolhido e pertence ao seu grupo.
        $last = $after['items'][16];
        $this->assertSame('GRUPO V', $last['label']);
        $this->assertSame($this->domains['Escrita'], $last['domains'][0]['domain_id']);
    }
}
