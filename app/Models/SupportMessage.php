<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Uma mensagem do fio — e o fio é a fonte canónica.
 *
 * O email é notificação e recurso; isto é o registo (ADR-0011 §4). Não há
 * processamento de email de entrada na V1: uma resposta enviada para a caixa de
 * suporte chega lá e **não** aparece aqui, e os emails dizem-no em vez de
 * deixarem a pessoa descobrir que a resposta se perdeu.
 *
 * SEM GUARD DE ELIMINAÇÃO, ao contrário de `VoucherRedemption`. Lá, apagar
 * destruiria a prova de um contrato; aqui, apagar É o mecanismo — a
 * anonimização dos 24 meses apaga estas linhas, e um guard transformaria a
 * promessa de eliminação numa impossibilidade.
 *
 * @property int $id
 * @property string $ulid
 * @property int $support_request_id
 * @property SupportAuthorRole $author_role
 * @property int|null $author_user_id
 * @property string $body
 * @property Carbon $created_at
 */
#[Fillable(['support_request_id', 'author_role', 'author_user_id', 'body'])]
class SupportMessage extends Model
{
    use HasUlids;

    /**
     * A regra que o MySQL não deixa gravar no esquema (erro 3823):
     * `author_user_id` é uma chave estrangeira `nullOnDelete()`, e uma coluna
     * com acção referencial não pode aparecer numa CHECK. Fica aqui.
     *
     * Uma resposta de operador tem sempre um operador. As de quem pediu podem
     * não ter conta — um visitante —, e as do sistema nunca têm.
     */
    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            if ($message->author_role === SupportAuthorRole::Operator && $message->author_user_id === null) {
                throw new LogicException(
                    'An operator reply always has an operator behind it: an answer nobody signed is an answer '
                    .'nobody can be asked about.'
                );
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return ['author_role' => SupportAuthorRole::class];
    }

    /**
     * @return BelongsTo<SupportRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SupportRequest::class, 'support_request_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
