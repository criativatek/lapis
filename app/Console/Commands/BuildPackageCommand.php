<?php

namespace App\Console\Commands;

use App\Support\Release\BuildStamp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Builds the deploy package (docs/deployment.md), and stamps it.
 *
 * The package is assembled from the files GIT KNOWS ABOUT, not from whatever
 * happens to be sitting in the directory. That inversion is the whole point.
 *
 * The previous procedure was a `tar` with a list of `--exclude` flags: it packed
 * the working directory and removed the things somebody had remembered to name.
 * tar does not read .gitignore, so anything nobody thought of travelled. It is
 * how six megabytes of AI tooling reached production, how a local storage/app
 * polluted real student photos, and how four non-anonymised student exports sat
 * on the production server from 31 July. Every one of those was a file nobody
 * put on the list — which is exactly what a denylist cannot protect against.
 *
 * So the rule is now the other way round: nothing enters unless it is either
 * tracked in git or one of two deliberately generated artefacts (the build stamp
 * and the compiled assets, both gitignored on purpose). A new untracked file
 * cannot reach production by being forgotten, because being forgotten is now the
 * safe state.
 *
 * The command also refuses to run unless the repository matches HEAD exactly. A
 * package is supposed to BE a commit; if the tree has drifted from HEAD — in the
 * working tree or merely staged — then whatever the stamp claims is a fiction.
 */
class BuildPackageCommand extends Command
{
    protected $signature = 'lapis:build-package
        {--output=update.tgz : Archive path, relative to the project root}
        {--allow-dirty : Build even when the repository does not match HEAD (never for a real deploy)}';

    protected $description = 'Build the production package from the tracked tree, stamped with the current commit';

    /**
     * Generated, gitignored, and required in production. The only two things in
     * the package that git does not know about — listed here so that "what is in
     * the package and why" is one readable answer.
     *
     * @var list<string>
     */
    protected const GENERATED = [
        BuildStamp::FILENAME,
        'public/build',
    ];

    /**
     * Checked against the finished archive. Almost none of these can come from
     * `git ls-files` — they are gitignored or untracked — so for those this is
     * not the defence, it is the proof that the defence worked.
     *
     * The exception is the `.env.*.example` templates, which ARE tracked. What
     * this rule is for is an environment file with real values in it; a template
     * carries none by definition, and `.env.example` has always travelled.
     *
     * @var array<string, string>
     */
    protected const MUST_NOT_CONTAIN = [
        '#^\.env$#' => '.env',
        '#^\.env\.(?!.*\.example$|example$)#' => '.env.* (exceto os .example)',
        '#^storage/app/(?!.*\.gitignore$)#' => 'conteúdo de storage/app',
        '#^storage/logs/(?!.*\.gitignore$)#' => 'conteúdo de storage/logs',
        '#^\.phpunit\.result\.cache$#' => '.phpunit.result.cache',
        '#^skills-lock\.json$#' => 'skills-lock.json',
        '#^AGENTS\.md\.local-backup-#' => 'AGENTS.md.local-backup-*',
        '#^Ficheiros avulsos/#' => 'Ficheiros avulsos/',
        '#^Grelhas de correção/#' => 'Grelhas de correção/',
        '#^(\.git|node_modules|vendor)/#' => 'pastas de desenvolvimento',
        // Armadilha 1 was the generated cache (config.php, packages.php, …),
        // which is gitignored and therefore now unreachable by construction. The
        // tracked .gitignore placeholder that defines the directory is fine.
        '#^bootstrap/cache/(?!\.gitignore$)#' => 'cache de bootstrap gerado',
    ];

    /**
     * Without these the deployed application does not boot or does not serve.
     *
     * @var list<string>
     */
    protected const MUST_CONTAIN = [
        'artisan',
        'composer.json',
        'composer.lock',
        'config/app.php',
        'public/index.php',
        'public/build/manifest.json',
        BuildStamp::FILENAME,
    ];

    public function handle(): int
    {
        $commit = $this->git(['rev-parse', 'HEAD']);

        if ($commit === null) {
            $this->error('Não foi possível ler o commit — este comando corre no repositório.');

            return self::FAILURE;
        }

        if (! $this->repositoryMatchesHead()) {
            return self::FAILURE;
        }

        $stamp = new BuildStamp(
            version: (string) config('app.version'),
            commit: $commit,
            builtAt: Date::now()->toIso8601String(),
        );
        $stamp->write();
        $this->line("Carimbo: versão {$stamp->version}, commit {$stamp->shortCommit()}");

        $paths = $this->packageList();

        if ($paths === null) {
            return self::FAILURE;
        }

        $output = (string) $this->option('output');

        if (! $this->archive($paths, $output)) {
            return self::FAILURE;
        }

        return $this->verify($output, count($paths));
    }

    /**
     * The package must BE a commit. Anything else — a stray edit, a file merely
     * staged, a tracked file deleted and not committed — means the archive and
     * the stamp would describe different things.
     */
    protected function repositoryMatchesHead(): bool
    {
        // --untracked-files=no on purpose: untracked files are allowed to exist
        // (there are deliberately local-only files here) and simply never enter
        // the package, so they are not a reason to refuse.
        $drift = $this->git(['status', '--porcelain', '--untracked-files=no']);

        if ($drift === '' || $drift === null) {
            return true;
        }

        if ($this->option('allow-dirty')) {
            $this->warn('ATENÇÃO: o repositório não corresponde ao HEAD e --allow-dirty foi usado.');

            return true;
        }

        $this->error('O repositório não corresponde ao HEAD, por isso o pacote não seria este commit:');
        $this->line($drift);
        $this->line('Committa (ou reverte) antes de empacotar.');

        return false;
    }

    /**
     * Tracked files, plus the two generated artefacts. Nothing else is reachable.
     *
     * @return list<string>|null
     */
    protected function packageList(): ?array
    {
        $tracked = $this->git(['ls-files', '-z']);

        if ($tracked === null) {
            $this->error('Não foi possível listar os ficheiros versionados.');

            return null;
        }

        $paths = array_values(array_filter(explode("\0", $tracked), fn (string $path): bool => $path !== ''));
        $trackedCount = count($paths);

        if (! is_file(base_path(BuildStamp::FILENAME))) {
            $this->error('O carimbo não existe — não devia acontecer, foi escrito acima.');

            return null;
        }

        $paths[] = BuildStamp::FILENAME;

        $assets = $this->compiledAssets();

        if ($assets === null) {
            return null;
        }

        $this->line('Lista do pacote: '.$trackedCount.' versionados + 1 carimbo + '.count($assets).' assets compilados');

        return [...$paths, ...$assets];
    }

    /**
     * Everything under public/build. Vite empties the directory on each build, so
     * what is there is this build — but the manifest is checked against the disk
     * anyway, because shipping a manifest that points at files nobody packaged
     * produces a blank page rather than an error.
     *
     * @return list<string>|null
     */
    protected function compiledAssets(): ?array
    {
        $root = base_path('public/build');
        $manifestPath = $root.'/manifest.json';

        if (! is_file($manifestPath)) {
            $this->error('public/build/manifest.json não existe — corre `npm run build` antes de empacotar.');

            return null;
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = 'public/build/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files);

        try {
            /** @var array<string, array<string, mixed>> $manifest */
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('public/build/manifest.json não é JSON válido.');

            return null;
        }

        $referenced = [];

        foreach ($manifest as $entry) {
            if (is_string($entry['file'] ?? null)) {
                $referenced[] = 'public/build/'.$entry['file'];
            }

            foreach ((array) ($entry['css'] ?? []) as $stylesheet) {
                $referenced[] = 'public/build/'.$stylesheet;
            }
        }

        $missing = array_values(array_diff(array_unique($referenced), $files));

        if ($missing !== []) {
            $this->error('O manifest aponta para ficheiros que não estão em public/build:');

            foreach (array_slice($missing, 0, 10) as $path) {
                $this->error("  - {$path}");
            }

            return null;
        }

        $stamp = Date::createFromTimestamp((int) filemtime($manifestPath))->toDateTimeString();
        $this->line("Assets compilados em {$stamp} — confirma que são desta release.");

        return $files;
    }

    /**
     * @param  list<string>  $paths
     */
    protected function archive(array $paths, string $output): bool
    {
        // Relative output path and cwd at the project root, so no argument ever
        // contains a Windows drive letter — GNU tar would read `d:` as a remote
        // host and bsdtar does not accept the --force-local that fixes it.
        $process = new Process(['tar', '-czf', $output, '--null', '-T', '-'], base_path());
        $process->setInput(implode("\0", $paths)."\0");
        $process->setTimeout(600);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            $this->error('O tar falhou:');
            $this->line($process->getErrorOutput());

            return false;
        }

        return true;
    }

    /**
     * Reads the finished archive back. Checking the list we intended to write
     * would only prove the intention; the thing that gets shipped is the file.
     */
    protected function verify(string $output, int $expected): int
    {
        $listing = $this->capture(['tar', '-tzf', $output]);

        if ($listing === null) {
            $this->error('Não foi possível ler o pacote acabado de criar.');

            return self::FAILURE;
        }

        // Strip a leading "./" and nothing else. ltrim() with a character list
        // would eat the dot of every dotfile — ".env" would arrive as "env" and
        // the rule that forbids it would never match anything.
        $entries = array_values(array_filter(
            array_map(fn (string $line): string => (string) preg_replace('#^\./#', '', trim($line)), explode("\n", $listing)),
            fn (string $path): bool => $path !== '' && ! str_ends_with($path, '/'),
        ));

        $problems = [];

        foreach (self::MUST_NOT_CONTAIN as $pattern => $label) {
            $offenders = array_values(array_filter($entries, fn (string $path): bool => preg_match($pattern, $path) === 1));

            if ($offenders !== []) {
                $problems[] = count($offenders)." ficheiro(s) de «{$label}», ex.: {$offenders[0]}";
            }
        }

        foreach (self::MUST_CONTAIN as $required) {
            if (! in_array($required, $entries, true)) {
                $problems[] = "falta «{$required}»";
            }
        }

        if ($problems !== []) {
            $this->newLine();
            $this->error('O pacote não passou a verificação:');

            foreach ($problems as $problem) {
                $this->error("  - {$problem}");
            }

            return self::FAILURE;
        }

        $size = round(((int) filesize(base_path($output))) / 1048576, 2);
        $this->newLine();
        $this->info("Pacote {$output} pronto: ".count($entries)." ficheiros, {$size} MB.");
        $this->line('  esperados na lista: '.$expected);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $arguments
     */
    protected function git(array $arguments): ?string
    {
        return $this->capture(['git', ...$arguments]);
    }

    /**
     * @param  list<string>  $command
     */
    protected function capture(array $command): ?string
    {
        $process = new Process($command, base_path());
        $process->setTimeout(300);

        try {
            $process->mustRun();
        } catch (ProcessFailedException) {
            return null;
        }

        return rtrim($process->getOutput(), "\n");
    }
}
