<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Commercial\ConfirmBankTransferRequest;
use App\Actions\Commercial\CorrectSubscriptionPayment;
use App\Actions\Commercial\RecordSubscriptionPayment;
use App\Actions\Commercial\RequestBankTransferPayment;
use App\Actions\Commercial\SetCommercialCondition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfirmTransferRequest;
use App\Http\Requests\Admin\CorrectPaymentRequest;
use App\Http\Requests\Admin\RecordPaymentRequest;
use App\Http\Requests\Admin\SetCommercialConditionRequest;
use App\Mail\BankTransferConfirmedMail;
use App\Models\AuditEvent;
use App\Models\BillingProfile;
use App\Models\CommercialCondition;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\PaymentMethod;
use App\Models\PaymentStatus;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Commercial\CheckoutUnavailable;
use App\Support\Commercial\CommercialConditionException;
use App\Support\Commercial\CommercialFilters;
use App\Support\Commercial\CommercialListing;
use App\Support\Commercial\CommercialMetrics;
use App\Support\Commercial\EffectiveSubscriptions;
use App\Support\Commercial\FounderAvailability;
use App\Support\Commercial\FounderSeats;
use App\Support\Commercial\PaymentCorrectionException;
use App\Support\Commercial\SubscriptionCondition;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > Comercial — the operator's view of accounts, subscriptions and money.
 *
 * THE ONE RULE THIS AREA EXISTS TO ENFORCE: plan is not payment. Every euro on
 * these screens comes from `subscription_payments` rows that are `paid`, via
 * `CommercialMetrics`, and never from counting Pro accounts and multiplying by a
 * price. An account can be on Pro through a launch condition, a voucher, a
 * trial, an operator's grant or an institutional arrangement, and this area's
 * whole job is to let somebody see WHICH — including the honest answer, «Origem
 * não registada», which is what almost every row in this database says today.
 *
 * DELIBERATELY SEPARATE FROM `AdminAccountController`. That controller manages
 * the person and the product (verify an email, change a plan, suspend, deactivate,
 * impersonate). This one manages the commercial record and nothing else, and
 * carries no button that changes what an account may USE — no «dar Pro», no
 * «mudar plano», no «reembolsar» that touches the subscription. Marking a
 * condition and recording a payment are both facts ABOUT an account, never
 * grants TO it.
 *
 * NO PEDAGOGICAL DATA REACHES THESE SCREENS (§19). Nothing here loads a student,
 * a class, a classification, an evidence record or a report. The only personal
 * data present is what managing a subscription requires: the account holder's
 * name and email.
 *
 * Runs outside tenancy, like the rest of the backoffice: every read is
 * explicitly `withoutGlobalScope('organization')`.
 */
class AdminCommercialController extends Controller
{
    public function __construct(
        protected CommercialMetrics $metrics,
        protected CommercialListing $listing,
        protected EffectiveSubscriptions $effective,
        protected Entitlements $entitlements,
        protected RecordSubscriptionPayment $recordPayment,
        protected CorrectSubscriptionPayment $correctPayment,
        protected ConfirmBankTransferRequest $confirmTransferRequest,
        protected SetCommercialCondition $setCondition,
        protected FounderSeats $founderSeats,
        protected FounderAvailability $founderAvailability,
    ) {}

    public function index(Request $request): Response
    {
        $filters = CommercialFilters::fromRequest($request);

        $subscriptions = $this->listing->query($filters)->paginate(25)->withQueryString();

        $totals = $this->listing->paymentTotals($this->organizationIdsOf(collect($subscriptions->items())));

        $subscriptions->through(fn (OrganizationSubscription $subscription): array => $this->row($subscription, $totals));

        return Inertia::render('admin/Commercial', [
            'metrics' => $this->metrics->snapshot($filters->from, $filters->to),
            'subscriptions' => $subscriptions,
            'filters' => $filters->toQuery(),
            'options' => $this->filterOptions(),
            // Fora dos filtros e da paginação de propósito: isto é trabalho por
            // fazer, não um recorte da carteira. Um pedido por confirmar tem de
            // aparecer a quem abre este ecrã mesmo que o filtro escolhido não o
            // apanhasse — senão é preciso saber que ele existe para o encontrar.
            'awaitingConfirmation' => $this->awaitingConfirmation(),
        ]);
    }

    /**
     * The listing as CSV, under exactly the filters on screen.
     *
     * Streamed rather than built in memory, and deliberately narrow: the
     * commercial columns of the table and nothing more. No pedagogical data, no
     * student anything, and no attempt to become a reporting module — an
     * operator who needs analysis has the CSV and a spreadsheet.
     */
    /**
     * Os pedidos de transferência à espera de que alguém veja o extrato.
     *
     * PORQUE É QUE ISTO É UMA LISTA PRÓPRIA. A tabela de subscrições responde a
     * «em que plano está cada conta»; um pedido por confirmar não é um plano, é
     * uma tarefa — e estava invisível: só se encontrava abrindo a ficha de uma
     * conta que já se soubesse ter pago. Quem chega a este ecrã tem de ver o
     * que está à espera dele sem ter de o adivinhar.
     *
     * @return list<array<string, mixed>>
     */
    protected function awaitingConfirmation(): array
    {
        $pedidos = SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('status', PaymentStatus::Pending)
            ->where('provider', RequestBankTransferPayment::PROVIDER)
            ->orderBy('created_at')
            ->get()
            ->map(function (SubscriptionPayment $payment): array {
                // `findOrFail`: a chave estrangeira é `restrictOnDelete`, por
                // isso a organização existe sempre. Um pagamento órfão seria um
                // problema de integridade, e falhar alto é melhor do que
                // mostrar um travessão onde devia estar uma conta.
                $organization = Organization::withoutGlobalScopes()->findOrFail($payment->organization_id);

                return [
                    'ulid' => $payment->ulid,
                    'reference' => $payment->provider_reference,
                    'amount' => number_format($payment->amount_cents / 100, 2, ',', ' ').' '
                        .($payment->currency === 'EUR' ? '€' : $payment->currency),
                    'account' => $organization->name,
                    'accountUlid' => $organization->ulid,
                    'requestedAt' => $payment->created_at?->toDateString(),
                    'waitingDays' => (int) ($payment->created_at?->diffInDays(now()) ?? 0),
                ];
            })
            ->all();

        return array_values($pedidos);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = CommercialFilters::fromRequest($request);
        $filename = 'Lapispro-comercial-'.Carbon::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // Excel opens a UTF-8 CSV as Latin-1 unless the file says
            // otherwise, which turns every «ã» into mojibake. The BOM is what
            // makes «Condição» readable in the tool this will actually be
            // opened in.
            fwrite($handle, "\u{FEFF}");

            fputcsv($handle, [
                'Organização', 'Tipo', 'Titular', 'Email', 'Plano', 'Condição comercial',
                'Estado', 'Início', 'Fim', 'Pago (EUR)', 'N.º pagamentos', 'Último pagamento',
            ], escape: '\\');

            $this->listing->query($filters)->chunk(200, function (Collection $chunk) use ($handle): void {
                $totals = $this->listing->paymentTotals($this->organizationIdsOf($chunk));

                foreach ($chunk as $subscription) {
                    $row = $this->row($subscription, $totals);

                    fputcsv($handle, [
                        $row['organization'],
                        $row['type'],
                        $row['owner'] ?? '',
                        $row['owner_email'] ?? '',
                        $row['plan'] ?? '',
                        $row['condition_label'],
                        $row['status_label'] ?? '',
                        $row['starts_at'] ?? '',
                        $row['ends_at'] ?? '',
                        // Decimal with a comma, because this is opened in a
                        // Portuguese spreadsheet. Cents are the storage format,
                        // not the reading format.
                        number_format($row['paid_cents'] / 100, 2, ',', ''),
                        $row['payment_count'],
                        $row['last_paid_at'] ?? '',
                    ], escape: '\\');
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One account's commercial record, whole: what it is on, on what terms,
     * every subscription it has ever had, every payment ever recorded, and the
     * administrative changes that produced them.
     */
    public function show(Organization $organization): Response
    {
        $organization->load('owner');

        $history = $this->effective->historyOf($organization->getKey());
        $current = $history->first(fn (OrganizationSubscription $subscription) => $subscription->isInForce())
            ?? $history->first();

        $payments = SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->with(['recordedBy', 'statusChangedBy'])
            ->latest('paid_at')
            ->latest('id')
            ->get();

        return Inertia::render('admin/CommercialAccount', [
            'account' => [
                'ulid' => $organization->ulid,
                'name' => $organization->name,
                'type' => $organization->type->value,
                'created_at' => $organization->created_at?->toDateString(),
                // The only personal data this screen carries, and only because
                // managing a subscription requires knowing whose it is.
                'owner' => $organization->owner === null ? null : [
                    'name' => $organization->owner->name,
                    'email' => $organization->owner->email,
                ],
            ],
            'current' => $current === null ? null : [
                ...$this->subscriptionPayload($current),
                // What the account may actually USE right now — the resolver's
                // own answer, shown so an operator can see that a commercial
                // condition changes none of it.
                //
                // COUNTS, NOT KEYS. The page only ever renders «20 módulos
                // ativos», and the key list would put `students`, `classes` and
                // `reports` into a commercial payload — capability names, not
                // student data, but a commercial screen has no use for either
                // and sending the smaller thing is the easier promise to keep.
                'module_count' => count($this->entitlements->modulesFor($organization)),
                'read_only_count' => count($this->entitlements->readOnlyModulesFor($organization)),
            ],
            'history' => $history->map(fn (OrganizationSubscription $subscription): array => $this->subscriptionPayload($subscription))->values(),
            'overrides' => OrganizationModuleOverride::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->getKey())
                ->with('module')
                ->get()
                ->map(fn (OrganizationModuleOverride $override): array => [
                    'module' => $override->module->name,
                    'enabled' => $override->enabled,
                    'reason' => $override->reason,
                    'in_force' => $override->isInForce(),
                    'starts_at' => $override->starts_at?->toDateString(),
                    'ends_at' => $override->ends_at?->toDateString(),
                ])->values(),
            'payments' => $payments->map(fn (SubscriptionPayment $payment): array => [
                'ulid' => $payment->ulid,
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'counts_as_revenue' => $payment->countsAsRevenue(),
                'method' => $payment->method?->label(),
                'provider_reference' => $payment->provider_reference,
                'condition_label' => $payment->commercial_condition?->label(),
                'voucher_code' => $payment->voucher_code,
                'paid_at' => $payment->paid_at?->toDateString(),
                'period_starts_at' => $payment->period_starts_at?->toDateString(),
                'period_ends_at' => $payment->period_ends_at?->toDateString(),
                'recorded_by' => $payment->recordedBy?->name,
                'recorded_at' => $payment->created_at?->toDateTimeString(),
                'status_reason' => $payment->status_reason,
                'status_changed_by' => $payment->statusChangedBy?->name,
                'status_changed_at' => $payment->status_changed_at?->toDateTimeString(),
                'correctable' => ! $payment->status->isTerminal(),
                'refundable' => $payment->status === PaymentStatus::Paid,
                // Um pedido que o cliente abriu no checkout e está à espera de
                // que alguém veja a transferência no extrato. Só estes se
                // confirmam — um pagamento pendente escrito à mão por um
                // operador não tem referência que ligue a nada.
                'confirmable' => $payment->status === PaymentStatus::Pending
                    && $payment->provider === RequestBankTransferPayment::PROVIDER,
            ])->values(),
            'totals' => [
                'paid_cents' => $payments->filter->countsAsRevenue()->sum('amount_cents'),
                'payment_count' => $payments->filter->countsAsRevenue()->count(),
                // The account's own currency, read off its payments rather than
                // assumed. Falls back to EUR when it has none — the same answer
                // `CommercialMetrics::currency()` gives for an empty table.
                'currency' => (string) ($payments->pluck('currency')->first() ?? 'EUR'),
            ],
            // The administrative trail, commercial events only. Read from the
            // target organization's own trail, unfiltered by causer: this is the
            // platform operator reading their own record of what operators did.
            'audit' => AuditEvent::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->getKey())
                ->whereIn('event', self::COMMERCIAL_EVENTS)
                ->with('causer')
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (AuditEvent $event): array => [
                    'event' => $event->event,
                    'summary' => $event->summary,
                    'causer' => $event->causer?->name,
                    'created_at' => $event->created_at->toDateTimeString(),
                ])->values(),
            'options' => [
                'conditions' => SubscriptionCondition::assignableOptions(),
                'methods' => PaymentMethod::options(),
                'statuses' => [
                    ['value' => PaymentStatus::Paid->value, 'label' => PaymentStatus::Paid->label()],
                    ['value' => PaymentStatus::Pending->value, 'label' => PaymentStatus::Pending->label()],
                ],
            ],
            // Stated on the page rather than left for an operator to discover:
            // a trial's condition comes from its status and cannot be typed.
            'condition_locked' => $current?->status === SubscriptionStatus::Trial,

            // O lugar de Membro Fundador desta conta, se tiver um. Read-only:
            // o número prometido, o preço que ficou congelado e se já está
            // confirmado ou ainda em reserva. É o que responde, sem SQL, à
            // pergunta «esta conta é mesmo fundadora, e é a número quantos?».
            'founderSeat' => $this->founderSeatPayload($organization),
        ]);
    }

    public function setCommercialCondition(SetCommercialConditionRequest $request, Organization $organization): RedirectResponse
    {
        $current = $this->effective->current([$organization->getKey()])->get($organization->getKey());

        if ($current === null) {
            return back()->withErrors(['condition' => __('Esta conta não tem subscrição para marcar.')]);
        }

        try {
            $this->setCondition->set(
                $current,
                $organization,
                $this->user(),
                CommercialCondition::tryFrom($this->optionalString($request->validated('condition')) ?? ''),
                $this->optionalString($request->validated('note')),
            );
        } catch (CommercialConditionException $exception) {
            return back()->withErrors(['condition' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Condição comercial registada.')]);

        return back();
    }

    public function storePayment(RecordPaymentRequest $request, Organization $organization): RedirectResponse
    {
        $payment = $this->recordPayment->record(
            organization: $organization,
            operator: $this->user(),
            amountCents: $request->amountCents(),
            status: PaymentStatus::from((string) $request->validated('status')),
            paidAt: $this->toDate($this->optionalString($request->validated('paid_at'))),
            currency: (string) $request->validated('currency'),
            method: PaymentMethod::tryFrom($this->optionalString($request->validated('method')) ?? ''),
            providerReference: $this->optionalString($request->validated('provider_reference')),
            commercialCondition: CommercialCondition::tryFrom($this->optionalString($request->validated('commercial_condition')) ?? ''),
            voucherCode: $this->optionalString($request->validated('voucher_code')),
            periodStartsAt: $this->toDate($this->optionalString($request->validated('period_starts_at'))),
            periodEndsAt: $this->toDate($this->optionalString($request->validated('period_ends_at'))),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Pagamento registado (:status).', [
            'status' => mb_strtolower($payment->status->label()),
        ])]);

        return back();
    }

    /**
     * O dinheiro de um pedido pendente entrou.
     *
     * Anula o pedido e regista o pagamento verdadeiro — duas linhas, porque um
     * pagamento é imutável excepto no estado e `paid_at` não se escreve depois
     * da criação.
     *
     * NÃO ACTIVA O PLANO, de propósito: aprovisionar e cobrar são factos
     * independentes neste domínio. O aviso a seguir diz-o com todas as letras,
     * porque é exactamente o passo que se esquece.
     */
    public function confirmTransfer(ConfirmTransferRequest $request, string $payment): RedirectResponse
    {
        $pedido = SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('ulid', $payment)
            ->firstOrFail();

        $organization = Organization::findOrFail($pedido->organization_id);

        try {
            $recebido = $this->confirmTransferRequest->confirm(
                $pedido,
                $organization,
                $this->user(),
                $request->amountCents(),
                Carbon::parse((string) $request->validated('paid_at')),
            );
        } catch (CheckoutUnavailable $exception) {
            return back()->withErrors(['amount' => $exception->getMessage()]);
        }

        // Quem transferiu fica sem saber de nada até isto chegar: a
        // transferência não gera recibo do nosso lado e o plano não muda no
        // momento em que o dinheiro entra. Falhar o envio não desfaz o registo
        // — o dinheiro entrou na mesma —, por isso o erro só vai para o log.
        try {
            $destino = app(CurrentOrganization::class)->runFor(
                $organization,
                fn (): ?string => BillingProfile::query()->value('email'),
            ) ?? $organization->owner?->email;

            if ($destino !== null) {
                // O plano vem do pedido e não da subscrição: a subscrição do
                // Pro ainda não existe neste momento — activar é o acto
                // seguinte, e de propósito.
                // `firstOrFail`: os planos são dados de referência semeados em
                // todos os ambientes. Se não existir, o problema é maior do que
                // um email — e o catch abaixo já impede que estrague o registo
                // do pagamento, que é a parte que não se pode perder.
                $plano = Plan::where('key', $pedido->metadata['plan_key'] ?? 'pro')->firstOrFail();

                Mail::to($destino)->send(new BankTransferConfirmedMail($recebido, $plano->name));
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(
            'Pagamento registado e cliente avisado por email. Falta ATIVAR O PLANO na ficha da conta — registar dinheiro não muda o plano.',
        )]);

        return back();
    }

    public function refundPayment(CorrectPaymentRequest $request, string $payment): RedirectResponse
    {
        return $this->correct($request, $payment, refund: true);
    }

    public function voidPayment(CorrectPaymentRequest $request, string $payment): RedirectResponse
    {
        return $this->correct($request, $payment, refund: false);
    }

    /**
     * The payment arrives as a bare ULID, not a route-model binding, and that is
     * not an oversight. `SubscriptionPayment` is tenant-scoped, and the global
     * scope THROWS when no tenant is resolved — which is always, in a backoffice
     * that deliberately runs outside tenancy. Binding it implicitly would fail
     * with `TenantNotResolvedException` instead of a 404, so the lookup is done
     * here, explicitly and unscoped, exactly like every other cross-organization
     * read in this area.
     */
    protected function correct(CorrectPaymentRequest $request, string $paymentUlid, bool $refund): RedirectResponse
    {
        $payment = SubscriptionPayment::query()
            ->withoutGlobalScope('organization')
            ->where('ulid', $paymentUlid)
            ->firstOrFail();

        // The payment knows which account it belongs to, and that is the only
        // account this may ever touch.
        $organization = Organization::findOrFail($payment->organization_id);

        try {
            $refund
                ? $this->correctPayment->refund($payment, $organization, $this->user(), (string) $request->validated('reason'))
                : $this->correctPayment->void($payment, $organization, $this->user(), (string) $request->validated('reason'));
        } catch (PaymentCorrectionException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $refund
            ? __('Pagamento reembolsado. Saiu da receita.')
            : __('Pagamento anulado. Saiu da receita.')]);

        return back();
    }

    /**
     * The commercial events this area writes, and the only ones its detail page
     * reads back. Named explicitly rather than matched by prefix so an unrelated
     * `admin.*` event can never leak into a financial trail.
     *
     * `admin.plan_changed`, `admin.subscription_suspended` and
     * `admin.subscription_reactivated` are `AdminAccountController`'s, not this
     * controller's — they are included because a plan moving is part of an
     * account's commercial story even when this screen was not what moved it.
     *
     * @var list<string>
     */
    protected const COMMERCIAL_EVENTS = [
        'commercial.condition_set',
        'commercial.payment_requested',
        'commercial.payment_recorded',
        'commercial.payment_refunded',
        'commercial.payment_voided',
        // Os três da condição Fundador. A atribuição de uma condição especial
        // tem de ser auditável (§19 do enunciado), e um lugar dos 250 é a mais
        // especial que este produto tem: o trilho diz o número, o preço
        // congelado, por onde entrou e — quando é libertado — porquê.
        'commercial.founder_seat_claimed',
        'commercial.founder_seat_confirmed',
        'commercial.founder_seat_released',
        'admin.plan_changed',
        'admin.subscription_suspended',
        'admin.subscription_reactivated',
    ];

    /**
     * The distinct accounts a page (or a chunk) of subscriptions belongs to —
     * the key the payment aggregate is fetched by.
     *
     * @param  Collection<int, OrganizationSubscription>  $subscriptions
     * @return array<int, int>
     */
    protected function organizationIdsOf(Collection $subscriptions): array
    {
        return $subscriptions
            ->map(fn (OrganizationSubscription $subscription): int => $subscription->organization_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{total_cents: int, payment_count: int, last_paid_at: string|null}>  $totals
     * @return array<string, mixed>
     */
    protected function row(OrganizationSubscription $subscription, array $totals): array
    {
        $organization = $subscription->organization;
        // Absent for an account with no revenue-bearing payment at all, which
        // is most of them.
        $total = $totals[$subscription->organization_id] ?? null;

        return [
            'ulid' => $organization->ulid,
            'organization' => $organization->name,
            'type' => $organization->type->value,
            'owner' => $organization->owner?->name,
            'owner_email' => $organization->owner?->email,
            'plan' => $subscription->plan->name,
            'plan_key' => $subscription->plan->key,
            'condition' => SubscriptionCondition::keyOf($subscription),
            'condition_label' => SubscriptionCondition::labelOf($subscription),
            'status' => $subscription->status->value,
            'status_label' => $subscription->status->label(),
            'in_force' => $subscription->isInForce(),
            'starts_at' => $subscription->starts_at->toDateString(),
            'ends_at' => $subscription->ends_at?->toDateString(),
            // Zero means «nothing was ever recorded», which is the truth for
            // this database today — not «this account owes nothing».
            'paid_cents' => $total['total_cents'] ?? 0,
            'payment_count' => $total['payment_count'] ?? 0,
            'last_paid_at' => ($total['last_paid_at'] ?? null) === null
                ? null
                : Carbon::parse($total['last_paid_at'])->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionPayload(OrganizationSubscription $subscription): array
    {
        return [
            'id' => $subscription->getKey(),
            'plan' => $subscription->plan->name,
            'plan_key' => $subscription->plan->key,
            'status' => $subscription->status->value,
            'status_label' => $subscription->status->label(),
            'condition' => SubscriptionCondition::keyOf($subscription),
            'condition_label' => SubscriptionCondition::labelOf($subscription),
            'condition_stored' => $subscription->commercial_condition?->value,
            'condition_note' => $subscription->commercial_condition_note,
            'is_trial' => $subscription->status === SubscriptionStatus::Trial,
            'in_force' => $subscription->isInForce(),
            'starts_at' => $subscription->starts_at->toDateTimeString(),
            'ends_at' => $subscription->ends_at?->toDateTimeString(),

            // O QUE FOI CONTRATADO. Quatro colunas que existem desde a 0.88.0 e
            // que este ecrã nunca mostrou: um administrador que quisesse
            // responder «que condição é que esta organização contratou?» tinha
            // de abrir a base de dados. Read-only aqui de propósito — são prova
            // imutável, e o modelo recusa qualquer alteração — e enviadas em
            // bruto, com a formatação a viver na página como toda a outra.
            'contracted_price_cents' => $subscription->contracted_price_cents,
            'contracted_currency' => $subscription->contracted_currency,
            'billing_period' => $subscription->billing_period?->value,
            'billing_period_label' => $subscription->billing_period?->label(),
            'commercial_term_ends_at' => $subscription->commercial_term_ends_at?->toDateString(),

            // A versão do plano contratada (ADR-0008): já existia na coluna e
            // aparecia noutro ecrã, mas não aqui, ao lado da condição — que é
            // onde a pergunta «o que é que esta conta comprou» se faz.
            // `plan_version_id` nunca é nulo — a chave estrangeira composta e o
            // guarda `creating` do modelo garantem-no —, por isso o `?->` aqui
            // cobre apenas a relação, e não a coluna.
            'plan_version' => $subscription->planVersion?->version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterOptions(): array
    {
        return [
            'plans' => Plan::orderBy('sort_order')->get(['key', 'name'])
                ->map(fn (Plan $plan): array => ['value' => $plan->key, 'label' => $plan->name])->values(),
            'conditions' => SubscriptionCondition::filterOptions(),
            'statuses' => array_map(
                fn (SubscriptionStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                SubscriptionStatus::cases(),
            ),
        ];
    }

    /**
     * O lugar de Membro Fundador desta organização, ou NULL.
     *
     * NÃO É EDITÁVEL AQUI. Um lugar toma-se no checkout e liberta-se com uma
     * razão registada (`FounderSeats::release()`); um campo neste ecrã faria a
     * promessa dos 250 depender de quem escrevesse por cima. O que este ecrã
     * faz é mostrá-lo.
     *
     * @return array<string, mixed>|null
     */
    protected function founderSeatPayload(Organization $organization): ?array
    {
        $seat = $this->founderSeats->seatOf($organization);

        if ($seat === null) {
            return null;
        }

        return [
            'number' => $seat->seat_number,
            'capacity' => $this->founderAvailability->capacity(),
            'price_cents' => $seat->price_cents,
            'currency' => $seat->currency,
            'claimed_at' => $seat->claimed_at->toDateTimeString(),
            'confirmed_at' => $seat->confirmed_at?->toDateTimeString(),
            'reserved_until' => $seat->reserved_until?->toDateTimeString(),
            'is_confirmed' => $seat->isConfirmed(),
            'is_holding' => $seat->isHolding(),
        ];
    }

    protected function toDate(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }

    /**
     * `validated()` returns `mixed`; every optional field here is "a non-empty
     * string, or nothing at all", and an empty select posts `''`, not null.
     */
    protected function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The signed-in operator. Same helper, same reasoning, as every other
     * controller in this application: the route is behind `auth`, so the user
     * is never null, and the narrowing is stated once instead of at each use.
     */
    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
