<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\VoucherCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Comercial > Vouchers — emitir, ver, desactivar.
 *
 * EMITIR É A ÚNICA ESCRITA CRIATIVA, e desactivar a única correcção: um voucher
 * é imutável depois de nascer (ver `App\Models\Voucher`), por isso não há
 * `update` nem `delete` aqui — errou-se, desactiva-se e emite-se outro, com o
 * rasto de quem e quando. A mesma disciplina do dinheiro.
 *
 * O FORMULÁRIO É TIPADO POR FAMÍLIA: cada `benefit_type` exige exactamente os
 * seus campos e recusa os das outras — o espelho da validação do modelo e das
 * CHECK constraints, dito na fronteira onde o operador o lê como mensagem de
 * formulário em vez de exceção.
 *
 * PLATAFORMA, NÃO INQUILINO: auditado com `recordPlatform`, como o resto do
 * que um operador faz fora de qualquer organização.
 */
class AdminVoucherController extends Controller
{
    public function __construct(protected AuditLog $audit) {}

    public function index(): Response
    {
        $now = Carbon::now();

        $vouchers = Voucher::query()
            ->with('plan:id,key,name')
            ->withCount([
                'redemptions as confirmed_count' => fn ($query) => $query->whereNotNull('confirmed_at'),
                'redemptions as reserved_count' => fn ($query) => $query
                    ->whereNull('confirmed_at')
                    ->where('reserved_until', '>=', $now),
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Voucher $voucher): array => [
                'id' => $voucher->getKey(),
                'code' => $voucher->code,
                'label' => $voucher->label,
                'benefitType' => $voucher->benefit_type->value,
                'benefitLabel' => $voucher->benefit_type->label(),
                'benefitSummary' => $this->benefitSummary($voucher),
                'planKey' => $voucher->plan?->key,
                'planName' => $voucher->plan?->name,
                'validFrom' => $voucher->valid_from?->toIso8601String(),
                'validUntil' => $voucher->valid_until?->toIso8601String(),
                'maxRedemptions' => $voucher->max_redemptions,
                'confirmedCount' => (int) $voucher->getAttribute('confirmed_count'),
                'reservedCount' => (int) $voucher->getAttribute('reserved_count'),
                'disabledAt' => $voucher->disabled_at?->toIso8601String(),
                'createdAt' => $voucher->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Vouchers', [
            'vouchers' => $vouchers,
            'benefitTypes' => VoucherBenefitType::options(),
            // Os planos a que um código se pode restringir. «Qualquer plano» é a
            // ausência — plan_id NULL — e não uma linha desta lista.
            'plans' => Plan::query()->orderBy('id')->get(['id', 'key', 'name'])
                ->map(fn (Plan $plan): array => ['id' => $plan->getKey(), 'key' => $plan->key, 'name' => $plan->name]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'benefit_type' => ['required', Rule::enum(VoucherBenefitType::class)],
            // Cada família com os seus campos, e SÓ os seus — o espelho das
            // CHECK constraints, dito como validação de formulário.
            'benefit_amount_cents' => ['required_if:benefit_type,fixed_price', 'prohibited_unless:benefit_type,fixed_price', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'benefit_percent' => ['required_if:benefit_type,percent_discount', 'prohibited_unless:benefit_type,percent_discount', 'nullable', 'integer', 'min:1', 'max:100'],
            'benefit_free_until' => ['required_if:benefit_type,free_until', 'prohibited_unless:benefit_type,free_until', 'nullable', 'date', 'after:today'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // Vazio = gerar. Preenchido = um código de parceiro/campanha, que
            // tem de ter a forma de um código e não pode existir já.
            'code' => ['nullable', 'string', 'max:64'],
        ]);

        $code = trim((string) ($validated['code'] ?? ''));

        if ($code === '') {
            $code = VoucherCode::generate();
        } elseif (! VoucherCode::isWellFormed($code)) {
            return back()->withErrors(['code' => __('Isto não tem a forma de um código: 4 a 32 letras e dígitos.')]);
        } elseif (Voucher::query()->code($code)->exists()) {
            return back()->withErrors(['code' => __('Já existe um voucher com este código.')]);
        }

        $type = VoucherBenefitType::from($validated['benefit_type']);

        $voucher = Voucher::create([
            'code' => $code,
            'label' => $validated['label'],
            'benefit_type' => $type,
            'benefit_amount_cents' => $type === VoucherBenefitType::FixedPrice ? (int) $validated['benefit_amount_cents'] : null,
            'benefit_currency' => $type === VoucherBenefitType::FixedPrice ? (string) config('billing.currency') : null,
            'benefit_percent' => $type === VoucherBenefitType::PercentDiscount ? (int) $validated['benefit_percent'] : null,
            'benefit_free_until' => $type === VoucherBenefitType::FreeUntil ? Carbon::parse((string) $validated['benefit_free_until'])->endOfDay() : null,
            'plan_id' => $validated['plan_id'] ?? null,
            'valid_from' => isset($validated['valid_from']) ? Carbon::parse((string) $validated['valid_from']) : null,
            'valid_until' => isset($validated['valid_until']) ? Carbon::parse((string) $validated['valid_until'])->endOfDay() : null,
            'max_redemptions' => $validated['max_redemptions'] ?? null,
            'created_by' => $request->user()?->getKey(),
        ]);

        $this->audit->recordPlatform('commercial.voucher_issued', $request->user(), sprintf(
            'Voucher %s emitido (%s): %s.',
            $voucher->code,
            $voucher->benefit_type->label(),
            $voucher->label,
        ), [
            'voucher_id' => $voucher->getKey(),
            'code' => $voucher->code,
            'benefit_type' => $voucher->benefit_type->value,
            'plan_id' => $voucher->plan_id,
            'max_redemptions' => $voucher->max_redemptions,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voucher :code emitido.', ['code' => $voucher->code])]);

        return back();
    }

    public function disable(Request $request, Voucher $voucher): RedirectResponse
    {
        if ($voucher->isDisabled()) {
            return back();
        }

        $voucher->disabled_at = Carbon::now();
        $voucher->disabled_by = $request->user()?->getKey();
        $voucher->save();

        $this->audit->recordPlatform('commercial.voucher_disabled', $request->user(), sprintf(
            'Voucher %s desactivado. Os resgates já feitos não são tocados.',
            $voucher->code,
        ), [
            'voucher_id' => $voucher->getKey(),
            'code' => $voucher->code,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Voucher :code desativado.', ['code' => $voucher->code])]);

        return back();
    }

    protected function benefitSummary(Voucher $voucher): string
    {
        return match ($voucher->benefit_type) {
            VoucherBenefitType::FixedPrice => number_format((int) $voucher->benefit_amount_cents / 100, 2, ',', ' ').' '.$voucher->benefit_currency,
            VoucherBenefitType::PercentDiscount => '−'.$voucher->benefit_percent.' %',
            VoucherBenefitType::FreeUntil => __('Gratuito até :date', ['date' => $voucher->benefit_free_until?->format('d/m/Y') ?? '—']),
        };
    }
}
