<?php

namespace App\Console\Commands;

use App\Actions\Accounts\AnonymiseClosedAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Carries out the account closures whose recovery window has ended.
 *
 * THIS IS THE HALF THAT WAS MISSING. Asking to close an account, the 60-day
 * window, `scheduled_deletion_at`, the banners and `retention:status` all
 * existed — and nothing ever executed. A person could ask for their account to
 * be closed, watch the countdown reach zero, and their data stayed exactly
 * where it was, indefinitely.
 *
 * `retention:status` stays what it was: a read-only preview that never
 * changes anything. This is its counterpart, and the only command in the
 * application that removes a person's identity.
 *
 * IT PROCESSES ONE ACCOUNT AT A TIME, and a failure on one does not stop the
 * rest. The alternative — one transaction over every due account — means the
 * hundredth account's locked photo file rolls back the ninety-nine closures
 * that had already succeeded, and none of them get carried out until somebody
 * notices. Each account is its own unit of work; what fails is logged, counted,
 * and retried on the next run, because the operation is idempotent.
 */
class ExecuteAccountClosures extends Command
{
    protected $signature = 'retention:execute
        {--dry-run : Conta e lista quantas contas seriam encerradas, sem alterar nada}
        {--limit=200 : Máximo de contas a processar nesta execução}';

    protected $description = 'Executa o encerramento das contas cuja janela de recuperação terminou';

    public function __construct(protected AnonymiseClosedAccount $anonymise)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $due = $this->due($limit);

        if ($due === []) {
            $this->info('Nenhuma conta com a janela de encerramento terminada.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            // Counts and internal ids only. A dry run is something an operator
            // runs to decide whether to proceed — it must not become a way of
            // reading the names and emails of people who asked to be forgotten.
            $this->info(count($due).' conta(s) seriam encerradas:');

            foreach ($due as $user) {
                $this->line("  utilizador #{$user->getKey()} — pedido em ".$user->closure_requested_at?->toDateString());
            }

            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;

        foreach ($due as $user) {
            try {
                $removed = $this->anonymise->execute($user);
                $done++;

                $this->line("  utilizador #{$user->getKey()} encerrado — ".collect($removed)
                    ->map(fn (int $count, string $what): string => "{$what}={$count}")
                    ->implode(' '));
            } catch (\Throwable $exception) {
                $failed++;

                // The id, never the person. Whoever reads this log is
                // debugging a failure, not looking somebody up.
                Log::error('Falha ao executar o encerramento de uma conta.', [
                    'user_id' => $user->getKey(),
                    'exception' => $exception->getMessage(),
                ]);

                $this->error("  utilizador #{$user->getKey()}: {$exception->getMessage()}");
            }
        }

        $this->info("Encerramentos executados: {$done}. Falhas: {$failed}.");

        // A non-zero exit so a failure is visible to whatever runs this, instead
        // of a scheduler quietly reporting DONE over a closure that never
        // happened.
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The accounts this run will act on.
     *
     * The narrowing happens in SQL — closure requested, not yet anonymised —
     * and the final word is `AnonymiseClosedAccount::isDue()`, which asks
     * `ClosureRetention` the same question the banners and `retention:status`
     * ask. The window is never re-derived here.
     *
     * @return list<User>
     */
    protected function due(int $limit): array
    {
        $due = [];

        $candidates = User::query()
            ->whereNotNull('closure_requested_at')
            ->whereNull('anonymized_at')
            ->orderBy('closure_requested_at')
            ->limit($limit)
            ->get();

        foreach ($candidates as $user) {
            if ($this->anonymise->isDue($user)) {
                $due[] = $user;
            }
        }

        return $due;
    }
}
