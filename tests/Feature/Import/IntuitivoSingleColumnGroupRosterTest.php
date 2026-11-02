<?php

namespace Tests\Feature\Import;

use App\Domain\Import\Correction\ImportMapping;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\ItemDomainAllocation;
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
    /** O cabeçalho do grupo de uma só coluna; as fichas reais trazem-no com o título («GRUPO V - Expressão escrita»). */
    protected string $lastGroupLabel = 'GRUPO V';

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
            ->singleColumnGroup($this->lastGroupLabel, 24);
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

        // Opens on the questions (the whole file); the teacher switches to the sections.
        $this->assertSame(ImportMapping::RESULT_PER_QUESTION, $preview['result_mode']);

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

    // ------------------------- d. a revisão mostra cada classificação, cada subtotal e o total ANTES de confirmar

    #[Test]
    public function the_review_carries_every_mark_every_subtotal_and_the_total_of_every_student(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);
        $mapping = $this->createMapping($preview);
        unset($mapping['domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $preview = $this->preview($import);

        $this->assertSame(ImportMapping::RESULT_PER_QUESTION, $preview['result_mode']);
        $this->assertCount(21, $preview['students']);
        $this->assertCount(17, $preview['items']);
        $this->assertCount(5, $preview['groups']);

        // Cada pergunta diz a que grupo pertence, por isso a revisão desenha-as debaixo da sua secção.
        $this->assertSame(
            array_merge(array_fill(0, 4, 'group:1'), array_fill(0, 4, 'group:2'), array_fill(0, 4, 'group:3'), array_fill(0, 4, 'group:4'), ['group:5']),
            array_column($preview['items'], 'group_source_key'),
        );

        $keys = array_column($preview['items'], 'source_key');
        $byName = [];

        foreach ($preview['students'] as $row) {
            $byName[$row['display_name']] = $row;
        }

        $marksSeen = 0;
        $subtotalsSeen = 0;
        $totalsSeen = 0;
        $groupSizes = [4, 4, 4, 4, 1];

        foreach ($this->names as $number => $name) {
            if (in_array($number, self::PAPER_NUMBERS, true)) {
                $this->assertArrayNotHasKey($name, $byName, 'Quem fez a prova em papel não está no ficheiro.');

                continue;
            }

            $row = $byName[$name];
            $this->assertCount(17, $row['marks']);

            foreach ($keys as $question => $key) {
                $this->assertSame((string) $this->markFor($number, $question), $row['marks'][$key], "{$name}, pergunta ".($question + 1));
                $marksSeen++;
            }

            $this->assertCount(5, $row['group_results']);
            $offset = 0;

            foreach ($this->groupSums($number) as $group => $expected) {
                $this->assertSame((string) $expected, $row['group_results'][$group]['earned'], "{$name}, subtotal do grupo ".($group + 1));
                $this->assertSame((string) array_sum(array_slice($this->maxima(), $offset, $groupSizes[$group])), $row['group_results'][$group]['possible']);
                $offset += $groupSizes[$group];
                $subtotalsSeen++;
            }

            $this->assertSame((string) array_sum($this->groupSums($number)), $row['lapis_total'], "{$name}, total");
            $totalsSeen++;
        }

        $this->assertSame(21 * 17, $marksSeen);
        $this->assertSame(21 * 5, $subtotalsSeen);
        $this->assertSame(21, $totalsSeen);
    }

    #[Test]
    public function in_the_review_a_blank_cell_is_a_blank_and_a_zero_is_a_zero(): void
    {
        // Aluno 4, pergunta 1 em branco (célula sem valor); aluno 5, pergunta 2 a zero. O
        // workbookBuilder() lê os overrides com `??`, que engole o null — por isso o ficheiro é montado aqui.
        $builder = $this->declareGroups(IntuitivoWorkbookBuilder::make());

        foreach (array_reverse(array_keys($this->names)) as $number) {
            if (in_array($number, self::PAPER_NUMBERS, true)) {
                continue;
            }

            $scores = [];

            foreach (array_keys($this->maxima()) as $question) {
                $scores[] = match (true) {
                    $number === 4 && $question === 0 => null,
                    $number === 5 && $question === 1 => 0,
                    default => $this->markFor($number, $question),
                };
            }

            $builder->student($this->names[$number], $scores);
        }

        $import = $this->uploadWorkbook($builder);
        $mapping = $this->createMapping($this->preview($import));
        unset($mapping['domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $preview = $this->preview($import);

        $keys = array_column($preview['items'], 'source_key');
        $rows = [];

        foreach ($preview['students'] as $row) {
            $rows[$row['display_name']] = $row;
        }

        $blank = $rows[$this->names[4]];
        $this->assertNull($blank['marks'][$keys[0]]);
        $this->assertNull($blank['group_results'][0]['earned'], 'Um grupo com uma célula em branco não tem subtotal.');
        $this->assertNotNull($blank['group_results'][1]['earned']);
        $this->assertNull($blank['lapis_total'], 'Nem total: uma soma parcial seria uma nota mais baixa a passar por completa.');

        $zero = $rows[$this->names[5]];
        $this->assertSame('0', $zero['marks'][$keys[1]]);
        $this->assertNotNull($zero['group_results'][0]['earned']);
        $this->assertNotNull($zero['lapis_total']);
    }

    // ------------------------- produção 2026-11-02: a grelha importada dizia «Por classificar» em todas as linhas

    #[Test]
    public function a_grid_imported_without_domains_offers_one_domain_per_group_and_then_produces_results(): void
    {
        $import = $this->uploadWorkbook();
        $mapping = $this->createMapping($this->preview($import));
        unset($mapping['domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        $instrument = $this->instrument();
        $props = fn (): array => $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}")->assertOk()->viewData('page')['props'];
        $before = $props();

        // O que aconteceu em produção: 17 perguntas, 357 classificações, nenhum domínio — e o motor não vê nada.
        $this->assertTrue($before['domainAssignment']['needed']);
        $this->assertTrue($before['domainAssignment']['can_assign']);
        $this->assertSame(17, $before['domainAssignment']['items_without_domain']);
        $this->assertCount(5, $before['domainAssignment']['groups']);
        $this->assertSame([4, 4, 4, 4, 1], array_column($before['domainAssignment']['groups'], 'items_without_domain'));
        $this->assertSame(['Leitura', 'Gramática', 'Escrita'], array_column($before['domainAssignment']['domains'], 'name'));
        $this->assertNull($before['domainAssignment']['groups'][0]['suggested_domain_id'], '«GRUPO I» não nomeia domínio nenhum: nada a sugerir.');

        // Os ids dos domínios DO PERFIL, pelo nome, tal como a grelha os oferece. Em MySQL pode haver mais do que um
        // «Leitura» visível (`Domain::where('name')->first()` sem ordem não é determinista), e só estes contam.
        $profile = array_column($before['domainAssignment']['domains'], 'id', 'name');

        // Um grupo cujo nome já diz o domínio («GRUPO II - Leitura», como na ficha real) vem pré-sugerido —
        // sugerido, não decidido: continua a ser o professor a confirmar.
        app(CurrentOrganization::class)->runFor($this->organization, function () use ($instrument): void {
            $instrument->groups()->orderBy('sequence')->skip(1)->first()->update(['label' => 'GRUPO II - Leitura']);
        });
        $this->assertSame($profile['Leitura'], $props()['domainAssignment']['groups'][1]['suggested_domain_id']);

        foreach ($before['official']['students'] as $cell) {
            $this->assertSame('pending', $cell['status'], 'Sem domínios, toda a gente está «Por classificar».');
        }

        // Um domínio fora do perfil é recusado antes de tocar em seja o que for.
        $groups = array_column($before['domainAssignment']['groups'], 'ulid');
        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/domains", ['assignments' => [['group' => $groups[0], 'domain_id' => 999999]]])
            ->assertSessionHasErrors('assignments');
        $this->assertSame(0, $this->allocationsOf($instrument));

        // O passo em falta: um domínio por grupo, uma vez.
        $assignments = [];

        foreach (['Leitura', 'Gramática', 'Escrita', 'Leitura', 'Escrita'] as $index => $name) {
            $assignments[] = ['group' => $groups[$index], 'domain_id' => $profile[$name]];
        }

        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/domains", ['assignments' => $assignments])->assertRedirect();
        $this->assertSame(17, $this->allocationsOf($instrument), 'Cada pergunta ficou com exatamente uma alocação a 100 %.');

        $after = $props();
        $this->assertFalse($after['domainAssignment']['needed']);
        $this->assertSame(0, $after['domainAssignment']['items_without_domain']);
        $this->assertCount(3, $after['official']['domains']);

        foreach ($this->enrollmentByNumber as $number => $enrollmentId) {
            $cell = $after['official']['students'][$enrollmentId];

            if (in_array($number, self::PAPER_NUMBERS, true)) {
                $this->assertSame('pending', $cell['status'], 'Quem fez em papel continua sem classificação — nunca um zero.');

                continue;
            }

            $this->assertNotSame('pending', $cell['status'], "aluno {$number}");
            $this->assertNotNull($cell['global']['value'], "aluno {$number}: a classificação global passou a existir");

            foreach ($after['official']['domains'] as $domain) {
                $this->assertNotNull($cell['domains'][$domain['key']]['value'] ?? null, "aluno {$number}, domínio {$domain['name']}");
            }
        }

        // As classificações originais não mexeram: continuam 357, iguais ao ficheiro.
        $scores = $this->scoresOf($instrument);
        $items = $this->itemIds($instrument);
        $this->assertCount(21 * 17, $scores);

        foreach ($this->names as $number => $name) {
            if (in_array($number, self::PAPER_NUMBERS, true)) {
                continue;
            }

            foreach ($items as $question => $itemId) {
                $this->assertEqualsWithDelta($this->markFor($number, $question), (float) $scores[$this->enrollmentByNumber[$number].':'.$itemId]->points_earned, 0.0001);
            }
        }

        // Repetir com outros domínios não mexe em nada: só perguntas SEM alocação recebem uma.
        $this->actingAs($this->teacher)
            ->post("/instruments/{$instrument->ulid}/domains", ['assignments' => [['group' => $groups[0], 'domain_id' => $profile['Escrita']]]])
            ->assertRedirect();
        $this->assertSame(17, $this->allocationsOf($instrument));
        $this->assertSame(
            $profile['Leitura'],
            (int) app(CurrentOrganization::class)->runFor($this->organization, fn () => ItemDomainAllocation::where('instrument_item_id', $items[0])->value('domain_id')),
        );

        // E o separador Resultados passa a ter as colunas dos domínios.
        $results = $this->actingAs($this->teacher)->get("/instruments/{$instrument->ulid}/resultados")->assertOk()->viewData('page')['props'];
        $this->assertFalse($results['domainAssignment']['needed']);
        $this->assertSame(['Leitura', 'Gramática', 'Escrita'], array_column($results['context']['domains'], 'name'));
    }

    protected function allocationsOf(Instrument $instrument): int
    {
        return app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => ItemDomainAllocation::whereIn('instrument_item_id', InstrumentItem::where('instrument_id', $instrument->getKey())->select('id'))->count(),
        );
    }

    // ------------------------- produção 2026-11-01: o cabeçalho do GRUPO V é mais comprido do que o código de um item

    #[Test]
    public function a_long_header_on_the_single_column_group_does_not_break_the_confirmation(): void
    {
        // `instrument_items.code` tem 16 caracteres; o cabeçalho real tem 27. Foi um 500 no último passo.
        $this->lastGroupLabel = 'GRUPO V - Expressão escrita';

        $import = $this->uploadWorkbook();
        $mapping = $this->createMapping($this->preview($import));
        unset($mapping['domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->assertTrue($this->preview($import)['can_confirm']);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        $instrument = $this->instrument();
        $items = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => InstrumentItem::where('instrument_id', $instrument->getKey())->orderBy('sequence')->get(),
        );

        $this->assertCount(17, $items);
        // O texto inteiro não se perde: fica como rótulo; o código é a posição, que é única.
        $this->assertSame('GRUPO V - Expressão escrita', $items[16]->label);
        $this->assertSame('Q17', $items[16]->code);
        $this->assertLessThanOrEqual(16, max(array_map(fn ($code): int => mb_strlen((string) $code), $items->pluck('code')->all())));
        // As perguntas com código curto mantêm o que o ficheiro trazia.
        $this->assertSame('Item 1', $items[0]->code);

        $scores = $this->scoresOf($instrument);
        $this->assertCount(21 * 17, $scores);
    }

    // ------------------------- e. a grelha reproduz o ficheiro: cada pergunta, cada subtotal, o total

    #[Test]
    public function opened_as_it_arrives_every_mark_of_every_student_is_persisted_and_the_grid_shows_groups_and_subtotals(): void
    {
        $import = $this->uploadWorkbook();
        $preview = $this->preview($import);

        // Sem escolher nada além do que o ficheiro não traz (título, data, tipo, período): nenhuma
        // cotação, nenhuma classificação, nenhum domínio é pedido ao professor.
        $this->assertSame(ImportMapping::RESULT_PER_QUESTION, $preview['result_mode']);
        $mapping = $this->createMapping($preview);
        unset($mapping['domains']);

        $this->actingAs($this->teacher)->patch("/imports/correction/{$import->ulid}", $mapping);
        $this->assertTrue($this->preview($import)['can_confirm']);
        $this->actingAs($this->teacher)->post("/imports/correction/{$import->ulid}/confirm")->assertRedirect();

        $instrument = $this->instrument();
        $items = $this->itemIds($instrument);
        $scores = $this->scoresOf($instrument);

        $this->assertCount(17, $items);
        $this->assertCount(21 * 17, $scores, '21 alunos × 17 perguntas, e nenhuma linha para quem fez a prova em papel.');

        $groupSizes = [4, 4, 4, 4, 1];

        foreach ($this->names as $number => $name) {
            if (in_array($number, self::PAPER_NUMBERS, true)) {
                continue;
            }

            foreach ($items as $question => $itemId) {
                $score = $scores[$this->enrollmentByNumber[$number].':'.$itemId];

                $this->assertSame('assessed', $score->result_state->value);
                $this->assertEqualsWithDelta($this->markFor($number, $question), (float) $score->points_earned, 0.0001, "{$name}, pergunta ".($question + 1));
            }

            // O subtotal de cada grupo é a soma das suas perguntas persistidas.
            $offset = 0;

            foreach ($this->groupSums($number) as $group => $expected) {
                $sum = 0.0;

                for ($i = 0; $i < $groupSizes[$group]; $i++) {
                    $sum += (float) $scores[$this->enrollmentByNumber[$number].':'.$items[$offset + $i]]->points_earned;
                }

                $this->assertEqualsWithDelta($expected, $sum, 0.0001, "{$name}, subtotal do grupo ".($group + 1));
                $offset += $groupSizes[$group];
            }
        }

        // A grelha recebe os cinco grupos e cada pergunta diz a que grupo pertence: é o que lhe permite
        // desenhar os subtotais. As cotações vêm do ficheiro (16+18+20+22+24 = 100).
        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertCount(5, $props['groups']);
                $this->assertSame([0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4], array_column($props['items'], 'group_index'));
                $this->assertSame([4, 4, 4, 4, 4, 4, 4, 6, 5, 5, 5, 5, 6, 7, 4, 5, 24], array_map('intval', array_column($props['items'], 'points_possible')));
                $this->assertCount(21 * 17, $props['scores']);
            });
    }
}
