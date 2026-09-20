<?php

namespace Tests\Support\Interventions;

use App\Models\CatalogueFamily;
use App\Models\EvaluationAdaptationCode;
use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\LegalMappingMode;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use App\Support\Interventions\InterventionLegalFramework;
use App\Support\Interventions\LegalMapping;
use App\Support\Interventions\LegalReference;
use App\Support\Interventions\LegalReferenceStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A legal framework that exists only in the test suite.
 *
 * It is here so the versioning claims can be PROVEN rather than asserted in a
 * comment — that a later diploma may move a measure between levels, rename one,
 * revoke another, introduce a family the current catalogue has no item for, and
 * that none of it touches a record made under the previous regime.
 *
 * It ships in `tests/` on purpose. Putting a speculative framework in `app/`,
 * even switched off, is one config mistake away from showing a teacher a law
 * that does not exist. Nothing in the application can reach this class.
 */
final class FictitiousLegalFramework implements InterventionLegalFramework
{
    /**
     * @param  array<string, SupportMeasureLevel|null>  $levels  measure code => level under this version; an explicit null revokes it
     * @param  array<string, string>  $labels  measure code => designation this version uses
     * @param  array<string, CatalogueFamily>  $families  intervention type value => family under this version
     */
    public function __construct(
        private string $code = 'xx-fictitious-v1',
        private string $jurisdiction = 'XX',
        private LegalFrameworkStatus $status = LegalFrameworkStatus::Active,
        private ?CarbonInterface $validFrom = null,
        private ?CarbonInterface $validUntil = null,
        private array $levels = [],
        private array $labels = [],
        private array $families = [],
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function title(): string
    {
        return 'Regime fictício de teste';
    }

    public function legalReference(): string
    {
        return 'Diploma fictício n.º 1/'.($this->validFrom?->year ?? 2000);
    }

    public function jurisdiction(): ?string
    {
        return $this->jurisdiction;
    }

    public function validFrom(): ?CarbonInterface
    {
        return $this->validFrom;
    }

    public function validUntil(): ?CarbonInterface
    {
        return $this->validUntil;
    }

    public function status(): LegalFrameworkStatus
    {
        return $this->status;
    }

    /**
     * In force between validFrom and validUntil, both open-ended when null —
     * the behaviour a real versioned framework has, so the resolver is
     * exercised against something that actually ends.
     */
    public function coversDate(CarbonInterface $date): bool
    {
        $immutable = CarbonImmutable::parse($date->toDateString());

        if ($this->validFrom !== null && $immutable->lessThan(CarbonImmutable::parse($this->validFrom->toDateString()))) {
            return false;
        }

        return $this->validUntil === null
            || $immutable->lessThanOrEqualTo(CarbonImmutable::parse($this->validUntil->toDateString()));
    }

    public function hasLegalTaxonomy(): bool
    {
        return true;
    }

    public function mappingFor(InterventionType $type): LegalMapping
    {
        $measure = SupportMeasureCode::tryFrom($type->value);

        if ($measure === null || $this->levelFor($measure) === null) {
            return LegalMapping::none();
        }

        return LegalMapping::direct($measure, $this->levelFor($measure));
    }

    public function familyFor(InterventionType $type): CatalogueFamily
    {
        if (isset($this->families[$type->value])) {
            return $this->families[$type->value];
        }

        return match ($this->mappingFor($type)->mode) {
            LegalMappingMode::Direct => CatalogueFamily::LegalMeasure,
            LegalMappingMode::EvaluationOnly => CatalogueFamily::EvaluationAdaptation,
            LegalMappingMode::Contextual, LegalMappingMode::None => CatalogueFamily::PedagogicalStrategy,
        };
    }

    /**
     * Null means "this version does not name this measure" — either it was
     * revoked, or it never existed here. Absence is the answer, not a gap.
     */
    public function levelFor(SupportMeasureCode $measure): ?SupportMeasureLevel
    {
        return array_key_exists($measure->value, $this->levels)
            ? $this->levels[$measure->value]
            : null;
    }

    public function legalReferenceFor(SupportMeasureCode $measure): ?LegalReference
    {
        if (! array_key_exists($measure->value, $this->levels)) {
            return null;
        }

        $designation = $this->labels[$measure->value] ?? $measure->label();

        if ($this->levels[$measure->value] === null) {
            return new LegalReference('1.º', null, 'a)', $designation, LegalReferenceStatus::Revoked);
        }

        return new LegalReference('1.º', null, 'a)', $designation);
    }

    public function supportMeasureLevels(): array
    {
        return array_map(function (SupportMeasureLevel $level) {
            $measures = array_values(array_filter(
                SupportMeasureCode::cases(),
                fn (SupportMeasureCode $measure) => $this->levelFor($measure) === $level
                    && ($this->legalReferenceFor($measure)?->status->isSelectable() ?? false),
            ));

            return [
                'value' => $level->value,
                'label' => $level->label(),
                'measures' => array_map(fn (SupportMeasureCode $measure) => [
                    'value' => $measure->value,
                    'label' => $this->labels[$measure->value] ?? $measure->label(),
                ], $measures),
            ];
        }, SupportMeasureLevel::cases());
    }

    public function evaluationAdaptations(): array
    {
        return array_map(
            fn (EvaluationAdaptationCode $code) => ['value' => $code->value, 'label' => $code->label()],
            EvaluationAdaptationCode::cases(),
        );
    }
}
