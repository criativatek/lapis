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
