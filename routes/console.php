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
