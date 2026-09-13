<?php

namespace App\Http\Requests\Lessons;

use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Os quatro modos do lote (§29), reduzidos a um intervalo ou a uma lista.
 *
 * `today` e `week` não recebem datas do browser por acaso: «hoje» é o dia real
 * no fuso da organização, calculado no servidor, e a semana é derivada da
 * segunda-feira que o ecrã diz estar a apresentar. Aceitar «hoje» como uma data
 * enviada pelo cliente tornaria o relógio do portátil do professor a fonte da
 * verdade sobre o que é hoje.
 */
class BatchTaughtRequest extends FormRequest
{
    /**
     * O tecto do modo «Outro intervalo», em dias.
     *
     * RAZÃO TÉCNICA, e não pedagógica: a pré-visualização enumera cada aula
     * abrangida para poder dizer quantas são e quais ficam de fora, e sem tecto
     * a resposta cresce sem limite com o intervalo pedido — um ano letivo
     * inteiro de várias turmas são milhares de linhas num JSON de confirmação.
     * Noventa e dois dias cobrem qualquer período letivo português com folga,
     * que é o maior intervalo com sentido para esta ação.
     */
    private const MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['today', 'week', 'selection', 'range'])],
            'week' => ['required_if:mode,week', 'nullable', 'date_format:Y-m-d'],
            'from' => ['required_if:mode,range', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:mode,range', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'ulids' => ['required_if:mode,selection', 'nullable', 'array', 'max:500'],
            'ulids.*' => ['string', 'max:26'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('mode') !== 'range') {
                return;
            }

            $from = CarbonImmutable::parse($this->string('from')->toString());
            $to = CarbonImmutable::parse($this->string('to')->toString());

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add('to', __(
                    'O intervalo não pode ser maior do que :days dias.',
                    ['days' => (string) self::MAX_RANGE_DAYS],
                ));
            }
        });
    }

    /**
     * A seleção explícita, ou NULL quando o modo é de intervalo.
     *
     * @return list<string>|null
     */
    public function selectedUlids(): ?array
    {
        if ($this->validated('mode') !== 'selection') {
            return null;
        }

        /** @var list<string> $ulids */
        $ulids = array_values(array_filter(
            (array) $this->validated('ulids', []),
            fn ($value): bool => is_string($value) && $value !== '',
        ));

        return $ulids;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        $timezone = app(CurrentOrganization::class)->get()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($this->validated('mode')) {
            'today' => [$today, $today],
            // A semana APRESENTADA, e não a semana de hoje: o professor que
            // navegou para a semana passada está a falar dessa (§31). O
            // `startOfWeek()` por cima do que chega garante que um cliente que
            // envie uma quarta-feira acaba na mesma semana ISO que o ecrã
            // mostra, de segunda a domingo.
            'week' => [
                $week = CarbonImmutable::parse($this->string('week')->toString(), $timezone)->startOfWeek(),
                $week->endOfWeek()->startOfDay(),
            ],
            'range' => [
                CarbonImmutable::parse($this->string('from')->toString(), $timezone)->startOfDay(),
                CarbonImmutable::parse($this->string('to')->toString(), $timezone)->startOfDay(),
            ],
            default => [$today, $today],
        };
    }
}
