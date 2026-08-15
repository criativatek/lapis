<?php

namespace App\Console\Commands;

use App\Support\Release\BuildStamp;
use Illuminate\Console\Command;

/**
 * Says what is running, and — when told what to expect — refuses to agree when
 * it is something else. Run on the server as the last step of a deploy
 * (docs/deployment.md).
 *
 * The version comes from the BOOTED configuration rather than from reading
 * config/app.php, deliberately: a stale bootstrap/cache/config.php serves the
 * old version while the file on disk shows the new one, and it is precisely that
 * disagreement a post-deploy check exists to catch (armadilha 1).
 *
 * The commit comes from the stamp written when the package was built. Without a
 * stamp the command says so and fails rather than guessing — "unknown" reported
 * as a mismatch is recoverable; a guess reported as a match is not.
 */
class ReleaseCheckCommand extends Command
{
    protected $signature = 'lapis:release-check
        {--expect-version= : Fail unless the running version is exactly this}
        {--expect-commit= : Fail unless the running commit starts with this}';

    protected $description = 'Report the running version and commit, and verify them against what was deployed';

    public function handle(): int
    {
        $runningVersion = (string) config('app.version');
        $stamp = BuildStamp::read();
        $runningCommit = $stamp?->commit;

        $expectedVersion = $this->stringOption('expect-version');
        $expectedCommit = $this->stringOption('expect-commit');

        $this->line('LÁPIS');
        $this->line('  running version: '.($runningVersion !== '' ? $runningVersion : '(por declarar)'));
        $this->line('  running commit:  '.($runningCommit ?? '(sem carimbo)'));

        if ($stamp !== null) {
            $this->line('  built at:        '.$stamp->builtAt);
        }

        $problems = [];

        if ($expectedVersion !== null && $runningVersion !== $expectedVersion) {
            $problems[] = "versão: esperada {$expectedVersion}, a correr ".($runningVersion !== '' ? $runningVersion : '(nenhuma)');
        }

        if ($expectedCommit !== null) {
            if ($runningCommit === null) {
                $problems[] = "commit: esperado {$expectedCommit}, mas não há carimbo — o pacote foi construído sem `lapis:build-stamp`";
            } elseif (! str_starts_with($runningCommit, $expectedCommit)) {
                $problems[] = "commit: esperado {$expectedCommit}, a correr ".substr($runningCommit, 0, 7);
            }
        }

        // A stamp whose version disagrees with the running config means the
        // package was assembled from one state and is running as another —
        // worth saying even when nobody asked for a comparison.
        if ($stamp !== null && $runningVersion !== '' && $stamp->version !== $runningVersion) {
            $problems[] = "o carimbo diz {$stamp->version} mas a aplicação corre {$runningVersion}";
        }

        if ($problems !== []) {
            $this->newLine();
            $this->error('O que está a correr não é o que se esperava:');

            foreach ($problems as $problem) {
                $this->error("  - {$problem}");
            }

            return self::FAILURE;
        }

        if ($expectedVersion === null && $expectedCommit === null) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Confere: a aplicação está a correr o que foi enviado.');

        return self::SUCCESS;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
