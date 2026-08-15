<?php

namespace Tests\Feature\Release;

use App\Support\Release\BuildStamp;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The packaging rule, pinned.
 *
 * The old procedure was a denylist and it failed the only way a denylist can:
 * silently, on the files nobody thought to name. Four non-anonymised student
 * exports reached the production server that way. So what these tests defend is
 * not a feature, it is an inversion — nothing enters the package unless git
 * knows about it or it is one of two named generated artefacts.
 *
 * Each test builds a real archive and reads it back, rather than inspecting the
 * list the command intended to write. The intention was never the problem.
 */
class BuildPackageCommandTest extends TestCase
{
    protected string $output = 'storage/framework/testing/package-test.tgz';

    protected function tearDown(): void
    {
        @unlink(base_path($this->output));
        @unlink(base_path(BuildStamp::FILENAME));

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    protected function build(bool $expectSuccess = true): array
    {
        // --allow-dirty because the repository under test is whatever the
        // developer happens to have open; the HEAD guard has its own test.
        $exitCode = $this->artisan("lapis:build-package --allow-dirty --output={$this->output}")->run();

        if (! $expectSuccess) {
            $this->assertNotSame(0, $exitCode);

            return [];
        }

        $this->assertSame(0, $exitCode, 'O empacotamento devia ter corrido.');

        $process = new Process(['tar', '-tzf', $this->output], base_path());
        $process->mustRun();

        // "./" only — ltrim() with a character list would swallow the leading dot
        // of every dotfile and quietly exempt them from every assertion below.
        return array_values(array_filter(
            array_map(fn (string $line): string => (string) preg_replace('#^\./#', '', trim($line)), explode("\n", $process->getOutput())),
            fn (string $path): bool => $path !== '' && ! str_ends_with($path, '/'),
        ));
    }

    #[Test]
    public function tracked_files_are_in_the_package(): void
    {
        $entries = $this->build();

        foreach (['artisan', 'composer.json', 'config/app.php', 'public/index.php', '.env.example'] as $required) {
            $this->assertContains($required, $entries);
        }

        // Not a spot check: every tracked path has to be there, because "the
        // package IS the commit" is the whole claim.
        $process = new Process(['git', 'ls-files', '-z'], base_path());
        $process->mustRun();
        $tracked = array_filter(explode("\0", $process->getOutput()), fn (string $path): bool => $path !== '');

        $this->assertSame([], array_values(array_diff($tracked, $entries)), 'Ficheiros versionados em falta no pacote.');
    }

    #[Test]
    public function the_generated_artefacts_that_production_needs_are_in_the_package(): void
    {
        $entries = $this->build();

        // Both are gitignored on purpose, so both have to be added explicitly —
        // and if either stops being added, production gets a blank page or a
        // deploy nobody can verify.
        $this->assertContains(BuildStamp::FILENAME, $entries);
        $this->assertContains('public/build/manifest.json', $entries);
        $this->assertNotEmpty(array_filter($entries, fn (string $path): bool => str_starts_with($path, 'public/build/assets/')));
    }

    #[Test]
    public function an_untracked_file_never_enters_the_package(): void
    {
        $probe = base_path('__package_probe.txt');
        $probeDirectory = base_path('__package_probe_dir');

        file_put_contents($probe, 'probe');
        @mkdir($probeDirectory);
        file_put_contents($probeDirectory.'/inside.txt', 'probe');

        try {
            $entries = $this->build();

            $offenders = array_values(array_filter($entries, fn (string $path): bool => str_contains($path, '__package_probe')));

            $this->assertSame([], $offenders, 'Um ficheiro untracked entrou no pacote — a allowlist falhou.');
        } finally {
            @unlink($probe);
            @unlink($probeDirectory.'/inside.txt');
            @rmdir($probeDirectory);
        }
    }

    #[Test]
    public function nothing_local_or_private_reaches_the_package(): void
    {
        $entries = $this->build();

        $forbidden = [
            '#^\.env$#',
            '#^\.env\.(?!example$)#',
            '#^storage/app/(?!.*\.gitignore$)#',
            '#^storage/logs/(?!.*\.gitignore$)#',
            '#^\.phpunit\.result\.cache$#',
            '#^skills-lock\.json$#',
            '#^AGENTS\.md\.local-backup-#',
            '#^Ficheiros avulsos/#',
            '#^Grelhas de correção/#',
            '#^(\.git|node_modules|vendor)/#',
            '#^bootstrap/cache/(?!\.gitignore$)#',
        ];

        foreach ($forbidden as $pattern) {
            $this->assertSame(
                [],
                array_values(array_filter($entries, fn (string $path): bool => preg_match($pattern, $path) === 1)),
                "O pacote contém algo que corresponde a {$pattern}.",
            );
        }
    }

    #[Test]
    public function the_stamp_in_the_package_describes_the_commit_being_packaged(): void
    {
        $this->build();

        $stamp = BuildStamp::read();
        $this->assertNotNull($stamp);

        $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $process->mustRun();

        $this->assertSame(trim($process->getOutput()), $stamp->commit);
        $this->assertSame((string) config('app.version'), $stamp->version);
    }

    #[Test]
    public function a_repository_that_does_not_match_head_is_refused(): void
    {
        // Without --allow-dirty, and with a tracked file modified. This is the
        // guard that makes the stamp trustworthy: a package built from a drifted
        // tree would claim a commit it does not contain.
        $tracked = base_path('README.md');
        $original = (string) file_get_contents($tracked);

        try {
            file_put_contents($tracked, $original."\n<!-- package guard test -->\n");

            $this->artisan("lapis:build-package --output={$this->output}")
                ->expectsOutputToContain('não corresponde ao HEAD')
                ->assertFailed();

            $this->assertFileDoesNotExist(base_path($this->output));
        } finally {
            file_put_contents($tracked, $original);
        }
    }

    #[Test]
    public function a_staged_but_uncommitted_change_is_refused_too(): void
    {
        // The subtler half of the same rule. `git ls-files` reads the INDEX, so a
        // file staged and not committed WOULD be packaged — the archive would
        // contain something no commit has. Staging is not committing.
        $probe = base_path('__package_staged_probe.txt');

        try {
            file_put_contents($probe, 'staged');
            (new Process(['git', 'add', '__package_staged_probe.txt'], base_path()))->mustRun();

            $this->artisan("lapis:build-package --output={$this->output}")
                ->expectsOutputToContain('não corresponde ao HEAD')
                ->assertFailed();
        } finally {
            (new Process(['git', 'rm', '-q', '--cached', '__package_staged_probe.txt'], base_path()))->run();
            @unlink($probe);
        }
    }
}
