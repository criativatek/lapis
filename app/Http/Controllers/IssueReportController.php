<?php

namespace App\Http\Controllers;

use App\Actions\Support\OpenIssueReport;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Support\StoreIssueReportRequest;
use App\Models\SupportAttachment;
use App\Models\SupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O widget de «Reportar problema»: entrada e leitura das imagens.
 *
 * A IMAGEM NUNCA TEM URL DIRECTO. É servida por este controlador, atrás da
 * mesma política que decide quem vê o pedido — e o disco onde vive é privado,
 * portanto não há caminho adivinhável que a sirva por trás das costas. Foi o
 * erro que o sistema equivalente do Plaanly cometeu ao guardar as capturas num
 * disco público: a rota estava autenticada e o ficheiro não.
 */
class IssueReportController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(protected OpenIssueReport $reports) {}

    public function store(StoreIssueReportRequest $request): RedirectResponse
    {
        // A mesma recusa do formulario de conversa, pela mesma razao: uma sessao
        // de suporte olha, nao escreve em nome de quem esta a ser visto. Aqui
        // pesa ainda mais, porque este caminho pode trazer uma captura do ecra
        // de outra pessoa.
        $this->refuseDuringImpersonation($request);

        /** @var list<UploadedFile> $images */
        $images = array_values($request->file('images', []));

        $support = $this->reports->open(
            $request->payload(),
            $request->user(),
            $request->file('screenshot'),
            $images,
        );

        return to_route('support.show', $support)
            ->with('flash', ['message' => __('Obrigado. O seu reporte foi registado com a referência :reference.', [
                'reference' => $support->reference,
            ])]);
    }

    /**
     * Uma imagem, transmitida — nunca redireccionada para o disco.
     *
     * A autorização é a do PEDIDO, não a da imagem: quem pode ver a conversa
     * pode ver o que veio com ela, e mais ninguém. Um administrador de
     * plataforma chega aqui pelo backoffice, que tem o seu próprio middleware.
     */
    public function image(SupportRequest $support, SupportAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $support);

        abort_if((int) $attachment->support_request_id !== (int) $support->getKey(), 404);
        abort_unless(Storage::disk('local')->exists($attachment->disk_path), 404);

        return Storage::disk('local')->response(
            $attachment->disk_path,
            null,
            // `inline` para abrir no browser; o nome nunca vem do original.
            ['Content-Type' => $attachment->mime, 'Content-Disposition' => 'inline'],
        );
    }
}
