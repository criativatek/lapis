<?php

namespace App\Console\Commands;

use App\Actions\Support\AnonymiseSupportRequest;
use App\Actions\Support\ChangeSupportStatus;
use App\Models\SupportNotificationType;
use App\Models\SupportRequest;
use App\Models\SupportRequestStatus;
use App\Services\Audit\AuditLog;
use App\Support\Support\SupportNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * O RELÓGIO DA CENTRAL DE SUPORTE — três passos, todos idempotentes.
 *
 *  1. **23 dias à espera** → um lembrete, uma só vez (`waiting_reminder_sent_at`).
 *  2. **30 dias à espera** → resolve-se sozinho, com uma mensagem do sistema a
 *     dizer porquê. `auto_resolved` distingue-o de um fecho decidido por uma
 *     pessoa: sem essa coluna, a estatística de «pedidos resolvidos» misturaria
 *     trabalho feito com silêncio.
 *  3. **24 meses resolvido** → anonimização verdadeira.
 *
 * `open` E `in_progress` NÃO EXPIRAM. Um pedido que está à nossa espera não
 * caduca por o termos deixado parado — fechá-lo por inactividade seria fazer do
 * nosso atraso um fim de conversa.
 *
 * A SUSPENSÃO SÓ TRAVA O PASSO 3. Reter um pedido para efeitos legais não é
 * razão para deixar de responder a quem o escreveu, e por isso o lembrete e o
 * auto-resolve correm à mesma.
 *
 * UMA REQUEST POR UNIDADE DE TRABALHO. Cada uma na sua transação, com o erro
 * apanhado e contado: uma linha problemática não pode impedir as outras
 * quinhentas de serem tratadas. É a mesma disciplina de
 * `ExecuteAccountClosures`, e a razão de o comando devolver `FAILURE` quando
 * houve falhas — um agendador que reporta sucesso sobre trabalho que não fez é
 * pior do que um que falha.
 */
class SupportRetention extends Command
{
    protected $signature = 'support:retention
        {--dry-run : Mostra o que seria feito, sem escrever nada}';

    protected $description = 'Lembra, resolve por inatividade e anonimiza pedidos de suporte segundo a política de retenção';

    public function handle(
        SupportNotifier $notifier,
        ChangeSupportStatus $status,
        AnonymiseSupportRequest $anonymiser,
        AuditLog $audit,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $agora = Carbon::now();

        if ($dryRun) {
            $this->components->info('Simulação (--dry-run). Nada é escrito.');
        }

        $reminders = $this->reminders($notifier, $audit, $agora, $dryRun);
        $autoResolved = $this->autoResolve($status, $audit, $agora, $dryRun);
        [$anonymized, $skippedHold] = $this->anonymise($anonymiser, $agora, $dryRun);

        $failed = $reminders['failed'] + $autoResolved['failed'] + $anonymized['failed'];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Lembretes enviados</>', (string) $reminders['done']);
        $this->components->twoColumnDetail('<fg=gray>Resolvidos por inatividade</>', (string) $autoResolved['done']);
        $this->components->twoColumnDetail('<fg=gray>Anonimizados</>', (string) $anonymized['done']);
        $this->components->twoColumnDetail('<fg=gray>Retidos por suspensão</>', (string) $skippedHold);
        $this->components->twoColumnDetail('<fg=gray>Falhas</>', $failed === 0 ? '0' : '<fg=red>'.$failed.'</>');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Passo 1 — o lembrete dos 23 dias, uma vez por espera.
     *
     * @return array{done: int, failed: int}
     */
    protected function reminders(SupportNotifier $notifier, AuditLog $audit, Carbon $agora, bool $dryRun): array
    {
        $limite = $agora->copy()->subDays((int) config('retention.support_waiting_reminder_days'));

        $pedidos = SupportRequest::query()
            ->where('status', SupportRequestStatus::WaitingForUser)
            ->whereNull('waiting_reminder_sent_at')
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<=', $limite)
            ->whereNull('anonymized_at')
            ->get();

        return $this->each($pedidos, $dryRun, 'lembrete', function (SupportRequest $pedido) use ($notifier, $audit, $agora): void {
            // O carimbo é escrito HAJA OU NÃO entrega bem-sucedida: se o SMTP
            // falhar, a falha fica visível em `support_notification_deliveries`
            // e o operador reenvia dali. Voltar a tentar amanhã em silêncio
            // mandaria vários lembretes à mesma pessoa quando o servidor
            // recuperasse.
            $pedido->forceFill(['waiting_reminder_sent_at' => $agora])->save();

            $notifier->send($pedido, SupportNotificationType::WaitingReminder);

            $audit->recordPlatformWithoutCauser(
                'support.reminder_sent',
                'Lembrete de espera enviado no pedido de suporte '.$pedido->reference.'.',
                ['reference' => $pedido->reference, 'days' => (int) config('retention.support_waiting_reminder_days')],
            );
        });
    }

    /**
     * Passo 2 — os 30 dias sem resposta.
     *
     * @return array{done: int, failed: int}
     */
    protected function autoResolve(ChangeSupportStatus $status, AuditLog $audit, Carbon $agora, bool $dryRun): array
    {
        $limite = $agora->copy()->subDays((int) config('retention.support_waiting_auto_resolve_days'));

        $pedidos = SupportRequest::query()
            ->where('status', SupportRequestStatus::WaitingForUser)
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<=', $limite)
            ->whereNull('anonymized_at')
            ->get();

        return $this->each($pedidos, $dryRun, 'auto-resolve', function (SupportRequest $pedido) use ($status, $audit, $agora): void {
            $status->appendSystemNote($pedido, __(
                'Este pedido foi fechado automaticamente por não ter havido resposta durante :days dias. Se ainda precisar de ajuda, responda aqui e ele reabre.',
                ['days' => (int) config('retention.support_waiting_auto_resolve_days')],
            ));

            $pedido->forceFill([
                'status' => SupportRequestStatus::Resolved,
                'resolved_at' => $agora,
                // Ninguém o resolveu: foi o tempo. `resolved_by` fica nulo e
                // `auto_resolved` di-lo, em vez de se deduzir de um NULL.
                'resolved_by' => null,
                'auto_resolved' => true,
                'waiting_since' => null,
                'waiting_reminder_sent_at' => null,
            ])->save();

            $audit->recordPlatformWithoutCauser(
                'support.auto_resolved',
                'Pedido de suporte '.$pedido->reference.' resolvido por inatividade.',
                [
                    'reference' => $pedido->reference,
                    'by' => 'system',
                    'from' => SupportRequestStatus::WaitingForUser->value,
                    'to' => SupportRequestStatus::Resolved->value,
                ],
            );
        });
    }

    /**
     * Passo 3 — os 24 meses, e o que a suspensão trava.
     *
     * @return array{0: array{done: int, failed: int}, 1: int}
     */
    protected function anonymise(AnonymiseSupportRequest $anonymiser, Carbon $agora, bool $dryRun): array
    {
        $limite = $agora->copy()->subMonths((int) config('retention.support_resolved_months_retained'));

        // Os retidos: fora da janela, mas com suspensão em vigor. Contados e
        // mostrados, nunca tocados — é a excepção a funcionar, não um erro.
        $retidos = SupportRequest::query()
            ->whereNull('anonymized_at')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $limite)
            ->whereNotNull('retention_hold_at')
            ->whereNull('retention_hold_released_at')
            ->count();

        $pedidos = SupportRequest::query()->anonymisable($agora)->get();

        return [
            $this->each($pedidos, $dryRun, 'anonimização', fn (SupportRequest $pedido) => $anonymiser->execute($pedido)),
            $retidos,
        ];
    }

    /**
     * Percorre os pedidos um a um, contando o que correu bem e o que falhou.
     *
     * O `--dry-run` mostra APENAS a referência e o ULID — nunca o assunto, a
     * descrição ou o email de quem escreveu. Um comando de inspecção que imprime
     * conteúdo é uma fuga de dados com aspecto de diagnóstico, e o registo do
     * agendador fica em disco.
     *
     * @param  Collection<int, SupportRequest>  $pedidos
     * @return array{done: int, failed: int}
     */
    protected function each($pedidos, bool $dryRun, string $passo, callable $trabalho): array
    {
        $done = 0;
        $failed = 0;

        foreach ($pedidos as $pedido) {
            if ($dryRun) {
                $this->line("  · {$passo}: {$pedido->reference} ({$pedido->ulid})");
                $done++;

                continue;
            }

            try {
                $trabalho($pedido);
                $done++;
            } catch (Throwable $exception) {
                // A falha de um pedido não pode levar os outros atrás. O
                // detalhe vai para o log; aqui fica a contagem e a referência,
                // que não identifica ninguém.
                $failed++;
                report($exception);
                $this->components->warn("Falhou o passo {$passo} em {$pedido->reference}.");
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}
