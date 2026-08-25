<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Mês view's optional `?month=YYYY-MM`, shaped like «Aulas e Sumários»'
 * own `?week=YYYY-MM-DD`: absent means «the month this year should open on»,
 * present means exactly that month, and anything else is rejected rather than
 * quietly reinterpreted.
 */
class CalendarMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['month' => ['sometimes', 'date_format:Y-m']];
    }
}
