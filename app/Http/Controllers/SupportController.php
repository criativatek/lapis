<?php

namespace App\Http\Controllers;

use App\Actions\Support\OpenSupportRequest;
use App\Actions\Support\ReplyToSupportRequest;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Support\StoreSupportRequestRequest;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Central de Suporte de quem tem conta.
 *
 * O QUE ESTA CLASSE NUNCA FAZ é procurar um pedido por qualquer coisa que não
 * seja o ULID da rota mais a policy. Não há pesquisa por referência, não há
 * «recuperar pedido» — `SUP-XXXXXX` é um número de protocolo, não uma
 * credencial (ADR-0011 §3).
 *
 * A ORGANIZAÇÃO VIAJA COMO CONTEXTO. É gravada no pedido para o operador saber
 * de onde veio, e não participa em decisão de acesso nenhuma: um colega da
 * mesma organização não vê este pedido, e o administrador institucional também
 * não.
 */
class SupportController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected OpenSupportRequest $open,
        protected ReplyToSupportRequest $replies,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function index(Request $request): Response
    {
        $pedidos = SupportRequest::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupportRequest $pedido): array => $this->summary($pedido));

        return Inertia::render('support/Index', ['requests' => $pedidos]);
    }

    public function create(): Response
    {
        return Inertia::render('support/Create', [
            'categories' => SupportCategory::options(),
        ]);
    }

    public function store(StoreSupportRequestRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        /** @var array{category: string, subject: string, description: string, technical_reference: ?string, technical_route: ?string} $dados */
        $dados = $request->validated();

        $pedido = $this->open->open($dados, $request->user(), $this->currentOrganization->get());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Pedido :reference registado. Respondemos por email.', ['reference' => $pedido->reference]),
        ]);

        return to_route('support.show', $pedido);
    }

    public function show(SupportRequest $support): Response
    {
        Gate::authorize('view', $support);

        return Inertia::render('support/Show', [
            'request' => $this->summary($support) + [
                'description' => $support->description,
                'messages' => $support->messages->map(fn ($mensagem): array => [
                    'ulid' => $mensagem->ulid,
                    'role' => $mensagem->author_role->value,
                    'roleLabel' => $mensagem->author_role->label(),
                    'body' => $mensagem->body,
                    'createdAt' => $mensagem->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * Responder — e reabrir, se estava resolvido.
     *
     * Não há endpoint de reabertura à parte: quem tem mais a dizer, diz, e o
     * pedido volta a `open`. Ver `ReplyToSupportRequest`.
     */
    public function reply(Request $request, SupportRequest $support): RedirectResponse
    {
        Gate::authorize('reply', $support);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $this->replies->fromRequester($support, $request->user(), $validated['body']);

        return back();
    }

    /**
     * O resumo que os ecrãs recebem.
     *
     * SEM `technical_code`: é classificação interna, para o backoffice. Mostrá-la
     * a quem pediu ajuda seria expor a nossa taxonomia de avarias e convidar a
     * discussões sobre a etiqueta em vez de sobre o problema.
     *
     * @return array<string, mixed>
     */
    protected function summary(SupportRequest $pedido): array
    {
        return [
            'ulid' => $pedido->ulid,
            'reference' => $pedido->reference,
            'subject' => $pedido->subject,
            'category' => $pedido->category->value,
            'categoryLabel' => $pedido->category->label(),
            'status' => $pedido->status->value,
            'statusLabel' => $pedido->status->label(),
            'createdAt' => $pedido->created_at?->toIso8601String(),
            'resolvedAt' => $pedido->resolved_at?->toIso8601String(),
            'autoResolved' => $pedido->auto_resolved,
        ];
    }
}
