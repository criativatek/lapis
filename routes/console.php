<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Abandoned roster-import temp photo folders (uploaded, previewed, never
// confirmed) are never otherwise cleaned up — see
// App\Console\Commands\PruneRosterImportTempStorage's docblock.
Schedule::command('roster-imports:prune')->hourly();

// Correction grids uploaded into the import wizard and never confirmed. Same
// reasoning as above and the same promise: the uploaded file is not kept
// indefinitely. See App\Console\Commands\PruneCorrectionImportTempStorage.
Schedule::command('correction-imports:prune')->hourly();

// INOVAR grids uploaded, previewed, and never generated. Generating deletes the
// folder itself, so this only catches the abandoned ones — and an INOVAR grid
// holds names, process numbers and marks. See
// App\Console\Commands\PruneInovarExportTempStorage.
Schedule::command('inovar-exports:prune')->hourly();

// "Exportar os meus dados" ZIPs (Fatia 4, §27) — a convenience artifact, not
// a technical backup, kept only for the configured availability window
// (default 24h). See App\Console\Commands\PruneDataExports.
Schedule::command('data-exports:prune')->hourly();

// Backup restores uploaded, previewed, and never confirmed (Fatia 6) — a
// backup can carry real students' pseudonymised data, so it is not kept
// indefinitely either. See App\Console\Commands\PruneDataImports.
Schedule::command('data-imports:prune')->hourly();

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
    ->withoutOverlapping();

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
    ->withoutOverlapping();
