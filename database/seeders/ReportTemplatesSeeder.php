<?php

namespace Database\Seeders;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionDefinition;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateKind;
use App\Models\ReportTone;
use App\Models\ReportType;
use Illuminate\Database\Seeder;

/**
 * The templates LÁPIS ships with (§22).
 *
 * ONE PER REPORT TYPE, NOT ONE PER PLAN. A single template can serve Base, Pro
 * and Institucional because SectionPlan filters it through the school's
 * capabilities on the way in: the same «Relatório de turma» produces a
 * descriptive document on Base and an analytical one on Pro, without two rows
 * that would drift apart the first time a section is added. §22 asks for
 * exactly that — «não duplicar desnecessariamente modelos se um mesmo template
 * puder ser filtrado por capabilities».
 *
 * BUILT FROM THE CATALOGUE, never from a hand-written list. A system template
 * that enumerated its own sections would be a second copy of the structure, and
 * the two would disagree the first time a section was added or reworded. What
 * is stored is the catalogue's own order and its own defaults — so these rows
 * say «the standard arrangement» rather than freezing today's version of it.
 *
 * Idempotent — matched on (organization_id, kind, key), which is unique. Their
 * settings ARE refreshed on every run, deliberately: a system template is the
 * product's default and is expected to follow the product. Reports already
 * created are unaffected, because a report keeps its own snapshot (§14).
 */
class ReportTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ReportType::cases() as $type) {
            ReportTemplate::withoutGlobalScope('organization')->updateOrCreate(
                [
                    'organization_id' => null,
                    'kind' => ReportTemplateKind::System,
                    'key' => $type->value.'_standard',
                ],
                [
                    'user_id' => null,
                    'report_type' => $type,
                    'name' => $type->label().' — padrão LÁPIS',
                    'description' => $this->descriptionFor($type),
                    'settings' => [
                        'sections' => $this->sectionsFor($type),
                        // The one tone every plan has. A template may not turn
                        // on a register the school is not entitled to (§28),
                        // and the standard one should not try.
                        'tone' => ReportTone::Objective->value,
                        'options' => [
                            // Never by default, in any shipped template: naming
                            // a minor on a class-wide document is a decision a
                            // teacher makes per report (§28, §57).
                            'name_students' => false,
                        ],
                    ],
                    'is_default' => true,
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * The catalogue's own order and defaults.
     *
     * @return list<array{key: string, included: bool, position: int}>
     */
    protected function sectionsFor(ReportType $type): array
    {
        $position = 0;

        return array_map(function (SectionDefinition $definition) use (&$position): array {
            $position += 10;

            return [
                'key' => $definition->key->value,
                'included' => $definition->defaultIncluded,
                'position' => $position,
            ];
        }, SectionCatalogue::for($type));
    }

    protected function descriptionFor(ReportType $type): string
    {
        return match ($type) {
            ReportType::SchoolClass => 'A estrutura habitual de um relatório de turma: identificação, resultados, evolução e as secções pedagógicas que o plano permitir.',
            ReportType::Student => 'A estrutura habitual de um relatório individual, do desempenho por domínio à síntese final.',
            ReportType::Records => 'Síntese dos registos do período, com a cronologia detalhada disponível como opção.',
            ReportType::School => 'Leitura agregada da escola, com a cobertura dos dados antes dos resultados e as notas de comparabilidade no fim.',
        };
    }
}
