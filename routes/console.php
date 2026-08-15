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
