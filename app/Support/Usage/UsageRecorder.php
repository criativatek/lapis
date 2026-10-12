<?php

namespace App\Support\Usage;

use App\Models\UsageEvent;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava as métricas de utilização (docs/usage-insights.md).
 *
 * TRÊS PROMESSAS, por esta ordem:
 *
 * 1. NUNCA IMPEDE O TRABALHO DO PROFESSOR. Tudo o que está aqui corre dentro
 *    de um `try/catch (Throwable)`, e o callback do `afterCommit` tem o seu
 *    próprio. Uma falha vai para o log como `usage.record_failed`, só com o
 *    nome do evento e a CLASSE da exceção — nunca a mensagem, que pode trazer
 *    SQL com valores.
 * 2. SÓ DEPOIS DO COMMIT. A escrita é um `DB::afterCommit`: uma transação
 *    revertida não deixa rasto.
 * 3. SÓ O QUE ESTÁ NO CATÁLOGO. Não há propriedades livres; a assinatura não
 *    tem onde as receber.
 *
 * Sai em silêncio quando não há pedido com utilizador autenticado (consola,
 * scheduler, jobs), durante um acesso técnico (impersonação), para quem se
 * opôs à recolha, para contas desativadas ou anonimizadas, e quando não há
 * organização resolvida.
 */
class UsageRecorder
{
    public function __construct(private readonly UsageCoverage $coverage) {}

    /** A fotografia não se conseguiu ler (ver snapshot()). */
    public const UNAVAILABLE = 'unavailable';

    /**
     * Uma ação concluída, contada uma vez por registo e por dia.
     *
     * Um lote pode discriminar o que lhe aconteceu (`created`, `changed`,
     * `unchanged`): os elementos afetados são então criados + alterados, e os
     * inalterados dizem só quantos vieram no lote sem mudar nada. Se nada foi
     * criado nem alterado, não houve ação e nada se grava.
     *
     * @param  Model|string|null  $subject  O registo (ou uma chave de lote) que identifica «a mesma ação» no dia.
     */
    public function action(
        UsageEventType $type,
        Model|string|null $subject = null,
        int $items = 1,
        ?int $created = null,
        ?int $changed = null,
        ?int $unchanged = null,
    ): void {
        try {
            $broken = $created !== null || $changed !== null || $unchanged !== null;

            // O que não foi passado fica NULO, não zero: «não sei quantos ficaram
            // iguais» não é «nenhum ficou igual». Os elementos afetados são os
            // criados mais os alterados que se conhecem.
            if ($broken) {
                $created = $created === null ? null : max(0, $created);
                $changed = $changed === null ? null : max(0, $changed);
                $unchanged = $unchanged === null ? null : max(0, $unchanged);
                $items = ($created ?? 0) + ($changed ?? 0);
            }

            if ($type->kind() !== UsageEventKind::Action || $type->feature() === null || $items < 1) {
                return;
            }

            $this->record($type, $type->feature(), [
                $type->value,
                $this->subjectKey($subject),
            ], itemCount: $items, breakdown: $broken ? [
                'items_created' => $created,
                'items_changed' => $changed,
                'items_unchanged' => $unchanged,
            ] : []);
        } catch (Throwable $exception) {
            $this->report($type, $exception);
        }
    }

    /** Uma consulta deliberada, no máximo uma por utilizador, funcionalidade e dia. */
    public function view(UsageFeature $feature): void
    {
        try {
            $this->record(UsageEventType::FeatureViewed, $feature, ['view', $feature->value]);
        } catch (Throwable $exception) {
            $this->report(UsageEventType::FeatureViewed, $exception);
        }
    }

    /**
     * Uma falha técnica. Repetições do mesmo pedido falhado no mesmo minuto contam uma vez.
     */
    public function failure(UsageFeature $feature, UsageFailureCode $code, string $operation): void
    {
        try {
            $this->record(UsageEventType::OperationFailed, $feature, [
                'failure',
                $feature->value,
                $operation,
                $code->value,
                now()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
            ], failureCode: $code, minuteKey: true);
        } catch (Throwable $exception) {
            $this->report(UsageEventType::OperationFailed, $exception);
        }
    }

    /**
     * Corre um cálculo AO SERVIÇO da telemetria — a impressão digital antes e
     * depois, a gravação anterior para comparar — sem que uma falha dele chegue
     * ao professor. Uma leitura que falhasse ANTES da operação impediria a
     * gravação; DEPOIS, devolveria um erro sobre um trabalho já gravado. Em
     * qualquer dos casos devolve `$fallback` e regista `usage.record_failed`.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue|mixed
     */
    public function attempt(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->report(null, $exception);

            return $fallback;
        }
    }

    /**
     * Uma impressão digital de campos de um modelo, para só contar a gravação
     * quando algo mudou. Nulo quando não há modelo (ainda não existia).
     *
     * Uma entrada de chave inteira é o NOME de um atributo lido do modelo; uma
     * entrada de chave textual é um valor literal (para juntar o estado de um
     * segundo modelo). Compara-se o resultado antes e depois da operação.
     *
     * @param  array<int|string, mixed>  $attributes
     */
    public function fingerprint(?Model $model, array $attributes): ?string
    {
        if ($model === null) {
            return null;
        }

        $values = [];

        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                $name = (string) $value;
                $values[$name] = $model->getAttribute($name);
            } else {
                $values[$key] = $value;
            }
        }

        return sha1((string) json_encode($values));
    }

    /**
     * Uma fotografia do estado de um conjunto de registos, calculada em memória
     * (nunca guardada) para só contar uma edição que mudou mesmo alguma coisa.
     * Devolve UNAVAILABLE quando a leitura falha; `changed()` trata isso como
     * «não mudou», de qualquer dos lados.
     *
     * @param  callable(): mixed  $compute
     */
    public function snapshot(callable $compute): string
    {
        // Quando nada vai ser registado — recolha desligada, acesso técnico,
        // oposição, encerramento, sem utilizador ou sem organização — o cálculo
        // nem corre: ler um relatório ou a identidade de um aluno para uma
        // impressão digital que se deita fora seria tratar dados sem finalidade.
        if (! $this->isRecording()) {
            return self::UNAVAILABLE;
        }

        $result = $this->attempt(
            fn (): string => sha1((string) json_encode($compute(), JSON_INVALID_UTF8_SUBSTITUTE)),
            self::UNAVAILABLE,
        );

        return is_string($result) ? $result : self::UNAVAILABLE;
    }

    public function changed(string $before, string $after): bool
    {
        return $before !== self::UNAVAILABLE && $after !== self::UNAVAILABLE && $before !== $after;
    }

    /**
     * Os atributos de um registo sem identificadores nem marcas de tempo, para
     * entrar numa fotografia. Vazio quando o registo não existe.
     *
     * @param  list<string>  $except
     * @return array<string, mixed>
     */
    public function stateOf(?Model $model, array $except = []): array
    {
        if ($model === null) {
            return [];
        }

        return array_diff_key(
            $model->getAttributes(),
            array_flip(['id', 'ulid', 'created_at', 'updated_at', ...$except]),
        );
    }

    /**
     * Se este pedido PODE registar alguma coisa — as mesmas condições do
     * registo, sem abrir o período. Os ganchos perguntam-no antes de qualquer
     * leitura auxiliar (ver `snapshot()`).
     */
    public function isRecording(): bool
    {
        try {
            return $this->recordingUser() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * O utilizador cuja utilização este pedido regista, ou nulo se não há nada
     * a registar: recolha desligada, sem pedido com utilizador (consola,
     * agendador, jobs), acesso técnico, oposição, conta anonimizada,
     * desativada ou com encerramento pedido, ou sem organização resolvida.
     */
    private function recordingUser(): ?User
    {
        if (! config('usage.enabled')) {
            return null;
        }

        $request = request();
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        if ($request->hasSession() && $request->session()->has('impersonator_id')) {
            return null;
        }

        // A partir do pedido de encerramento, nada mais é registado.
        if ($user->usage_opted_out_at !== null || $user->anonymized_at !== null || $user->deactivated_at !== null || $user->closure_requested_at !== null) {
            return null;
        }

        if (! app(CurrentOrganization::class)->isResolved()) {
            return null;
        }

        return $user;
    }

    /**
     * @param  list<string>  $dedupeParts  O que identifica «o mesmo registo» além de utilizador e organização.
     * @param  array<string, int>  $breakdown  A discriminação de um lote, quando há.
     */
    private function record(
        UsageEventType $type,
        UsageFeature $feature,
        array $dedupeParts,
        ?int $itemCount = null,
        ?UsageFailureCode $failureCode = null,
        bool $minuteKey = false,
        array $breakdown = [],
    ): void {
        $user = $this->recordingUser();

        if ($user === null) {
            return;
        }

        $tenant = app(CurrentOrganization::class);

        // O dia é o dia de calendário LOCAL, qualquer que seja o fuso do relógio.
        $now = now()->setTimezone((string) config('app.timezone'));

        // A ATIVAÇÃO EFETIVA: nada é gravado antes de existir um período de
        // recolha aberto, e o primeiro registo possível abre-o neste instante.
        $this->coverage->ensureOpen($now);
        $day = $now->toDateString();
        $organizationId = $tenant->id();

        // Consultas e ações fecham a chave com o dia; a falha já traz o minuto.
        $parts = [$user->getKey(), $organizationId, ...$dedupeParts];

        if (! $minuteKey) {
            $parts[] = $day;
        }

        $row = [
            'user_id' => $user->getKey(),
            'organization_id' => $organizationId,
            'occurred_at' => $now->format('Y-m-d H:i:s'),
            'occurred_on' => $day,
            'feature' => $feature->value,
            'event' => $type->value,
            'kind' => $type->kind()->value,
            'item_count' => $itemCount,
            'items_created' => $breakdown['items_created'] ?? null,
            'items_changed' => $breakdown['items_changed'] ?? null,
            'items_unchanged' => $breakdown['items_unchanged'] ?? null,
            'failure_code' => $failureCode?->value,
            'dedupe_key' => hash_hmac('sha256', implode('|', $parts), $this->dedupeSecret()),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ];

        DB::afterCommit(function () use ($row, $type): void {
            try {
                UsageEvent::query()->insertOrIgnore($row);
            } catch (Throwable $exception) {
                $this->report($type, $exception);
            }
        });
    }

    private function subjectKey(Model|string|null $subject): string
    {
        if ($subject instanceof Model) {
            return class_basename($subject).':'.$subject->getKey();
        }

        return $subject ?? '';
    }

    private function dedupeSecret(): string
    {
        return hash('sha256', 'lapis.usage.dedupe|'.config('app.key'));
    }

    /** Sem tipo quando a falha foi num cálculo auxiliar (`attempt()`). */
    private function report(?UsageEventType $type, Throwable $exception): void
    {
        try {
            Log::warning('usage.record_failed', [
                'event' => $type->value ?? 'attempt',
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nem o log pode impedir o trabalho do professor.
        }
    }
}
