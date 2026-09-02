<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Support\ChangeSupportStatus;
use App\Actions\Support\ManageRetentionHold;
use App\Actions\Support\ReplyToSupportRequest;
use App\Http\Controllers\Controller;
use App\Models\RetentionHoldReason;
use App\Models\SupportCategory;
use App\Models\SupportNotificationDelivery;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Models\SupportTechnicalCode;
use App\Support\Support\SupportNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Suporte — a fila, o fio e as decisões sobre ele.
 *
 * A FRONTEIRA DE ACESSO É O MIDDLEWARE `platform-admin`, e só ele. Não há aqui
 * verificações de policy: `SupportRequestPolicy` responde por quem é titular de
 * um pedido, e dar-lhe um ramo para o operador criaria uma segunda porta para a
 * mesma sala — duas portas divergem, e a que ficar esquecida é a que se abre
 * sozinha.
 *
 * O QUE ESTE ECRÃ FECHA, todo, na primeira versão: ver, filtrar, responder,
 * mudar de estado, classificar, suspender e retomar a eliminação, e reenviar um
 * aviso que não chegou. Uma Central que só recolhe pedidos sem os operar seria
 * uma caixa de correio com mais passos.
 */
class AdminSupportController extends Controller
{
    public function __construct(
        protected ReplyToSupportRequest $replies,
        protected ChangeSupportStatus $status,
        protected ManageRetentionHold $holds,
        protected SupportNotifier $notifier,
    ) {}

    public function index(Request $request): Response
    {
        $filtros = [
            'status' => (string) $request->query('status', ''),
            'category' => (string) $request->query('category', ''),
            'technical_code' => (string) $request->query('technical_code', ''),
            'hold' => (string) $request->query('hold', ''),
            'search' => trim((string) $request->query('search', '')),
        ];

        $pedidos = SupportRequest::query()
            ->when($filtros['status'] !== '', fn (Builder $q) => $q->where('status', $filtros['status']))
            ->when($filtros['category'] !== '', fn (Builder $q) => $q->where('category', $filtros['category']))
            ->when($filtros['technical_code'] !== '', fn (Builder $q) => $filtros['technical_code'] === 'none'
                ? $q->whereNull('technical_code')
                : $q->where('technical_code', $filtros['technical_code']))
            ->when($filtros['hold'] === 'active', fn (Builder $q) => $q
                ->whereNotNull('retention_hold_at')->whereNull('retention_hold_released_at'))
            // A PESQUISA É POR REFERÊNCIA OU EMAIL, e nunca pelo corpo: um
            // backoffice que procura dentro da descrição convida a lê-la sem
            // razão, e o conteúdo de um pedido só interessa a quem o vai
            // responder.
            ->when($filtros['search'] !== '', fn (Builder $q) => $q->where(function (Builder $procura) use ($filtros) {
                $procura->where('reference', 'like', '%'.$filtros['search'].'%')
                    ->orWhere('requester_email', 'like', '%'.$filtros['search'].'%');
            }))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'in_progress' THEN 1 WHEN 'waiting_for_user' THEN 2 ELSE 3 END")
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $pedidos->through(fn (SupportRequest $pedido): array => $this->row($pedido));

        return Inertia::render('admin/Support', [
            'requests' => $pedidos,
            'filters' => $filtros,
            'options' => [
                'statuses' => SupportRequestStatus::options(),
                'categories' => SupportCategory::options(),
                'technicalCodes' => SupportTechnicalCode::options(),
            ],
            // O trabalho que está à espera de uma pessoa: avisos que falharam e
            // ninguém reenviou. Fora dos filtros, como as transferências por
            // confirmar no ecrã comercial — é preciso vê-lo sem o procurar.
            'pendingDeliveries' => SupportNotificationDelivery::query()
                ->pending()
                ->with('request:id,ulid,reference')
                ->orderByDesc('last_failed_at')
                ->limit(25)
                ->get()
                ->map(fn (SupportNotificationDelivery $entrega): array => [
                    'ulid' => $entrega->ulid,
                    'requestUlid' => $entrega->request?->ulid,
                    'reference' => $entrega->request?->reference,
                    'type' => $entrega->notification_type->value,
                    'typeLabel' => $entrega->notification_type->label(),
                    'recipientLabel' => $entrega->recipient_role->label(),
                    'attempts' => $entrega->attempts,
                    'failureLabel' => $entrega->failure_code?->label(),
                    'lastFailedAt' => $entrega->last_failed_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function show(SupportRequest $support): Response
    {
        $support->load(['messages.author:id,name', 'user:id,name,email', 'organization:id,name', 'deliveries']);

        return Inertia::render('admin/SupportRequest', [
            'request' => $this->row($support) + [
                'description' => $support->description,
                'requesterName' => $support->requester_name,
                'requesterEmail' => $support->requester_email,
                'organizationName' => $support->organization?->name,
                'source' => $support->source->value,
                'appVersion' => $support->app_version,
                'technicalReference' => $support->technical_reference,
                'technicalRoute' => $support->technical_route,
                // O contexto do ecra, quando o reporte veio do widget. Lista
                // fechada, logo nao ha aqui texto livre que precise de cuidado.
                'clientContext' => $support->client_context,
                // As imagens e o aceite de quem as enviou. O URL é sempre o do
                // controlador — o ficheiro vive num disco privado e não tem
                // endereço próprio.
                'attachments' => $support->attachments->map(fn ($anexo): array => [
                    'ulid' => $anexo->ulid,
                    'kind' => $anexo->kind,
                    'bytes' => $anexo->bytes,
                    'url' => route('support.image', ['support' => $support, 'attachment' => $anexo]),
                ])->all(),
                'consent' => $support->consent_accepted_at === null ? null : [
                    'acceptedAt' => $support->consent_accepted_at->toIso8601String(),
                    'termsVersion' => $support->consent_terms_version,
                    'scope' => $support->consent_scope,
                ],
                'anonymizedAt' => $support->anonymized_at?->toIso8601String(),
                'holdNote' => $support->retention_hold_note,
                'holdReleasedAt' => $support->retention_hold_released_at?->toIso8601String(),
                'messages' => $support->messages->map(fn ($mensagem): array => [
                    'ulid' => $mensagem->ulid,
                    'role' => $mensagem->author_role->value,
                    'roleLabel' => $mensagem->author_role->label(),
                    'authorName' => $mensagem->author?->name,
                    'body' => $mensagem->body,
                    'createdAt' => $mensagem->created_at->toIso8601String(),
                ])->all(),
                'deliveries' => $support->deliveries->map(fn (SupportNotificationDelivery $entrega): array => [
                    'ulid' => $entrega->ulid,
                    'type' => $entrega->notification_type->value,
                    'typeLabel' => $entrega->notification_type->label(),
                    'recipientLabel' => $entrega->recipient_role->label(),
                    'attempts' => $entrega->attempts,
                    'deliveredAt' => $entrega->delivered_at?->toIso8601String(),
                    'failureLabel' => $entrega->failure_code?->label(),
                    'lastFailedAt' => $entrega->last_failed_at?->toIso8601String(),
                ])->all(),
            ],
            'options' => [
                'statuses' => SupportRequestStatus::options(),
                'technicalCodes' => SupportTechnicalCode::options(),
                'holdReasons' => RetentionHoldReason::options(),
            ],
        ]);
    }

    public function reply(Request $request, SupportRequest $support): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $this->replies->fromOperator($support, $request->user(), $validated['body']);

        return back();
    }

    public function changeStatus(Request $request, SupportRequest $support): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(SupportRequestStatus::class)],
        ]);

        $this->status->to($support, SupportRequestStatus::from($validated['status']), $request->user());

        return back();
    }

    public function classify(Request $request, SupportRequest $support): RedirectResponse
    {
        $validated = $request->validate([
            'technical_code' => ['nullable', Rule::enum(SupportTechnicalCode::class)],
        ]);

        $code = $validated['technical_code'] ?? null;

        $this->status->classify(
            $support,
            $code === null ? null : SupportTechnicalCode::from($code),
            $request->user(),
        );

        return back();
    }

    public function applyHold(Request $request, SupportRequest $support): RedirectResponse
    {
        $validated = $request->validate([
            // O motivo é OBRIGATÓRIO e de vocabulário fechado: uma excepção a
            // uma promessa de eliminação tem de ser contável.
            'reason_code' => ['required', Rule::enum(RetentionHoldReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->holds->apply(
            $support,
            $request->user(),
            RetentionHoldReason::from($validated['reason_code']),
            $validated['note'] ?? null,
        );

        return back();
    }

    public function releaseHold(Request $request, SupportRequest $support): RedirectResponse
    {
        $this->holds->release($support, $request->user());

        return back();
    }

    /**
     * Reenviar um aviso que não chegou.
     *
     * RECONSTRÓI O EMAIL A PARTIR DO PEDIDO — nada é lido da tabela de
     * entregas, que por isso não precisa de guardar conteúdo nenhum.
     */
    public function resendNotification(Request $request, SupportRequest $support): RedirectResponse
    {
        $validated = $request->validate([
            'notification_type' => ['required', Rule::enum(SupportNotificationType::class)],
        ]);

        $this->notifier->send(
            $support,
            SupportNotificationType::from($validated['notification_type']),
            force: true,
        );

        return back();
    }

    /** @return array<string, mixed> */
    protected function row(SupportRequest $pedido): array
    {
        return [
            'ulid' => $pedido->ulid,
            'reference' => $pedido->reference,
            'subject' => $pedido->subject,
            'category' => $pedido->category->value,
            'categoryLabel' => $pedido->category->label(),
            'status' => $pedido->status->value,
            'statusLabel' => $pedido->status->label(),
            'technicalCode' => $pedido->technical_code?->value,
            'technicalCodeLabel' => $pedido->technical_code?->label(),
            'requesterEmail' => $pedido->requester_email,
            'waitingDays' => $pedido->waiting_since === null
                ? null
                : (int) $pedido->waiting_since->diffInDays(Carbon::now()),
            'createdAt' => $pedido->created_at?->toIso8601String(),
            'resolvedAt' => $pedido->resolved_at?->toIso8601String(),
            'autoResolved' => $pedido->auto_resolved,
            'holdActive' => $pedido->hasActiveHold(),
            'holdReasonLabel' => $pedido->retention_hold_reason_code?->label(),
            'holdAt' => $pedido->retention_hold_at?->toIso8601String(),
            'isAnonymised' => $pedido->isAnonymised(),
        ];
    }
}
