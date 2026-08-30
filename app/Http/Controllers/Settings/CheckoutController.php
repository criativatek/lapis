<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Commercial\RequestBankTransferPayment;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StoreCheckoutRequest;
use App\Mail\BankTransferInstructionsMail;
use App\Models\BillingProfile;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\VoucherBenefitType;
use App\Support\Commercial\CheckoutUnavailable;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\Vouchers;
use App\Support\Commercial\VoucherUnavailable;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Subscrever o Pro por transferência bancária.
 *
 * SÓ O PRO. O Base é gratuito e o Institucional é sob consulta — nenhum tem
 * preço em `config/billing.php`, e é a ausência do preço que os recusa, não uma
 * lista de exclusões escrita aqui a ficar desactualizada.
 *
 * NADA MUDA DE PLANO NESTE CONTROLADOR. Cria-se um pedido pendente e mostra-se
 * o IBAN. Quem activa é um administrador, depois de ver o dinheiro no extrato —
 * e activar continua a ser um acto separado de registar o pagamento, como o
 * domínio comercial exige.
 */
class CheckoutController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        private readonly RequestBankTransferPayment $requests,
        private readonly FounderAvailability $founder,
        private readonly CurrentOrganization $currentOrganization,
        private readonly Vouchers $vouchers,
    ) {}

    public function create(): Response
    {
        $organization = $this->currentOrganization->get();
        Gate::authorize('subscribe', $organization);

        $plan = $this->proPlan();

        try {
            $cents = $this->requests->priceFor($plan);
        } catch (CheckoutUnavailable $exception) {
            abort(404, $exception->getMessage());
        }

        // A referência que o ecrã mostra tem de continuar a valer: fora da
        // janela de transferência, ou sem lugar de fundador a segurá-la, um
        // pedido antigo deixa de ser uma compra a meio e passa a ser um preço
        // que ninguém pode honrar. Ver `validPendingFor()`.
        $pendente = $this->requests->validPendingFor($organization);

        return Inertia::render('settings/Checkout', [
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'price' => $this->money($cents),
            'standardPrice' => $this->money((int) config('billing.prices.'.$plan->key)),
            'isFounderPrice' => $this->founder->isOpen(),
            'founderSeatsRemaining' => $this->founder->remaining(),
            // Repreenchido a partir do que já se sabe, para ninguém reescrever a
            // morada a cada renovação.
            'billing' => $this->existingBilling(),
            'pendingReference' => $pendente?->provider_reference,
            'pendingUlid' => $pendente?->ulid,
            'bankTransferReady' => filled(config('billing.bank_transfer.iban')),
        ]);
    }

    public function store(StoreCheckoutRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $organization = $this->currentOrganization->get();
        Gate::authorize('subscribe', $organization);

        $plan = $this->proPlan();

        // O CÓDIGO, SE VEIO, TEM DE VALER ANTES DE SE EMITIR UMA REFERÊNCIA. A
        // resolução aqui é a conversa (mensagem por caso, no campo do código); a
        // DECISÃO é do `request()`, sob a transação — que revalida sob lock e
        // pode ainda responder que o último lugar do código acabou de ser
        // tomado. Um `free_until` não passa por aqui: não define uma quantia, e
        // resgata-se na página do plano.
        $voucher = null;
        $codigo = $request->validated('voucher_code');

        if (is_string($codigo) && trim($codigo) !== '') {
            $resolution = $this->vouchers->resolve($codigo, $organization, $plan);

            if (! $resolution->isValid()) {
                return back()->withErrors(['voucher_code' => $this->vouchers->messageFor($resolution->outcome)]);
            }

            if ($resolution->voucher?->benefit_type === VoucherBenefitType::FreeUntil) {
                return back()->withErrors(['voucher_code' => __(
                    'Este código dá acesso gratuito até uma data e resgata-se na página do plano, não no checkout.',
                )]);
            }

            $voucher = $resolution->voucher;
        }

        try {
            /** @var array{name: string, tax_number: ?string, address_line1: string, address_line2: ?string, postal_code: string, city: string, country: string, email: string} $dados */
            $dados = $request->safe()->except(['voucher_code']);
            $payment = $this->requests->request($organization, $request->user(), $plan, $dados, $voucher);
        } catch (CheckoutUnavailable $exception) {
            return back()->withErrors(['checkout' => $exception->getMessage()]);
        } catch (VoucherUnavailable $exception) {
            return back()->withErrors(['voucher_code' => $exception->getMessage()]);
        }

        // Falhar a enviar o email não desfaz o pedido: a referência já existe e
        // o ecrã seguinte mostra-a. Só se regista o problema.
        try {
            $destino = BillingProfile::query()->value('email') ?? $request->user()->email;

            Mail::to($destino)->send(new BankTransferInstructionsMail($payment, $plan->name));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return to_route('settings.checkout.show', $payment);
    }

    public function show(SubscriptionPayment $payment): Response
    {
        Gate::authorize('subscribe', $this->currentOrganization->get());

        $expira = $payment->metadata['expires_at'] ?? null;

        return Inertia::render('settings/CheckoutPending', [
            'payment' => [
                'reference' => $payment->provider_reference,
                'amount' => $this->money($payment->amount_cents),
                'status' => $payment->status->value,
                'statusLabel' => $payment->status->label(),
                'expiresAt' => $expira,
                'requestedAt' => $payment->created_at?->toIso8601String(),
            ],
            'planName' => $this->proPlan()->name,
            'bank' => [
                'beneficiary' => config('billing.bank_transfer.beneficiary'),
                'iban' => config('billing.bank_transfer.iban'),
                'bic' => config('billing.bank_transfer.bic'),
            ],
        ]);
    }

    private function proPlan(): Plan
    {
        return Plan::where('key', 'pro')->firstOrFail();
    }

    /** @return array<string, string|null>|null */
    private function existingBilling(): ?array
    {
        $profile = BillingProfile::query()->first();

        return $profile === null ? null : [
            'name' => $profile->name,
            'tax_number' => $profile->tax_number,
            'address_line1' => $profile->address_line1,
            'address_line2' => $profile->address_line2,
            'postal_code' => $profile->postal_code,
            'city' => $profile->city,
            'country' => $profile->country,
            'email' => $profile->email,
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ').' €';
    }
}
