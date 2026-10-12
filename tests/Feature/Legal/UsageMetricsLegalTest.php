<?php

namespace Tests\Feature\Legal;

use App\Support\Legal\LegalDocuments;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A POLÍTICA DESCREVE AS MÉTRICAS DE UTILIZAÇÃO COMO O CÓDIGO AS GRAVA (0.163.0).
 *
 * O painel interno `/admin/usage` passou a existir, e com ele um tratamento
 * novo dos dados da conta. Estes testes garantem que a Política o diz — a
 * finalidade, o que é e o que não é registado, quem vê, o fundamento e o
 * prazo — e que o prazo publicado é o que `usage:prune` executa.
 */
class UsageMetricsLegalTest extends TestCase
{
    /** @return list<string> */
    private function sectionBody(string $heading): array
    {
        return collect(LegalDocuments::privacy()['sections'])
            ->firstWhere('heading', $heading)['body'] ?? [];
    }

    #[Test]
    public function the_policy_takes_effect_with_this_release(): void
    {
        $this->assertSame('2026-10-12', LegalDocuments::privacy()['effective_from']);
    }

    #[Test]
    public function the_usage_metrics_section_states_what_is_and_is_not_recorded(): void
    {
        $text = mb_strtolower(implode(' ', $this->sectionBody('Métricas de utilização do serviço')));

        $this->assertNotSame('', $text, 'A Política não tem a secção «Métricas de utilização do serviço».');

        // O que é registado.
        $this->assertStringContainsString('que funcionalidade foi aberta', $text);
        $this->assertStringContainsString('que tipo de ação foi concluída', $text);
        $this->assertStringContainsString('lista fechada', $text);
        $this->assertStringContainsString('quantos elementos foram afetados', $text);

        // O que não é.
        foreach (['conteúdo do que escreve', 'nomes ou identificadores de alunos', 'classificações', 'termos de pesquisa', 'mensagens de erro', 'gravações da sessão', 'endereço ip', 'ferramentas de análise de terceiros'] as $excluded) {
            $this->assertStringContainsString($excluded, $text, "A secção não diz que «{$excluded}» fica de fora.");
        }

        // O tempo de sessão não é apresentado como utilização.
        $this->assertStringContainsString('não medimos o tempo que passa na aplicação', $text);
    }

    #[Test]
    public function the_signals_are_described_as_suggestions_without_effects(): void
    {
        $text = mb_strtolower(implode(' ', $this->sectionBody('Métricas de utilização do serviço')));

        $this->assertStringContainsString('não são diagnósticos nem avaliações', $text);
        $this->assertStringContainsString('não produzem qualquer efeito na sua conta', $text);
        $this->assertStringContainsString('não desencadeiam mensagens automáticas', $text);
        $this->assertStringContainsString('não há decisões automatizadas', $text);
    }

    #[Test]
    public function access_is_limited_to_authorised_staff_and_never_to_the_organization(): void
    {
        $text = mb_strtolower(implode(' ', $this->sectionBody('Métricas de utilização do serviço')));

        $this->assertStringContainsString('expressamente autorizadas', $text);
        $this->assertStringContainsString('não são mostrados a colegas', $text);
        $this->assertStringContainsString('quem administre a organização', $text);
    }

    #[Test]
    public function the_legal_basis_is_a_weighed_legitimate_interest_with_a_right_to_object(): void
    {
        $text = implode(' ', $this->sectionBody('Métricas de utilização do serviço'));

        $this->assertStringContainsString('interesse legítimo', $text);
        $this->assertStringContainsString('alínea f)', $text);
        $this->assertStringContainsString('Ponderámos este interesse', $text);
        $this->assertStringContainsString('Pode opor-se a este tratamento', $text);

        $bases = implode(' ', $this->sectionBody('Com que fundamento tratamos os dados da sua conta'));
        $this->assertStringContainsString('medir a utilização do serviço', $bases);
    }

    #[Test]
    public function the_published_retention_is_the_one_the_prune_command_enforces(): void
    {
        config(['retention.usage_metrics_days' => 200]);

        $section = implode(' ', $this->sectionBody('Métricas de utilização do serviço'));
        $retention = implode(' ', $this->sectionBody('Durante quanto tempo'));

        $this->assertStringContainsString('ao fim de 200 dias', $section);
        $this->assertStringContainsString('Métricas de utilização do serviço: eliminadas por uma rotina diária ao fim de 200 dias', $retention);
        $this->assertStringContainsString('As de uma conta encerrada são eliminadas no fim do período de recuperação', $retention);
    }

    #[Test]
    public function the_cookie_section_still_holds(): void
    {
        // As métricas vivem no servidor: nenhum cookie, nenhum script de
        // terceiros. A secção de cookies continua verdadeira sem mudar.
        $text = mb_strtolower(implode(' ', $this->sectionBody('Cookies e armazenamento no navegador')));

        $this->assertStringContainsString('não há cookies de publicidade, de marketing ou de análise de tráfego', $text);
        $this->assertStringContainsString('não é carregado qualquer serviço de terceiros no seu navegador', $text);
    }
}
