<?php

namespace Tests\Feature\Legal;

use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A POLÍTICA DESCREVE AS MÉTRICAS DE UTILIZAÇÃO QUANDO — E SÓ QUANDO — ELAS EXISTEM.
 *
 * A recolha (docs/usage-insights.md) está desligada por omissão até à decisão
 * de ativação. Enquanto nunca esteve ativa, a Política fica exatamente como
 * estava. No instante em que é ativada (o primeiro período de
 * `usage_collection_periods`), a secção aparece e esse instante passa a ser a
 * versão em vigor — o aviso não bloqueante volta a aparecer a toda a gente.
 */
class UsageMetricsLegalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-25 09:00:00', 'Europe/Lisbon'));
        DB::table('usage_collection_periods')->delete();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function activateAt(string $instant): void
    {
        DB::table('usage_collection_periods')->insert(['started_at' => $instant, 'ended_at' => null]);
    }

    /** @return list<string> */
    private function sectionBody(string $heading): array
    {
        return collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', $heading)['body'] ?? [];
    }

    private function privacyText(): string
    {
        return (string) json_encode(LegalDocuments::privacy(), JSON_UNESCAPED_UNICODE);
    }

    // --- Antes da ativação --------------------------------------------------

    #[Test]
    public function before_activation_the_policy_is_unchanged(): void
    {
        $this->assertSame('2026-09-15', LegalDocuments::privacy()['effective_from']);
        $this->assertSame([], $this->sectionBody('Métricas de utilização do serviço'));
        $this->assertStringNotContainsString('Métricas de utilização', $this->privacyText());
        $this->assertStringNotContainsString('medir a utilização do serviço', $this->privacyText());
    }

    #[Test]
    public function before_activation_nobody_is_shown_the_notice_again(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['privacy_notice_dismissed_at' => '2026-10-01 12:00:00'])->save();

        $this->assertFalse($user->fresh()->shouldSeePrivacyNotice());
    }

    // --- Depois da ativação -------------------------------------------------

    #[Test]
    public function the_activation_instant_becomes_the_version_in_force(): void
    {
        $this->activateAt('2026-10-20 14:00:00');

        $this->assertSame('2026-10-20', LegalDocuments::privacy()['effective_from']);
    }

    #[Test]
    public function the_notice_comes_back_to_whoever_dismissed_it_before_the_activation_instant(): void
    {
        $this->activateAt('2026-10-20 14:00:00');

        $before = User::factory()->create();
        $before->forceFill(['privacy_notice_dismissed_at' => '2026-10-20 10:00:00'])->save();
        $after = User::factory()->create();
        $after->forceFill(['privacy_notice_dismissed_at' => '2026-10-20 15:00:00'])->save();

        // O mesmo dia, horas diferentes: compara instantes, não só datas.
        $this->assertTrue($before->fresh()->shouldSeePrivacyNotice());
        $this->assertFalse($after->fresh()->shouldSeePrivacyNotice());
    }

    #[Test]
    public function switching_off_and_on_again_does_not_move_the_version(): void
    {
        // A Política já descrevia a recolha: a versão é a da PRIMEIRA ativação.
        DB::table('usage_collection_periods')->insert([
            ['started_at' => '2026-10-20 14:00:00', 'ended_at' => '2026-10-22 09:00:00'],
            ['started_at' => '2026-10-24 08:00:00', 'ended_at' => null],
        ]);

        $this->assertSame('2026-10-20', LegalDocuments::privacy()['effective_from']);
    }

    #[Test]
    public function the_usage_metrics_section_states_what_is_and_is_not_recorded(): void
    {
        $this->activateAt('2026-10-20 14:00:00');
        $text = mb_strtolower(implode(' ', $this->sectionBody('Métricas de utilização do serviço')));

        $this->assertNotSame('', $text);
        $this->assertStringContainsString('que funcionalidade foi aberta', $text);
        $this->assertStringContainsString('que tipo de ação foi concluída', $text);
        $this->assertStringContainsString('lista fechada', $text);
        $this->assertStringContainsString('quantos elementos foram criados, alterados ou ficaram iguais', $text);

        foreach (['conteúdo do que escreve', 'nomes ou identificadores de alunos', 'classificações', 'termos de pesquisa', 'mensagens de erro', 'gravações da sessão', 'endereço ip', 'ferramentas de análise de terceiros'] as $excluded) {
            $this->assertStringContainsString($excluded, $text, "A secção não diz que «{$excluded}» fica de fora.");
        }

        $this->assertStringContainsString('não medimos o tempo que passa na aplicação', $text);
        $this->assertStringContainsString('são contagens, não um registo de tudo o que faz', $text);
    }

    #[Test]
    public function the_ranking_and_the_signals_are_described_as_internal_and_without_effects(): void
    {
        $this->activateAt('2026-10-20 14:00:00');
        $text = mb_strtolower(implode(' ', $this->sectionBody('Métricas de utilização do serviço')));

        $this->assertStringContainsString('ordenação interna das contas pela regularidade de utilização', $text);
        $this->assertStringContainsString('cada um à parte', $text);
        $this->assertStringContainsString('nem os sinais nem essa ordenação são diagnósticos ou avaliações', $text);
        $this->assertStringContainsString('não produzem qualquer efeito na sua conta', $text);
        $this->assertStringContainsString('não desencadeiam mensagens automáticas', $text);
        $this->assertStringContainsString('não há decisões automatizadas', $text);
        $this->assertStringContainsString('expressamente autorizadas', $text);
        $this->assertStringContainsString('quem administre a organização', $text);
    }

    #[Test]
    public function the_legal_basis_is_a_weighed_legitimate_interest_with_a_right_to_object(): void
    {
        $this->activateAt('2026-10-20 14:00:00');
        $text = implode(' ', $this->sectionBody('Métricas de utilização do serviço'));

        $this->assertStringContainsString('interesse legítimo', $text);
        $this->assertStringContainsString('alínea f)', $text);
        $this->assertStringContainsString('Ponderámos este interesse', $text);
        $this->assertStringContainsString('Pode opor-se a este tratamento', $text);
        $this->assertStringContainsString('eliminamos de imediato', $text);

        $bases = implode(' ', $this->sectionBody('Com que fundamento tratamos os dados da sua conta'));
        $this->assertStringContainsString('medir a utilização do serviço', $bases);
    }

    #[Test]
    public function the_published_retention_is_the_one_the_prune_command_enforces(): void
    {
        $this->activateAt('2026-10-20 14:00:00');
        config(['retention.usage_events_days' => 20, 'retention.usage_rollups_days' => 120]);

        $section = implode(' ', $this->sectionBody('Métricas de utilização do serviço'));
        $retention = implode(' ', $this->sectionBody('Durante quanto tempo'));

        $this->assertStringContainsString('ao fim de 20 dias', $section);
        $this->assertStringContainsString('ao fim de 120 dias', $section);
        $this->assertStringContainsString('os registos detalhados são eliminados por uma rotina diária ao fim de 20 dias e as contagens diárias associadas a cada conta ao fim de 120 dias', $retention);

        // O prazo dos eventos nunca é publicado maior do que o dos agregados.
        config(['retention.usage_events_days' => 400]);
        $this->assertStringContainsString('ao fim de 120 dias, e as contagens', implode(' ', $this->sectionBody('Métricas de utilização do serviço')));
    }

    #[Test]
    public function the_cookie_section_still_holds(): void
    {
        $this->activateAt('2026-10-20 14:00:00');
        $text = mb_strtolower(implode(' ', $this->sectionBody('Cookies e armazenamento no navegador')));

        $this->assertStringContainsString('não há cookies de publicidade, de marketing ou de análise de tráfego', $text);
        $this->assertStringContainsString('não é carregado qualquer serviço de terceiros no seu navegador', $text);
    }

    #[Test]
    public function the_privacy_page_reads_the_start_of_collection_once_per_request(): void
    {
        $this->activateAt('2026-10-20 14:00:00');

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'usage_collection_periods')) {
                $reads++;
            }
        });

        $this->get('/privacidade')->assertOk();

        $this->assertSame(1, $reads);
    }
}
