<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Um pedido de ajuda, e o fio de conversa que dele nasce.
 *
 * FORA DA TENANCY, como `Voucher` e `FounderSeat`. Um pedido é da PESSOA e da
 * plataforma, não do inquilino — `organization_id` está aqui para o operador
 * saber de onde veio, e **nunca** para decidir quem o pode ver. Quem decide
 * isso é `SupportRequestPolicy`, e a única chave do lado do requerente é
 * `user_id`. É a coluna deste modelo que mais se parece com tenancy e não é.
 *
 * OS CAMPOS IDENTIFICANTES SÃO NULLABLE POR DESENHO. Passados 24 meses sobre
 * `resolved_at`, `AnonymiseSupportRequest` põe-nos a NULL de verdade e apaga as
 * mensagens — sem marcas de substituição (ADR-0011 §8). A obrigatoriedade vive
 * nas FormRequests, que é onde ela é verdadeira.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference
 * @property string|null $requester_name
 * @property string|null $requester_email
 * @property int|null $user_id
 * @property int|null $organization_id
 * @property SupportSource $source
 * @property SupportCategory $category
 * @property string|null $subject
 * @property string|null $description
 * @property SupportRequestStatus $status
 * @property string|null $technical_reference
 * @property string|null $technical_route
 * @property SupportTechnicalCode|null $technical_code
 * @property string $app_version
 * @property Carbon|null $waiting_since
 * @property Carbon|null $waiting_reminder_sent_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property bool $auto_resolved
 * @property Carbon|null $retention_hold_at
 * @property int|null $retention_hold_by
 * @property RetentionHoldReason|null $retention_hold_reason_code
 * @property string|null $retention_hold_note
 * @property Carbon|null $retention_hold_released_at
 * @property int|null $retention_hold_released_by
 * @property Carbon|null $anonymized_at
 */
#[Fillable([
    'reference', 'requester_name', 'requester_email', 'user_id', 'organization_id',
    'source', 'category', 'subject', 'description', 'status',
    'technical_reference', 'technical_route', 'technical_code', 'app_version',
    'client_context',
])]
class SupportRequest extends Model
{
    use HasUlids;

    /**
     * A regra que o MySQL não deixa gravar no esquema.
     *
     * «Um pedido de visitante nunca tem conta» seria uma CHECK constraint
     * natural, e o MySQL recusa-a (erro 3823): `user_id` participa numa chave
     * estrangeira com `SET NULL`, porque um pedido tem de sobreviver ao
     * apagamento da conta que o abriu, e uma coluna com acção referencial não
     * pode aparecer numa CHECK. Das duas garantias, a que fica é a que protege
     * o histórico — e esta passa para aqui, onde cobre toda a escrita em vez de
     * apenas a que passa por uma acção.
     */
    protected static function booted(): void
    {
        static::saving(function (self $request): void {
            if ($request->source === SupportSource::Guest && $request->user_id !== null) {
                throw new LogicException(
                    'A guest support request never carries an account: it was opened without a session, and '
                    .'attaching one afterwards would hand the history to whoever proved only to own the address.'
                );
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'source' => SupportSource::class,
            'category' => SupportCategory::class,
            'status' => SupportRequestStatus::class,
            'technical_code' => SupportTechnicalCode::class,
            'retention_hold_reason_code' => RetentionHoldReason::class,
            'client_context' => 'array',
            'waiting_since' => 'datetime',
            'waiting_reminder_sent_at' => 'datetime',
            'resolved_at' => 'datetime',
            'auto_resolved' => 'boolean',
            'retention_hold_at' => 'datetime',
            'retention_hold_released_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupportNotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(SupportNotificationDelivery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    /**
     * O PRIMEIRO NOME DE QUEM PEDIU — para o email poder cumprimentar alguém.
     *
     * O nome completo é o que a pessoa escreveu, no formulário público, ou o
     * que tem na conta. Só o primeiro token sai para o email: um cumprimento é
     * «Olá, Maria» e não «Olá, Maria Antunes Ferreira da Silva», e cortar aqui
     * evita que uma saudação se transforme numa ficha de identificação.
     *
     * NULL quando o pedido já foi anonimizado, ou quando o que sobrou não tem
     * uma única letra — e nesse caso o email cumprimenta sem nome em vez de
     * inventar um. Ver o template, que trata os dois casos.
     */
    public function requesterFirstName(): ?string
    {
        $nome = trim((string) $this->requester_name);

        if ($nome === '') {
            return null;
        }

        // `\s+` em modo unicode apanha também o espaço duro que um copiar-colar
        // traz do Word. Se a divisão falhar — um nome que não seja UTF-8
        // válido —, fica o nome inteiro: cumprimentar com um nome comprido é
        // pior do que cumprimentar sem nenhum, mas melhor do que rebentar.
        $primeiro = (preg_split('/\s+/u', $nome) ?: [$nome])[0];

        return $primeiro === '' ? null : $primeiro;
    }

    /**
     * A CONFIRMAÇÃO DE RECEÇÃO CHEGOU A SAIR?
     *
     * Pergunta-o à tabela de entregas, que é o registo durável do que aconteceu
     * ao aviso — a mesma linha que o backoffice lista por entregar e que o
     * botão «Reenviar» volta a tentar. Depois de um reenvio com sucesso isto
     * passa a `true` sem que mais nada tenha de ser actualizado, porque não há
     * segunda cópia deste facto em lado nenhum.
     *
     * É POR ISTO QUE O ECRÃ NÃO MENTE. Dizer «enviámos uma confirmação para o
     * seu email» quando o servidor recusou a mensagem põe a pessoa à espera de
     * algo que não vem — e, quando não vier, a duvidar do pedido inteiro, que
     * ficou perfeitamente registado. A pergunta vai à base de dados e não à
     * relação já carregada em memória: quem a faz fá-la logo a seguir ao envio
     * e precisa do estado de agora, não do que estava carregado antes dele.
     */
    public function acknowledgementWasDelivered(): bool
    {
        return $this->deliveries()
            ->where('notification_type', SupportNotificationType::RequestReceived)
            ->whereNotNull('delivered_at')
            ->exists();
    }

    public function isAnonymised(): bool
    {
        return $this->anonymized_at !== null;
    }

    /** Um hold aplicado e ainda não libertado. */
    public function hasActiveHold(): bool
    {
        return $this->retention_hold_at !== null && $this->retention_hold_released_at === null;
    }

    /**
     * Já passaram os 24 meses desde a resolução?
     *
     * Contados SEMPRE de `resolved_at`, que é o que faz um hold libertado tarde
     * ser anonimizado na execução seguinte em vez de ganhar dois anos novos. Um
     * pedido reaberto tem `resolved_at` a NULL e por isso não conta tempo
     * nenhum — ADR-0011 §12.
     */
    public function isBeyondRetention(?Carbon $at = null): bool
    {
        if ($this->resolved_at === null) {
            return false;
        }

        $limite = $this->resolved_at->copy()->addMonths(
            (int) config('retention.support_resolved_months_retained')
        );

        return ($at ?? Carbon::now())->greaterThanOrEqualTo($limite);
    }

    /**
     * Os pedidos que a anonimização deve considerar: resolvidos, fora da
     * janela, sem hold activo e ainda por anonimizar.
     *
     * @param  Builder<SupportRequest>  $query
     * @return Builder<SupportRequest>
     */
    public function scopeAnonymisable(Builder $query, ?Carbon $at = null): Builder
    {
        $limite = ($at ?? Carbon::now())->copy()
            ->subMonths((int) config('retention.support_resolved_months_retained'));

        return $query
            ->whereNull('anonymized_at')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $limite)
            ->where(function (Builder $sem) {
                // Sem hold, ou com um hold já libertado. Libertar não reinicia
                // o relógio: a comparação acima continua a ser com `resolved_at`.
                $sem->whereNull('retention_hold_at')
                    ->orWhereNotNull('retention_hold_released_at');
            });
    }
}
