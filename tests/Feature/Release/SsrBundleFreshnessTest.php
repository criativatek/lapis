<?php

namespace Tests\Feature\Release;

use App\Support\Release\BuildsSsrBundle;
use App\Support\Release\BuildStamp;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `lapis:build-package` used to trust whatever sat in `bootstrap/ssr` — a
 * directory `npm run build:ssr` writes, but which the command itself never
 * ran. "The directory exists" and "the directory matches the current source"
 * were treated as the same fact.
 *
 * They are not the same fact. Three releases in a row (0.105.4, 0.105.5,
 * 0.105.6) shipped `resources/js/pages/reports/Create.vue` and
 * `HomeworkGrid.vue` fixed on the SSR-relative-fetch crash, while production
 * kept running a `bootstrap/ssr/ssr.js` built on 30 August — from before the
 * fix existed — because the documented pre-package step was `npm run build`,
 * the client-only script, which never touches `bootstrap/ssr` at all. The
 * fix was real, committed, and an ancestor of the deployed commit. It never
 * shipped.
 *
 * These tests pin the correction: `BuildPackageCommand` now depends on
 * `BuildsSsrBundle` and calls `build()` before it ever reads `bootstrap/ssr`
 * (see that method's docblock). A double standing in for the real npm
 * invocation lets each scenario below be asserted deterministically, without
 * this suite needing Node to pass.
 */
class SsrBundleFreshnessTest extends TestCase
{
    protected string $output = 'storage/framework/testing/ssr-freshness-test.tgz';

    protected string $ssrRoot;

    protected ?string $ssrBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ssrRoot = base_path('bootstrap/ssr');

        // A developer's real bootstrap/ssr (from a manual `npm run build:ssr`
        // in this checkout) must survive this test class untouched — moved
        // aside here, restored in tearDown(), never deleted.
        if (is_dir($this->ssrRoot)) {
            $this->ssrBackup = base_path('bootstrap/ssr.freshness-test-backup');
            rename($this->ssrRoot, $this->ssrBackup);
        }
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->output));
        @unlink(base_path(BuildStamp::FILENAME));
        $this->removeSsrRoot();

        if ($this->ssrBackup !== null) {
            rename($this->ssrBackup, $this->ssrRoot);
            $this->ssrBackup = null;
        }

        parent::tearDown();
    }

    protected function removeSsrRoot(): void
    {
        if (! is_dir($this->ssrRoot)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->ssrRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->ssrRoot);
    }

    protected function writeStaleBundle(): void
    {
        mkdir($this->ssrRoot.'/assets', recursive: true);
        file_put_contents($this->ssrRoot.'/ssr.js', "// STALE_BUNDLE_FROM_BEFORE_THE_FIX\n");
        file_put_contents($this->ssrRoot.'/assets/Create-STALE.js', "// STALE_BUNDLE_FROM_BEFORE_THE_FIX\n");
    }

    /**
     * Runs the command exactly once, then reads the archive it produced.
     *
     * @return list<string> the tar listing, or [] if the command failed
     */
    protected function build(): array
    {
        $exitCode = $this->artisan("lapis:build-package --allow-dirty --output={$this->output}")->run();

        return $exitCode === 0 ? $this->readArchive() : [];
    }

    /**
     * Reads back an archive already produced by a prior artisan call —
     * never runs the command itself, so a test that already asserted on the
     * command's own output (via expectsOutputToContain()) does not run it a
     * second time just to inspect the result.
     *
     * @return list<string> the tar listing
     */
    protected function readArchive(): array
    {
        $process = new Process(['tar', '-tzf', $this->output], base_path());
        $process->mustRun();

        return array_values(array_filter(
            array_map(fn (string $line): string => (string) preg_replace('#^\./#', '', trim($line)), explode("\n", $process->getOutput())),
            fn (string $path): bool => $path !== '' && ! str_ends_with($path, '/'),
        ));
    }

    /**
     * A) SSR bundle ausente: the rebuild ran and succeeded, but produced no
     * `bootstrap/ssr` at all. That is still a safe, valid package — a public
     * page rendered on the client only, never a broken one.
     */
    #[Test]
    public function a_successful_rebuild_that_produces_nothing_still_packages_safely(): void
    {
        $this->app->instance(BuildsSsrBundle::class, new class implements BuildsSsrBundle
        {
            public function build(): bool
            {
                return true; // succeeds, deliberately writes nothing
            }
        });

        $this->artisan("lapis:build-package --allow-dirty --output={$this->output}")
            ->expectsOutputToContain('Sem bootstrap/ssr')
            ->assertSuccessful();

        $entries = $this->readArchive();
        $this->assertSame([], array_values(array_filter($entries, fn (string $path): bool => str_starts_with($path, 'bootstrap/ssr/'))));
    }

    /**
     * B) SSR bundle stale/incompatível: a bundle from a previous build sits on
     * disk when packaging starts. It must never reach the archive — only what
     * the rebuild just produced can.
     */
    #[Test]
    public function a_stale_bundle_on_disk_before_the_rebuild_never_reaches_the_package(): void
    {
        $this->writeStaleBundle();

        $this->app->instance(BuildsSsrBundle::class, new class implements BuildsSsrBundle
        {
            public function build(): bool
            {
                // What a real `npm run build:ssr` does: replaces the bundle
                // wholesale with output from the current source.
                $root = base_path('bootstrap/ssr');
                foreach (glob($root.'/assets/*') as $stale) {
                    unlink($stale);
                }
                file_put_contents($root.'/ssr.js', "// FRESH_BUNDLE_FROM_CURRENT_SOURCE\n");
                file_put_contents($root.'/assets/Create-FRESH.js', "// FRESH_BUNDLE_FROM_CURRENT_SOURCE\n");

                return true;
            }
        });

        $entries = $this->build();

        $this->assertContains('bootstrap/ssr/ssr.js', $entries);
        $this->assertContains('bootstrap/ssr/assets/Create-FRESH.js', $entries);
        $this->assertNotContains('bootstrap/ssr/assets/Create-STALE.js', $entries);

        $this->assertStringNotContainsString(
            'STALE_BUNDLE_FROM_BEFORE_THE_FIX',
            (string) file_get_contents(base_path('bootstrap/ssr/ssr.js')),
        );
    }

    /**
     * C) build SSR falha: the package must fail outright — not silently keep
     * serving whatever stale bundle was already on disk before the attempt.
     */
    #[Test]
    public function a_failed_rebuild_fails_the_package_instead_of_reusing_the_old_bundle(): void
    {
        $this->writeStaleBundle();

        $this->app->instance(BuildsSsrBundle::class, new class implements BuildsSsrBundle
        {
            public function build(): bool
            {
                return false; // `npm run build:ssr` exited non-zero
            }
        });

        $this->artisan("lapis:build-package --allow-dirty --output={$this->output}")
            ->expectsOutputToContain('A reconstrução do bundle SSR falhou')
            ->assertFailed();

        $this->assertFileDoesNotExist(base_path($this->output));

        // The stale file is still on disk (nobody touched it) — the point is
        // that it never became part of a package that claims to be current.
        $this->assertFileExists($this->ssrRoot.'/ssr.js');
    }

    /**
     * D) + E) bundle correto: a successful rebuild with real-looking output
     * both passes the package and puts that exact output in the archive.
     */
    #[Test]
    public function a_successful_rebuild_packages_the_bundle_it_just_produced(): void
    {
        $this->app->instance(BuildsSsrBundle::class, new class implements BuildsSsrBundle
        {
            public function build(): bool
            {
                $root = base_path('bootstrap/ssr');
                mkdir($root.'/assets', recursive: true);
                file_put_contents($root.'/ssr.js', "// FRESH_BUNDLE_FROM_CURRENT_SOURCE\n");
                file_put_contents($root.'/assets/Create-FRESH.js', "// onMounted, no immediate watch\n");

                return true;
            }
        });

        $entries = $this->build();
        $this->assertContains('bootstrap/ssr/ssr.js', $entries);
        $this->assertContains('bootstrap/ssr/assets/Create-FRESH.js', $entries);
    }
}
