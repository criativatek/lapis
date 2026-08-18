<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportStatus;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 *
 * Share the organization with `->recycle($organization)`, as the other
 * factories here expect.
 *
 * Defaults to a class report, because that is the shape the CHECK constraint
 * is strictest about and the one most tests want. `finalized()` goes through a
 * real document rather than flipping a status, so a factory can never produce
 * the state the schema forbids.
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => ReportType::SchoolClass,
            'status' => ReportStatus::Draft,
            'title' => 'Relatório de turma',
            'tone' => ReportTone::Objective,
            'class_id' => SchoolClass::factory(),
            'scope_kind' => ReportScopeKind::Period,
            'scope_label' => '1.º Período',
            'created_by' => User::factory(),
        ];
    }

    public function student(): static
    {
        return $this->state(fn () => [
            'type' => ReportType::Student,
            'title' => 'Relatório individual',
        ]);
    }

    public function records(): static
    {
        return $this->state(fn () => [
            'type' => ReportType::Records,
            'title' => 'Relatório por registos',
            'class_id' => null,
            'scope_kind' => ReportScopeKind::DateRange,
        ]);
    }

    public function school(): static
    {
        return $this->state(fn () => [
            'type' => ReportType::School,
            'title' => 'Relatório de escola',
            'class_id' => null,
            'scope_kind' => ReportScopeKind::Year,
        ]);
    }

    /**
     * A finalized report, with a document that actually hashes to what is
     * stored — so `isIntact()` is true and the CHECK constraint is satisfied.
     *
     * @param  array<string, mixed>|null  $document
     */
    public function finalized(?array $document = null): static
    {
        return $this->state(function () use ($document) {
            $document ??= [
                'version' => Report::CURRENT_DOCUMENT_VERSION,
                'sections' => [],
            ];

            return [
                'status' => ReportStatus::Finalized,
                'document' => $document,
                'document_version' => Report::CURRENT_DOCUMENT_VERSION,
                'document_hash' => Report::hashFor($document),
                'finalized_at' => now(),
            ];
        });
    }
}
