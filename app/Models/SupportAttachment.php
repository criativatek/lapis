<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma imagem que veio com um reporte.
 *
 * VIVE NO DISCO PRIVADO E NUNCA TEM URL. `disk_path` é um caminho relativo a
 * `storage/app/private`; quem a serve é um controlador atrás da política do
 * pedido, e não o servidor de ficheiros. Foi o erro que o sistema equivalente do
 * Plaanly cometeu ao pôr as capturas num disco público: o caminho é adivinhável
 * e a autenticação da rota deixa de valer nada.
 *
 * DUAS ESPÉCIES, E A DIFERENÇA IMPORTA. `screenshot` é a que a aplicação tirou
 * do ecrã de quem reportou — a que pode conter uma pauta inteira sem ninguém
 * ter escrito nada — e por isso tem uma certificação própria. `user_upload` é a
 * que a pessoa escolheu de propósito num selector de ficheiros.
 *
 * APAGA-SE COM O PEDIDO, das duas maneiras: `cascadeOnDelete` no esquema, e
 * dentro da transacção de `AnonymiseSupportRequest` aos 24 meses, que também
 * apaga o ficheiro do disco — uma linha eliminada com o ficheiro a ficar seria
 * a pior das duas metades.
 */
#[Fillable(['support_request_id', 'kind', 'disk_path', 'mime', 'bytes'])]
class SupportAttachment extends Model
{
    use HasUlids;

    public const KIND_SCREENSHOT = 'screenshot';

    public const KIND_USER_UPLOAD = 'user_upload';

    /**
     * O ULID e a coluna `ulid`, nao a chave primaria.
     *
     * Sem isto o `HasUlids` assume que a chave e uma string e escreve o ULID em
     * `id`, que e um inteiro — e o insert falha com "datatype mismatch", longe
     * da causa. Mesma forma de `SupportRequest` e `SupportMessage`.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<SupportRequest, $this> */
    public function supportRequest(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class);
    }

    public function isScreenshot(): bool
    {
        return $this->kind === self::KIND_SCREENSHOT;
    }

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
        ];
    }
}
