<?php

namespace App\Services\Usage\Insights;

use App\Support\Usage\UsageSegment;
use Carbon\CarbonImmutable;

/**
 * Os filtros de um pedido ao painel, já resolvidos e imutáveis.
 *
 * `from` e `to` são dias (meias-noites locais). `from` já vem ajustado ao
 * limite de conservação; `adjustedToRetention` diz se isso aconteceu, para o
 * painel o poder dizer.
 */
final readonly class UsageFilters
{
    public const PERIOD_CUSTOM = 'custom';

    public const PRESETS = ['7', '30', '90'];

    public const DEFAULT_PERIOD = '30';

    public const MAX_DAYS = 366;

    public function __construct(
        public string $period,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $today,
        public bool $adjustedToRetention,
        public ?string $plan,
        public ?string $organization,
        public UsageSegment $segment,
        public string $search = '',
        public ?string $signal = null,
        public ?string $sort = null,
        public string $age = 'all',
        public int $page = 1,
    ) {}

    /**
     * Um período predefinido, para usos fora de um pedido (testes, comandos).
     */
    public static function preset(int $days, ?CarbonImmutable $today = null, UsageSegment $segment = UsageSegment::Real): self
    {
        $today ??= UsageDates::today();

        return new self(
            period: (string) $days,
            from: $today->subDays($days - 1),
            to: $today,
            today: $today,
            adjustedToRetention: false,
            plan: null,
            organization: null,
            segment: $segment,
        );
    }

    public function days(): int
    {
        return UsageDates::countInclusive($this->from, $this->to);
    }

    /** O dia a seguir ao fim do período, para intervalos `day < $toExclusive`. */
    public function toExclusive(): CarbonImmutable
    {
        return $this->to->addDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'from' => UsageDates::iso($this->from),
            'to' => UsageDates::iso($this->to),
            'days' => $this->days(),
            'plan' => $this->plan,
            'organization' => $this->organization,
            'segment' => $this->segment->value,
            'adjusted_to_retention' => $this->adjustedToRetention,
            'q' => $this->search,
            'signal' => $this->signal,
            'sort' => $this->sort,
            'age' => $this->age,
        ];
    }
}
