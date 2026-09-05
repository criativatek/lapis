<?php

namespace Tests\Unit\Import;

use App\Services\Import\Backup\ValidateBackupPayload;
use App\Support\Assessment\SupportedCalculationRules;
use App\Support\Import\Backup\BackupSchemaCompatibility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A IMPORTAÇÃO É O OUTRO CAMINHO PARA UMA VERSÃO ATIVA.
 *
 * `ActivateProfileVersion` recusa ativar um rascunho cuja regra o motor não
 * cumpre — mas uma restauração de backup escreve `status` directamente e cria
 * versões já ativas sem passar por lá. Sem esta guarda, um ficheiro de outra
 * instalação (ou de uma versão futura da aplicação) traria um perfil que a
 * aplicação calcularia por uma regra diferente da que ele diz ter.
 *
 * A linha é rejeitada com motivo, não faz a importação inteira rebentar: é o
 * mesmo comportamento das outras linhas inválidas do ficheiro.
 */
class BackupProfileVersionRuleTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $versionOverrides
     */
    protected function payloadWithVersion(array $versionOverrides): string
    {
        $version = array_merge([
            'ulid' => '01M1SWTDACW2M4QKC49C9EH1FK',
            'profile_ulid' => '01M1SWTDACW2M4QKC49C9EH1FM',
            'version_number' => 1,
            'status' => 'active',
            'is_current' => true,
            'scale' => ['name' => 'Escala 1 a 5', 'kind' => 'level', 'system' => true],
            'domain_weight_mode' => 'must_total_100',
            'period_result_mode' => 'weighted_domain_average',
            'accumulated_mode' => 'all_valid_year_elements',
            'absence_mode' => 'exclude_all_warn',
            'rounding_mode' => 'half_up',
            'rounding_scale' => 0,
            'rounding_stage' => 'final_only',
            'minimum_rules' => null,
            'activated_at' => '2026-09-01T10:00:00+01:00',
            'frozen_at' => '2026-09-01T10:00:00+01:00',
            'superseded_at' => null,
            'change_note' => null,
        ], $versionOverrides);

        return (string) json_encode([
            'schema_version' => BackupSchemaCompatibility::CURRENT,
            'app_version' => '0.130.2',
            'generated_at' => '2026-09-06T00:00:00+01:00',
            'organization' => [
                'ulid' => '01M1SWTDACW2M4QKC49C9EH1FN',
                'name' => 'Escola de Teste',
                'type' => 'institutional',
            ],
            'assessment_profile_versions' => [$version],
        ]);
    }

    /**
     * @return list<array{domain: string, ulid: string|null, reason: string}>
     */
    protected function issuesFor(string $rawJson): array
    {
        return app(ValidateBackupPayload::class)->validate($rawJson)['rowIssues'];
    }

    #[Test]
    public function a_version_whose_rule_the_engine_implements_is_accepted(): void
    {
        $issues = $this->issuesFor($this->payloadWithVersion([]));

        $this->assertSame([], $issues, 'A combinação implementada tem de passar sem reparos.');
    }

    #[Test]
    public function a_version_carrying_a_rule_the_engine_does_not_implement_is_rejected(): void
    {
        $unsupported = [
            'accumulated_mode' => 'last_period_only',
            'period_result_mode' => 'simple_domain_average',
            'rounding_stage' => 'each_domain',
        ];

        foreach ($unsupported as $field => $value) {
            $issues = $this->issuesFor($this->payloadWithVersion([$field => $value]));

            $this->assertNotSame([], $issues, "A linha com {$field} = {$value} tinha de ser rejeitada.");
            $this->assertSame('assessment_profile_versions', $issues[0]['domain']);
            $this->assertStringContainsString(
                $value,
                $issues[0]['reason'],
                'O motivo tem de nomear a regra recusada — quem lê o relatório precisa de saber qual.',
            );
        }
    }

    #[Test]
    public function the_shared_list_is_what_both_paths_ask(): void
    {
        // Uma salvaguarda contra a lista voltar a ser duplicada: se um modo
        // passar a ser implementado, sai de um sítio só.
        $this->assertSame(
            ['period_result_mode', 'accumulated_mode', 'rounding_stage'],
            array_keys(SupportedCalculationRules::RULES),
        );
    }
}
