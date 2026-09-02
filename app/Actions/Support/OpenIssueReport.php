<?php

namespace App\Actions\Support;

use App\Models\SupportAttachment;
use App\Models\SupportRequest;
use App\Models\User;
use App\Support\Support\IssueConsent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Um reporte do widget: o pedido de sempre, mais imagens e mais um aceite.
 *
 * NÃO DUPLICA `OpenSupportRequest`, CHAMA-O. A referência, a identidade
 * resolvida no servidor, a primeira mensagem, o evento de auditoria sem autor e
 * os avisos são todos dele, e continuam a ser — este caminho acrescenta o que é
 * seu e mais nada. Duas portas para a mesma fila só são seguras enquanto forem
 * a mesma porta por dentro.
 *
 * A CONDIÇÃO DA CAPTURA DE ECRÃ VIVE AQUI. Uma imagem que chegue sem
 * `screenshot_certified` é descartada em silêncio e o reporte entra na mesma —
 * não é um erro de validação, porque não é um erro: é uma imagem que a pessoa
 * não certificou, e o desenho diz que essa não segue. Bloquear o envio inteiro
 * ensinaria a marcar a caixa sem olhar, que é o oposto do que ela existe para
 * fazer.
 */
class OpenIssueReport
{
    public function __construct(protected OpenSupportRequest $openSupportRequest) {}

    /**
     * A forma é a mesma que `OpenSupportRequest::open()` declara, mais o que
     * este caminho acrescenta. Escrita por extenso em vez de `array<string,
     * mixed>`: as duas acções escrevem na mesma tabela, e uma chave que uma
     * conheça e a outra não é exactamente o tipo de divergência que uma
     * assinatura larga esconde até ser tarde.
     *
     * @param  array{category: string, subject: string, description: string, technical_route?: ?string, client_context?: ?array<string, mixed>, screenshot_certified?: bool, screenshot_warning?: ?string}  $data
     * @param  list<UploadedFile>  $images
     */
    public function open(array $data, User $user, ?UploadedFile $screenshot, array $images): SupportRequest
    {
        // A certificação decide-se ANTES de a transação abrir: se a imagem não
        // segue, não há nada a gravar nem a limpar depois.
        $certified = (bool) ($data['screenshot_certified'] ?? false);
        $screenshot = $certified ? $screenshot : null;

        $request = $this->openSupportRequest->open($data, $user, $user->personalOrganization());

        return DB::transaction(function () use ($request, $data, $screenshot, $images, $certified): SupportRequest {
            $stored = 0;

            if ($screenshot !== null) {
                $this->store($request, $screenshot, SupportAttachment::KIND_SCREENSHOT);
                $stored = 1;
            }

            foreach ($images as $image) {
                $this->store($request, $image, SupportAttachment::KIND_USER_UPLOAD);
            }

            $request->forceFill([
                'consent_accepted_at' => now(),
                'consent_terms_version' => IssueConsent::currentVersion(),
                'consent_scope' => IssueConsent::scope(
                    ['screenshot_certified' => $certified] + $data,
                    $stored,
                    count($images),
                ),
            ])->save();

            return $request->fresh();
        });
    }

    /**
     * Guarda um ficheiro no disco PRIVADO, com um nome que não vem de fora.
     *
     * O nome original nunca toca no disco: um ficheiro chamado
     * «pauta-do-7A-com-notas.png» seria, ele próprio, um dado pessoal no
     * caminho — e um caminho é a única parte disto que se lê sem abrir nada.
     */
    protected function store(SupportRequest $request, UploadedFile $file, string $kind): void
    {
        $ulid = (string) Str::ulid();
        $extension = $file->extension() ?: 'bin';
        $path = "support/{$request->ulid}/{$ulid}.{$extension}";

        Storage::disk('local')->putFileAs(
            "support/{$request->ulid}",
            $file,
            "{$ulid}.{$extension}",
        );

        // `forceFill`, e nao `create`: o `ulid` e gerado aqui porque tambem
        // nomeia o ficheiro, e uma coluna gerada nao entra no `#[Fillable]` —
        // e o proprio ponto de o `#[Fillable]` ser uma lista curta e explicita.
        (new SupportAttachment)->forceFill([
            'ulid' => $ulid,
            'support_request_id' => $request->getKey(),
            'kind' => $kind,
            'disk_path' => $path,
            'mime' => (string) $file->getMimeType(),
            'bytes' => (int) $file->getSize(),
        ])->save();
    }
}
