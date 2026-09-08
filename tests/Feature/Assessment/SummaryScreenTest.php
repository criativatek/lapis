<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Quadro Síntese: the year of a class, read whole.
 *
 * It adds no arithmetic of its own. Everything on it — the standalone average
 * of each period, the accumulated one, the movement between them, what the
 * student said about each domain and about the period, the proposal and the
 * decision — comes from BuildResultsProgression, in a single call, and is
 * arranged rather than computed. A second opinion about those numbers is the
 * one thing this screen must never have.
 */
class SummaryScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function screen(): string
    {
        return (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Summary.vue')),
        );
    }

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    private function classUlid(User $teacher): string
    {
        return $this->asTenant($teacher, fn (): string => SchoolClass::where('label', '7.º A')->firstOrFail()->ulid);
    }

    private function open(User $teacher, string $classUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/results/quadro-sintese");
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    // ------------------------------------------------------- 1. navegação

    #[Test]
    public function the_summary_has_its_own_route_and_is_not_mistaken_for_a_period(): void
    {
        $teacher = $this->seedDemo();

        $this->open($teacher, $this->classUlid($teacher))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('results/Summary'));
    }

    #[Test]
    public function the_per_period_screen_offers_it_after_the_real_periods(): void
    {
        $perPeriod = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/results/Show.vue')),
        );

        // The buttons are built from the periods the year actually has, and this
        // one comes after them — never one of them, and never a fixed list (§2).
        $this->assertStringContainsString('v-for="period in periods"', $perPeriod);
        $this->assertStringContainsString('/results/quadro-sintese', $perPeriod);
        $this->assertLessThan(
            strpos($perPeriod, 'Quadro Síntese'),
            (int) strpos($perPeriod, 'v-for="period in periods"'),
        );
    }

    #[Test]
    public function another_organizations_teacher_cannot_open_it(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $this->actingAs(User::factory()->create())
            ->get("/classes/{$classUlid}/results/quadro-sintese")
            ->assertNotFound();
    }

    // ------------------------------------------- 2. o que a vista recebe

    #[Test]
    public function the_screen_receives_the_read_model_whole_and_untouched(): void
    {
        $teacher = $this->seedDemo();
        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        // Periods and domains come from the configuration — their number and
        // their names are the class's, not this screen's.
        $this->assertNotEmpty($props['progression']['periods']);
        $this->assertNotEmpty($props['progression']['domains']);
        $this->assertCount(6, $props['progression']['students']);

        $student = $props['progression']['students'][0];
        $this->assertCount(count($props['progression']['periods']), $student['periods']);

        $period = $student['periods'][0];

        foreach (['weighted_average', 'accumulated_average', 'evolution', 'domains', 'self_assessment', 'classification'] as $key) {
            $this->assertArrayHasKey($key, $period);
        }

        // Every domain of the profile appears inside every period, so the table
        // can be built without asking for anything else.
        $this->assertCount(count($props['progression']['domains']), $period['domains']);

        foreach (['weighted_average', 'accumulated_average', 'evolution', 'self_assessment'] as $key) {
            $this->assertArrayHasKey($key, $period['domains'][0]);
        }
    }

    #[Test]
    public function the_first_period_has_no_evolution_and_the_later_ones_do(): void
    {
        $teacher = $this->seedDemo();
        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $found = ['up' => false, 'down' => false];

        foreach ($props['progression']['students'] as $student) {
            // Nothing before the first period to compare against, in the whole
            // row and in every domain of it. An absence is not a regression.
            $this->assertNull($student['periods'][0]['evolution']);

            foreach ($student['periods'][0]['domains'] as $domain) {
                $this->assertNull($domain['evolution']);
            }

            $direction = $student['periods'][1]['evolution']['direction'] ?? null;

            if ($direction !== null) {
                $found[$direction] = true;
            }
        }

        // The demo class carries both readings, which is what makes it worth
        // looking at.
        $this->assertTrue($found['up'], 'faltou um aluno em progressão');
        $this->assertTrue($found['down'], 'faltou um aluno em regressão');
    }

    #[Test]
    public function the_decision_of_each_period_is_kept_as_it_was(): void
    {
        $teacher = $this->seedDemo();

        // A proposal in the first period, and a decision on it — so the quadro
        // has a real history to show rather than an empty column.
        $this->asTenant($teacher, function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $first = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $first);

            $decided = Classification::query()
                ->where('academic_period_id', $first->id)
                ->where('scope', ClassificationScope::Period)
                ->firstOrFail();

            app(ConfirmClassification::class)->confirm($decided, $teacher);
        });

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $seen = ['proposal' => false, 'decision' => false];

        foreach ($props['progression']['students'] as $student) {
            foreach ($student['periods'] as $period) {
                if ($period['classification'] === null) {
                    continue;
                }

                // The proposal read on the scale, on every kind of scale — and
                // the decision beside it, never derived from it.
                $this->assertArrayHasKey('proposal', $period['classification']);
                $this->assertArrayHasKey('final', $period['classification']);
                $this->assertArrayHasKey('is_published', $period['classification']);

                $seen['proposal'] = $seen['proposal'] || $period['classification']['proposal']['value'] !== null;
                $seen['decision'] = $seen['decision'] || $period['classification']['final'] !== null;
            }
        }

        $this->assertTrue($seen['proposal']);
        $this->assertTrue($seen['decision']);
    }

    // -------------------------------------------------------- 3. a tabela

    #[Test]
    public function the_student_stays_put_while_the_year_scrolls_past(): void
    {
        $screen = $this->screen();

        // Sticky first column and a scrolling container — the table is wide on
        // purpose and the name must never leave the screen (§15).
        $this->assertStringContainsString('sticky left-0', $screen);
        $this->assertStringContainsString('overflow-auto', $screen);

        // …and only that one column is frozen: the «Aluno» heading and the
        // «Aluno» cell of each row, and nothing else (§15).
        $frozenLeft = substr_count($screen, 'sticky left-0') + substr_count($screen, 'sticky top-0 left-0');
        $this->assertSame(2, $frozenLeft, 'só a coluna Aluno fica congelada à esquerda');
    }

    #[Test]
    public function the_headers_are_grouped_and_every_abbreviation_says_what_it_means(): void
    {
        $screen = $this->screen();

        // Two levels: the domain (or the period's synthesis) over its columns…
        $this->assertStringContainsString('scope="colgroup"', $screen);
        $this->assertStringContainsString(':colspan="domainColumns"', $screen);
        $this->assertStringContainsString('Síntese · {{ period.label }}', $screen);

        // …and every short label carries the full one, for the pointer and for
        // a screen reader alike (§14).
        //
        // «Acum.» PASSOU A «Desemp.», e não é uma preferência de abreviatura: a
        // partir do momento em que a avaliação contínua e o desempenho
        // acumulado aparecem no mesmo produto, «Acumulado» sozinho é ambíguo —
        // quem acabou de ler uma pode supor que a outra é o mesmo somado de
        // outra maneira. Ver `ReadingVocabulary`.
        foreach (['Evol.', 'Desemp.', 'Menção', 'Prop.', 'Autoav.'] as $abbreviation) {
            $this->assertStringContainsString($abbreviation, $screen);
        }

        $this->assertStringNotContainsString('Acum.', $screen);
        $this->assertStringContainsString('${ACCUMULATED_LONG} — ${domain.name}', $screen);
        $this->assertStringContainsString(':aria-label="`Menção qualitativa acumulada — ${domain.name}`"', $screen);
        $this->assertStringContainsString(':aria-label="`Autoavaliação global do aluno — ${period.label}`"', $screen);
        $this->assertStringContainsString(':aria-label="`${decision.label} — ${period.label}`"', $screen);
    }

    #[Test]
    public function the_columns_are_counted_from_the_year_and_never_fixed(): void
    {
        $screen = $this->screen();

        // One column per period, an evolution column after each but the first,
        // then the accumulated and its mention. E MAIS NADA: a conclusão do ano
        // saiu do bloco de cada domínio para o bloco que fecha a grelha, e a
        // contagem tem de acompanhar essa mudança — uma largura a mais aqui
        // desalinha todas as colunas à direita dela.
        // E A LEITURA ACUMULADA PODE SAIR DA GRELHA, o que tira duas colunas a
        // cada domínio e uma a cada síntese. As larguras têm de contar com isso,
        // ou o cabeçalho promete mais células do que a linha tem.
        $this->assertStringContainsString('periods.value.length * 2 - 1 + (showAccumulated.value ? 2 : 0)', $screen);
        $this->assertStringContainsString('index === 0 ? 5 : 6', $screen);
        $this->assertStringContainsString('showAccumulated.value ? base : base - 1', $screen);

        // E O BLOCO FINAL CONTA-SE DOS DOMÍNIOS QUE HÁ, nunca de um número
        // escrito à mão: dois por domínio — a média e a menção — e três no
        // global, menos as médias quando os quantitativos estão desligados.
        $this->assertStringContainsString('showQuantitative.value ? 2 : 1', $screen);
        $this->assertStringContainsString('showQuantitative.value ? 3 : 2', $screen);
        $this->assertStringContainsString(
            'domains.value.length * finalDomainColumns.value + finalGlobalColumns.value',
            $screen,
        );
        // Nothing here knows how many periods a year has.
        $this->assertStringNotContainsString('P1</th>', $screen);
        $this->assertStringNotContainsString('1.º Período', $screen);
    }

    #[Test]
    public function a_temporal_unit_is_called_by_the_name_the_school_gave_it(): void
    {
        $screen = $this->screen();

        // «P1» ERA UMA ABREVIATURA INVENTADA POR ESTE ECRÃ, e não correspondia a
        // nada escrito em lado nenhum: uma escola com módulos, ou com três
        // períodos, lia «P1» sem ter dado esse nome a coisa nenhuma. O rótulo é
        // agora o `label` que a própria unidade tem.
        $this->assertStringNotContainsString('function shortPeriod', $screen);
        $this->assertStringNotContainsString('`P${index + 1}`', $screen);
        $this->assertStringContainsString('{{ period.label }} </th>', $screen);
    }

    #[Test]
    public function the_accumulated_column_says_what_it_is_the_performance_of(): void
    {
        $screen = $this->screen();

        // «Desemp.» sozinho podia ser lido como o desempenho DO PERÍODO, que é
        // outro número na mesma linha; e «Acum.» é o nome que a distinção entre
        // as duas leituras mandou abandonar. Nenhum dos dois volta.
        $this->assertStringNotContainsString('Acum.', $screen);
        $this->assertStringNotContainsString('> Desemp. <', $screen);
        $this->assertStringContainsString('{{ ACCUMULATED_SHORT }}', $screen);

        // E a abreviatura nunca é a única informação: o nome inteiro e a
        // explicação continuam no `title` e no texto acessível (§25).
        $this->assertStringContainsString('${ACCUMULATED_LONG} — ${domain.name}. ${ACCUMULATED_EXPLANATION}', $screen);
    }

    #[Test]
    public function every_accumulated_value_opens_the_account_that_produced_it(): void
    {
        $screen = $this->screen();

        // O NÚMERO É A PORTA. Um ícone por célula tornaria a grelha mais pesada
        // do que a explicação que oferece; o valor clicável não custa uma
        // coluna (§5).
        $this->assertStringContainsString('openBreakdown(student, student.periods[lastPeriodIndex], domain.ulid)', $screen);
        $this->assertStringContainsString('openBreakdown(student, period, null)', $screen);
        $this->assertStringContainsString('results/desempenho-acumulado/', $screen);

        // A PEDIDO, E NÃO COM A PÁGINA (§26): o componente do painel recebe uma
        // rota, e é ele que a vai buscar quando alguém a abre.
        $this->assertStringContainsString('<AccumulatedBreakdownPanel', $screen);
        $this->assertStringContainsString(':url="breakdownUrl"', $screen);
    }

    #[Test]
    public function the_year_is_closed_by_the_continuous_reading_and_never_by_the_accumulated(): void
    {
        $screen = $this->screen();

        // O BLOCO FINAL EXISTE PORQUE NENHUMA SÍNTESE RESPONDE PELO ANO: cada
        // uma responde por uma unidade temporal. A média do ano, a proposta
        // formal e a decisão vivem aqui — e o nome do bloco vem do vocabulário
        // partilhado, para que o ecrã e o Excel não lhe chamem coisas
        // diferentes.
        $this->assertStringContainsString('{{ CONTINUOUS_FINAL }}', $screen);
        $this->assertStringContainsString('continuousByEnrollment.get(student.enrollment_id)?.normalized_value', $screen);

        // E A PROPOSTA FORMAL SAI DAQUI. Se um dia esta coluna passar a ler o
        // acumulado, o produto passa a propor um nível a partir do indicador
        // analítico — que é precisamente a troca que as duas leituras existem
        // para tornar impossível.
        $this->assertStringContainsString('continuousByEnrollment.get(student.enrollment_id)!.level!.code', $screen);
        $this->assertStringNotContainsString(
            'accumulated_average"',
            explode('A AVALIAÇÃO CONTÍNUA FINAL', $screen)[1] ?? '',
        );
    }

    /**
     * A GRELHA LÊ-SE POR QUATRO BLOCOS, e é essa a leitura que a reorganização
     * existe para tornar possível: o que aconteceu em cada DOMÍNIO, o que fechou
     * cada UNIDADE TEMPORAL, e em que é que o ano deu.
     */
    #[Test]
    public function the_grid_is_read_as_four_structural_blocks(): void
    {
        $screen = $this->screen();

        $this->assertStringContainsString('Resultados por domínio', $screen);
        $this->assertStringContainsString('Síntese · {{ period.label }}', $screen);
        $this->assertStringContainsString('{{ CONTINUOUS_FINAL }}', $screen);

        // TRÊS LINHAS DE CABEÇALHO, e não duas: os grandes blocos, os grupos
        // dentro deles (cada domínio, e cada domínio outra vez no bloco final)
        // e as colunas. Sem a linha do meio, a conclusão do ano de Oralidade
        // ficaria debaixo de uma abreviatura em vez do nome do domínio.
        $this->assertStringContainsString('rowspan="3"', $screen);
        $this->assertStringContainsString('sticky top-8', $screen);
        $this->assertStringContainsString('sticky top-16', $screen);
        $this->assertStringNotContainsString('top-[33px]', $screen);
    }

    /**
     * A CONCLUSÃO DO ANO DE CADA DOMÍNIO VIVE NO BLOCO FINAL, e não escondida
     * dentro do bloco do domínio, encostada ao desempenho acumulado — que é a
     * OUTRA leitura do ano (§6, §14).
     */
    #[Test]
    public function every_domain_closes_the_year_inside_the_final_block(): void
    {
        $screen = $this->screen();

        // O bloco final repete os domínios, e cada um leva a média e a menção.
        $this->assertStringContainsString(':key="`sub-final-${domain.id}`"', $screen);
        $this->assertStringContainsString(':key="`${student.enrollment_id}-final-${domain.id}`"', $screen);
        $this->assertStringContainsString('{{ FINAL_AVERAGE }}', $screen);
        $this->assertStringContainsString('{{ FINAL_MENTION }}', $screen);

        // E O GLOBAL FECHA-O UMA VEZ SÓ. Duas médias do ano no mesmo ecrã
        // seriam duas respostas à mesma pergunta (§15).
        $this->assertSame(1, substr_count($screen, '> Global </th>'));
        $this->assertSame(
            1,
            substr_count($screen, 'pct(continuousByEnrollment.get(student.enrollment_id)?.normalized_value ?? null)'),
        );

        // «FINAL» E «APREC.» ERAM O PROBLEMA. Uma coluna chamada só «Final»,
        // dentro do bloco de um domínio, lia-se como «o último valor» e não
        // como a média ponderada final do ano naquele domínio (§9, §10).
        $this->assertStringNotContainsString('> Final </th>', $screen);
        // …e a comparação é com o RÓTULO, não com a palavra: os comentários que
        // explicam a mudança nomeiam as duas colunas antigas, e têm de o poder
        // fazer.
        $this->assertStringNotContainsString('> Aprec. </th>', $screen);
    }

    /**
     * A HIERARQUIA DO TRAÇO ESTAVA INVERTIDA: o mais forte era um azul dentro
     * do bloco de um domínio, a separar duas colunas internas, e as fronteiras
     * entre os grandes blocos eram mais fracas do que ele (§16, §17, §18).
     */
    #[Test]
    public function the_firmest_rule_is_the_one_between_the_big_blocks(): void
    {
        $screen = $this->screen();

        // Três forças, por ordem: bloco, grupo, coluna.
        $this->assertStringContainsString("const BLOCK_RULE = 'border-l-4 border-l-foreground/25'", $screen);
        $this->assertStringContainsString("const GROUP_RULE = 'border-l-2 border-l-border'", $screen);

        // E NENHUMA DELAS É AZUL. O azul do produto é ênfase FUNCIONAL — o
        // fundo do bloco que carrega o indicador formal — e nunca um divisor
        // estrutural: uma barra azul a separar duas colunas de um domínio
        // gritava mais alto do que a fronteira entre os blocos (§18).
        $this->assertStringNotContainsString('border-l-primary', $screen);
        $this->assertStringNotContainsString('border-l-4 border-l-border', $screen);

        // As sínteses ficaram em tons neutros: enquanto levavam a mesma tinta
        // do bloco final, os dois tinham o mesmo peso e o final não se
        // distinguia (§19).
        $this->assertSame(0, substr_count($screen, 'bg-primary/5 px-2 py-1 text-center text-xs font-medium">'));
        $this->assertStringContainsString('bg-muted/40 px-2 py-1', $screen);
    }

    /**
     * COM OS QUANTITATIVOS DESLIGADOS, A PALAVRA — nunca o número disfarçado de
     * menção. Um «3» onde devia estar «Suficiente» não é uma pauta sem números
     * (§12, §34).
     */
    #[Test]
    public function switching_the_numbers_off_leaves_the_words_and_never_the_codes(): void
    {
        $screen = $this->screen();

        foreach ([
            'showQuantitative ? domainFinalAppreciation(student, domain.id)!.level?.code : domainFinalAppreciation(student, domain.id)!.level?.label',
            'showQuantitative ? continuousByEnrollment.get(student.enrollment_id)!.level!.code : continuousByEnrollment.get(student.enrollment_id)!.level!.label',
            'showQuantitative ? continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.code : continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.label',
        ] as $pair) {
            $this->assertStringContainsString($pair, $screen);
        }
    }

    #[Test]
    public function the_final_appreciation_of_a_domain_is_the_door_to_deciding_it(): void
    {
        $screen = $this->screen();

        // O VALOR É A PORTA (§5). Um botão «alterar» em cada uma de cento e
        // cinquenta células transformaria a grelha num painel de controlo.
        $this->assertStringContainsString('openFinalDecision(student, domain)', $screen);
        $this->assertStringContainsString('<FinalDomainDecisionDialog', $screen);

        // ESCREVE NO ÂMBITO DO ANO, e a unidade não vai no endereço: é o
        // servidor que a deriva, pela mesma função que a leitura usa.
        $this->assertStringContainsString('/results/quadro-sintese/dominios/', $screen);
        $this->assertStringNotContainsString('/results/quadro-sintese/dominios/${unit', $screen);

        // SEM AUTORIZAÇÃO NÃO HÁ BOTÃO, e o valor lê-se na mesma — esconder a
        // acção é apresentação; quem impede é a rota (§8.2).
        $this->assertStringContainsString("canDecideDomains && domainFinalAppreciation(student, domain.id) ? 'button' : 'span'", $screen);
    }

    #[Test]
    public function the_screen_is_told_whether_this_teacher_may_conclude_a_domain(): void
    {
        $teacher = $this->seedDemo();
        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertTrue($props['canDecideDomains']);
    }

    #[Test]
    public function the_level_picker_is_shared_with_the_pauta_rather_than_copied(): void
    {
        // Passou a haver DOIS sítios onde um domínio se decide — a Pauta, sobre
        // um período, e o Quadro Síntese, sobre o ano. A lista de menções e o
        // «voltar à proposta» são exatamente os mesmos; duas cópias divergiriam
        // na primeira correção feita só de um lado.
        foreach ([
            'resources/js/components/evaluation-sheets/EvaluationSheetDomainDecisionDialog.vue',
            'resources/js/components/results/FinalDomainDecisionDialog.vue',
        ] as $dialog) {
            $source = (string) file_get_contents(base_path($dialog));

            $this->assertStringContainsString('DomainAppreciationPicker', $source, "{$dialog} não reutiliza o seletor.");
            // E nenhum deles voltou a escrever a lista à mão.
            $this->assertStringNotContainsString('v-for="level in decision.levels"', $source);
        }
    }

    #[Test]
    public function the_self_assessment_marker_says_whose_voice_it_is(): void
    {
        $screen = $this->screen();

        // «A3» OBRIGAVA A DECIFRAR — o «A» podia ser um nível, uma alínea ou um
        // aviso, e a legenda que o explicava está no fundo da página, longe de
        // quem está a ler a célula. O dado por baixo é exatamente o mesmo.
        $this->assertStringContainsString('>Auto {{ domainCell(period, domain.id)?.self_assessment?.code }}</sup>', $screen);
        $this->assertStringContainsString('Autoavaliação do aluno: ${level.code} — ${level.label}', $screen);
    }

    #[Test]
    public function each_domain_is_a_block_the_eye_can_find_without_tracing_columns(): void
    {
        $screen = $this->screen();

        // A firmer rule where each block begins, in the heading and in every row
        // alike, so the grouping is read down the table and not only across its
        // top (§12).
        $this->assertStringContainsString("const GROUP_RULE = 'border-l-2 border-l-border'", $screen);
        $this->assertStringContainsString(':class="index === 0 ? GROUP_RULE : \'\'"', $screen);

        // The domain's name is the most evident thing in the heading, and it
        // carries the grouping on its own: the tones alternate merely so the eye
        // finds an edge, and are not what the block depends on (§17).
        $this->assertStringContainsString('domainTone(domainIndex)', $screen);
        $this->assertStringContainsString("index % 2 === 0 ? 'bg-muted' : 'bg-muted/60'", $screen);
    }

    #[Test]
    public function the_synthesis_is_separated_more_firmly_than_a_domain_is(): void
    {
        $screen = $this->screen();

        // A heavier rule where each big block begins — a síntese is never read
        // as one more domain, and o bloco final nunca como mais uma síntese
        // (§13, §17). O traço é o MESMO nas três fronteiras: é isso que faz
        // delas uma hierarquia e não três decisões avulsas.
        $this->assertStringContainsString(':class="BLOCK_RULE"', $screen);
        $this->assertStringContainsString('Síntese · {{ period.label }}', $screen);

        // E A SÍNTESE É NEUTRA. A tinta do produto está reservada ao bloco que
        // carrega o indicador formal; enquanto as sínteses a partilhavam, os
        // dois tinham o mesmo peso e a hierarquia entre eles não existia (§19).
        //
        // A COMPARAÇÃO É COM A CÉLULA, e não com o ficheiro: a asserção antiga
        // procurava «bg-primary/10» em lado nenhum em particular, e continuou a
        // passar depois de essa tinta ter mudado de bloco. Um teste que passa
        // seja onde for que a string esteja não afirma nada sobre o sítio.
        $sintese = $this->headerCell($screen, 'Síntese · {{ period.label }}');
        $this->assertStringContainsString('bg-muted/70', $sintese);
        $this->assertStringNotContainsString('bg-primary', $sintese);

        $final = $this->headerCell($screen, '{{ CONTINUOUS_FINAL }}');
        $this->assertStringContainsString('bg-primary/10', $final);
    }

    /**
     * A célula de cabeçalho que termina num dado rótulo — do `<th` que a abre
     * até ao texto que ela mostra.
     *
     * Existe porque uma asserção sobre a aparência de UMA célula não se pode
     * fazer sobre o ficheiro inteiro: a mesma classe noutro sítio faria o teste
     * passar sem que a célula a tivesse.
     */
    private function headerCell(string $screen, string $label): string
    {
        $end = strpos($screen, $label);
        $this->assertNotFalse($end, "«{$label}» não está no ecrã.");

        $start = strrpos(substr($screen, 0, $end), '<th');
        $this->assertNotFalse($start, "«{$label}» não está dentro de uma célula de cabeçalho.");

        return substr($screen, $start, $end - $start);
    }

    #[Test]
    public function the_organisational_tones_never_reach_a_data_cell(): void
    {
        $screen = $this->screen();

        // The trend tint owns the only colour in a data cell (§15): the tones
        // that merely group the columns live in the headings, and the body keeps
        // nothing but the accumulated column's own faint cue.
        $body = explode('<tbody', $screen)[1] ?? '';

        $this->assertStringNotContainsString('bg-muted"', $body);
        $this->assertStringNotContainsString('bg-muted/60', $body);
        $this->assertStringNotContainsString('bg-primary/10', $body);
        $this->assertStringNotContainsString('bg-primary/5', $body);

        // …and the trend is still the green and the red it was.
        $this->assertStringContainsString('TREND_SHAPE, trendClasses(', $body);
    }

    #[Test]
    public function trend_and_performance_are_drawn_with_different_ink(): void
    {
        $screen = $this->screen();

        // Both read from the canonical modules — no colour is decided here.
        $this->assertStringContainsString("from '@/lib/results'", $screen);
        $this->assertStringContainsString("from '@/lib/qualitativeTone'", $screen);
        $this->assertStringContainsString('qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)]', $screen);
        $this->assertStringNotContainsString('bg-emerald-50', explode('<template>', $screen)[0]);

        // A DECISÃO É O MAIS FORTE DOS TRÊS JUÍZOS, e o único a negrito. São
        // DUAS ocorrências porque há duas decisões — a de cada unidade temporal
        // e a do ano, no bloco «Avaliação Contínua Final» —, e não porque a
        // regra tenha afrouxado: a proposta continua em itálico e a
        // autoavaliação a meia-voz. Se este número subir sem uma coluna de
        // decisão nova, alguém pôs a negrito algo que não é uma decisão do
        // professor.
        $this->assertSame(2, substr_count($screen, 'font-bold'));
    }

    #[Test]
    public function nothing_is_recomputed_in_the_browser(): void
    {
        $screen = $this->screen();

        // No averaging, no summing, no dividing: the read model already did it.
        foreach (['.reduce(', 'Math.round', '/ periods', '* 100'] as $arithmetic) {
            $this->assertStringNotContainsString($arithmetic, $screen);
        }
    }

    // ----------------------------------------------------- 4. performance

    #[Test]
    public function the_page_costs_the_same_whether_the_class_has_six_students_or_nine(): void
    {
        $teacher = $this->seedDemo();
        $classUlid = $this->classUlid($teacher);

        $before = $this->queriesToOpen($teacher, $classUlid);

        $this->asTenant($teacher, function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $organization = $teacher->personalOrganization();

            for ($number = 90; $number < 93; $number++) {
                $student = Student::factory()->recycle($organization)->create();
                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $organization->getKey(),
                    'display_name' => "Aluno de Teste {$number}",
                ]);
                Enrollment::factory()->recycle($organization)->create([
                    'class_id' => $class->id,
                    'student_id' => $student->getKey(),
                    'class_number' => $number,
                    'enrolled_on' => now()->subMonths(9)->toDateString(),
                ]);
            }
        });

        $after = $this->queriesToOpen($teacher, $classUlid);

        // One call to the read model, and nothing per student: the cost does not
        // track the roll. Three more students would add at least three queries
        // to a page with an N+1 in it, and a real class has thirty (§17).
        $this->assertLessThanOrEqual(
            $before,
            $after,
            "as consultas passaram de {$before} para {$after} ao acrescentar 3 alunos",
        );
    }

    private function queriesToOpen(User $teacher, string $classUlid): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->open($teacher, $classUlid)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
