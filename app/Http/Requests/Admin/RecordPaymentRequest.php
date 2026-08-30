<?php

namespace App\Http\Requests\Admin;

use App\Models\CommercialCondition;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A payment an operator says they received.
 *
 * THE AMOUNT IS ENTERED IN EUROS AND STORED IN CENTS, and the conversion
 * happens exactly once, here, in `amountCents()`. An operator types «44,90»
 * because that is what a Portuguese invoice says; the database keeps 4490
 * because money summed as a decimal is money that eventually rounds. Doing the
 * conversion in the controller or the action instead would mean two places that
 * both have to agree about commas, and one of them eventually would not.
 *
 * Authorization is the route's `platform-admin` middleware, not a policy: this
 * is the platform operator acting on any account, so there is no per-tenant
 * ownership question to answer.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isPlatformAdmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Upper bound is a typo guard, not a business limit: nobody pays
            // 100 000 EUR for a teacher's annual subscription, and a stray
            // keystroke that adds three zeros must not silently become revenue.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'currency' => ['required', 'string', 'size:3'],
            // Only two are offerable: a payment being recorded by hand either
            // arrived or is announced. `failed`/`refunded`/`cancelled` are
            // outcomes of a correction, never something typed into a new row.
            'status' => ['required', Rule::in([PaymentStatus::Paid->value, PaymentStatus::Pending->value])],
            // Required precisely when the status claims the money arrived —
            // the same rule the database enforces with a check constraint, said
            // here so the operator gets a field error instead of a 500.
            'paid_at' => ['nullable', 'date', 'required_if:status,'.PaymentStatus::Paid->value],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'provider_reference' => ['nullable', 'string', 'max:191'],
            // The operator states it. It is never derived from `amount`.
            'commercial_condition' => ['nullable', Rule::enum(CommercialCondition::class)],
            // A literal code, stated by the operator on a MANUAL payment.
            // Deliberately NOT resolved against the voucher engine: a manual
            // record is testimony about money that arrived, and inventing a
            // redemption from it would fabricate a contract nobody made. Real
            // redemptions come through the checkout, where the engine decides.
            'voucher_code' => ['nullable', 'string', 'max:60'],
            'period_starts_at' => ['nullable', 'date'],
            'period_ends_at' => ['nullable', 'date', 'after_or_equal:period_starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_at.required_if' => __('Indique a data em que o pagamento foi recebido.'),
            'period_ends_at.after_or_equal' => __('O fim do período não pode ser anterior ao início.'),
            'amount.max' => __('Valor demasiado elevado. Confirme o montante.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        if (is_string($amount)) {
            // «44,90» and «1 234,56» are what an operator types. Normalised
            // before validation so `numeric` sees a number rather than
            // rejecting a perfectly ordinary Portuguese figure.
            $this->merge([
                'amount' => str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim($amount)),
            ]);
        }

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => mb_strtoupper(trim($this->input('currency')))]);
        }
    }

    /**
     * The single conversion from what a person types to what is stored.
     * `round()` before the cast, never a bare `(int)`: 44.90 * 100 is
     * 4489.999… in binary floating point, and truncating it would quietly
     * record one cent less than was paid.
     */
    public function amountCents(): int
    {
        return (int) round(((float) $this->validated('amount')) * 100);
    }
}
