<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Every cleanup task states its own failure, in one grep-able line.
 *
 * NOT belt and braces — the braces do not hold. `scheduler.log`'s DONE/FAIL
 * column cannot report a failure at all in the Laravel installed here:
 * ScheduleRunCommand::runEvent() hands `$event->exitCode == 0` (a bool) to
 * Task::render(), which matches it STRICTLY against TaskResult::Failure->value
 * (the int 2) and falls through to `default => DONE`. A bool is never
 * identical to an int, so the FAIL branch is unreachable and every scheduled
 * task prints DONE whether it worked or not.
 *
 * That is exactly how `data-imports:prune` failed 22 hours in a row on
 * 2026-08-30 with `DONE=24, FAIL=0` in the log an operator was told to trust
 * (docs/deployment.md). Laravel does separately log "Scheduled command [...]
 * failed with exit code [1]" through the exception handler; this adds the
 * stable, structured event next to it, so a failure can be found by name
 * rather than by recognising a stack trace.
 *
 * Do not replace this with a vendor patch.
 */
$reportFailure = static fn (string $command): Closure => static function () use ($command): void {
    Log::error('scheduler.task_failed', ['command' => $command]);
};

// Abandoned roster-import temp photo folders (uploaded, previewed, never
// confirmed) are never otherwise cleaned up — see
// App\Console\Commands\PruneRosterImportTempStorage's docblock.
Schedule::command('roster-imports:prune')
    ->hourly()
    ->onFailure($reportFailure('roster-imports:prune'));

// Correction grids uploaded into the import wizard and never confirmed. Same
// reasoning as above and the same promise: the uploaded file is not kept
// indefinitely. See App\Console\Commands\PruneCorrectionImportTempStorage.
Schedule::command('correction-imports:prune')
    ->hourly()
    ->onFailure($reportFailure('correction-imports:prune'));

// INOVAR grids uploaded, previewed, and never generated. Generating deletes the
// folder itself, so this only catches the abandoned ones — and an INOVAR grid
// holds names, process numbers and marks. See
// App\Console\Commands\PruneInovarExportTempStorage.
Schedule::command('inovar-exports:prune')
    ->hourly()
    ->onFailure($reportFailure('inovar-exports:prune'));

// "Exportar os meus dados" ZIPs (Fatia 4, §27) — a convenience artifact, not
// a technical backup, kept only for the configured availability window
// (default 24h). See App\Console\Commands\PruneDataExports.
Schedule::command('data-exports:prune')
    ->hourly()
    ->onFailure($reportFailure('data-exports:prune'));

// Backup restores uploaded, previewed, and never confirmed (Fatia 6) — a
// backup can carry real students' pseudonymised data, so it is not kept
// indefinitely either. See App\Console\Commands\PruneDataImports.
Schedule::command('data-imports:prune')
    ->hourly()
    ->onFailure($reportFailure('data-imports:prune'));

// The account closures whose 60-day recovery window has ended. Until this line
// existed, asking to close an account started a countdown that reached zero and
// did nothing — see App\Console\Commands\ExecuteAccountClosures.
//
// DAILY, NOT HOURLY: the window is measured in days, so running it twenty-four
// times a day buys nothing and gives twenty-four chances a day to hit the same
// locked file. 03:40 (Europe/Lisbon, the application's timezone) is quiet and
// deliberately not on the hour, away from the hourly prunes above.
//
// `withoutOverlapping()` because a run that meets a slow filesystem must not be
// joined by the next one working the same account: the operation is idempotent,
// but two processes anonymising the same row is not something to rely on.
Schedule::command('retention:execute')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onFailure($reportFailure('retention:execute'));

// O relógio da Central de Suporte: o lembrete dos 23 dias, o auto-resolve dos
// 30 e a anonimização dos 24 meses (ADR-0011 §7).
//
// DEZ MINUTOS DEPOIS do encerramento de contas, e não à mesma hora: os dois
// comandos podem tocar nas mesmas contas — um pedido de suporte aponta para um
// `user_id` que o outro está a anonimizar — e correr em série evita que a
// ordem entre eles seja uma questão de sorte. Fora da hora certa pela mesma
// razão que o vizinho documenta.
//
// `withoutOverlapping()` porque o passo dos 24 meses apaga mensagens: a
// operação é idempotente, mas dois processos a apagar as mesmas linhas não é
// coisa em que se confie.
Schedule::command('support:retention')
    ->dailyAt('03:50')
    ->withoutOverlapping()
    ->onFailure($reportFailure('support:retention'));
