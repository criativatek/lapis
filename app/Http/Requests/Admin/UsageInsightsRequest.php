<?php

namespace App\Http\Requests\Admin;

use App\Services\Usage\Insights\FollowUpSignals;
use App\Services\Usage\Insights\UsageDates;
use App\Services\Usage\Insights\UsageFilters;
use App\Services\Usage\Insights\UsageRanking;
use App\Services\Usage\Insights\UsageUserDirectory;
use App\Support\Retention\RetentionPolicy;
use App\Support\Usage\UsageSegment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Os filtros do painel de utilização (docs/usage-insights.md, «Filtros»).
 *
 * `authorize()` é verdadeiro porque a rota já passa pelo middleware
 * `usage-insights`; autorizar duas vezes em sítios diferentes é como duas
 * regras deixam de concordar.
 */
class UsageInsightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in([...UsageFilters::PRESETS, UsageFilters::PERIOD_CUSTOM])],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => [
                'required_if:period,custom', 'nullable', 'date_format:Y-m-d',
                'before_or_equal:'.UsageDates::iso(UsageDates::today()),
                'after_or_equal:'.UsageDates::iso($this->retentionCutoff()),
            ],
            'plan' => ['nullable', 'string', Rule::exists('plans', 'key')],
            'organization' => ['nullable', 'string', Rule::exists('organizations', 'ulid')],
            'segment' => ['nullable', Rule::enum(UsageSegment::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'signal' => ['nullable', Rule::in(FollowUpSignals::KEYS)],
            'sort' => ['nullable', Rule::in($this->sortValues())],
            'age' => ['nullable', Rule::in(UsageRanking::AGE_VALUES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required_if' => 'Indique o dia de início do intervalo.',
            'to.required_if' => 'Indique o dia de fim do intervalo.',
            'to.before_or_equal' => 'O intervalo não pode acabar no futuro.',
            'to.after_or_equal' => 'O intervalo tem de acabar dentro do prazo de conservação dos dados.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('period') !== UsageFilters::PERIOD_CUSTOM || $validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }

                $from = UsageDates::day((string) $this->input('from'));
                $to = UsageDates::day((string) $this->input('to'));

                if ($from->greaterThan($to)) {
                    $validator->errors()->add('from', 'O dia de início não pode ser depois do dia de fim.');

                    return;
                }

                if (UsageDates::countInclusive($from, $to) > UsageFilters::MAX_DAYS) {
                    $validator->errors()->add('to', 'O intervalo não pode ter mais de '.UsageFilters::MAX_DAYS.' dias.');
                }
            },
        ];
    }

    public function filters(): UsageFilters
    {
        $today = UsageDates::today();
        $period = (string) ($this->validated('period') ?? UsageFilters::DEFAULT_PERIOD);

        if ($period === UsageFilters::PERIOD_CUSTOM) {
            $from = UsageDates::day((string) $this->validated('from'));
            $to = UsageDates::day((string) $this->validated('to'));
        } else {
            $to = $today;
            $from = $today->subDays(((int) $period) - 1);
        }

        // `from` nunca antes do que a conservação ainda guarda: o que está antes
        // foi apagado, e mostrá-lo como zeros seria mentir.
        $cutoff = $this->retentionCutoff();
        $adjusted = $from->lessThan($cutoff);
        if ($adjusted) {
            $from = $cutoff;
        }

        $segment = $this->validated('segment');
        $sorts = $this->sortValues();
        $sort = $this->validated('sort');

        return new UsageFilters(
            period: $period,
            from: $from,
            to: $to,
            today: $today,
            adjustedToRetention: $adjusted,
            plan: $this->validated('plan') ?: null,
            organization: $this->validated('organization') ?: null,
            segment: $segment === null ? UsageSegment::Real : UsageSegment::from((string) $segment),
            search: trim((string) ($this->validated('q') ?? '')),
            signal: $this->validated('signal') ?: null,
            sort: is_string($sort) && in_array($sort, $sorts, true) ? $sort : $sorts[0],
            age: (string) ($this->validated('age') ?? 'all'),
            page: (int) ($this->validated('page') ?? 1),
        );
    }

    /**
     * Os valores de `sort` que a página aceita; o primeiro é a omissão.
     *
     * @return list<string>
     */
    private function sortValues(): array
    {
        return $this->routeIs('admin.usage.ranking')
            ? UsageRanking::SORT_VALUES
            : UsageUserDirectory::SORT_VALUES;
    }

    private function retentionCutoff(): CarbonImmutable
    {
        return UsageDates::today()->subDays(app(RetentionPolicy::class)->usageMetricsDays());
    }
}
