<?php

namespace Tests\Feature\Release;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two halves of a release have to describe the same thing.
 *
 * `config/app.php` is what the application calls itself; `CHANGELOG.md` is what
 * the release says it contains. Nothing connected them, so either could move
 * without the other: a version bumped with nothing written down, or a changelog
 * entry for a version the application does not claim to be. Both have happened —
 * the instrument groups work reached a package with no entry at all.
 *
 * This does NOT assert that the version is deployed. What is in git and what is
 * in production are different questions, and the second one is answered at
 * deploy time by `lapis:release-check`.
 */
class ReleaseVersionTest extends TestCase
{
    protected function changelog(): string
    {
        return (string) file_get_contents(base_path('CHANGELOG.md'));
    }

    /**
     * @return list<string>
     */
    protected function changelogVersions(): array
    {
        preg_match_all('/^## \[([^\]]+)\]/m', $this->changelog(), $matches);

        return $matches[1];
    }

    #[Test]
    public function the_application_declares_a_semantic_version(): void
    {
        $version = config('app.version');

        $this->assertIsString($version);
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $version,
            'config/app.php tem de declarar uma versão semântica (x.y.z).',
        );
    }

    #[Test]
    public function the_declared_version_has_a_changelog_entry(): void
    {
        $version = (string) config('app.version');

        $this->assertContains(
            $version,
            $this->changelogVersions(),
            "A versão {$version} não tem entrada no CHANGELOG.md. Um bump de versão sem changelog chega a produção por documentar.",
        );
    }

    #[Test]
    public function the_declared_version_is_the_most_recent_changelog_entry(): void
    {
        $versions = $this->changelogVersions();

        $this->assertNotEmpty($versions, 'O CHANGELOG.md não tem nenhuma entrada `## [versão]`.');

        // The other direction of the same rule: an entry written above the
        // version the application claims means the changelog was updated and the
        // bump forgotten.
        $this->assertSame(
            (string) config('app.version'),
            $versions[0],
            'A entrada no topo do CHANGELOG.md tem de ser a versão declarada em config/app.php.',
        );
    }

    #[Test]
    public function every_changelog_entry_is_a_semantic_version_with_a_date(): void
    {
        preg_match_all('/^## \[([^\]]+)\][^\n]*$/m', $this->changelog(), $matches);

        foreach ($matches[0] as $heading) {
            $this->assertMatchesRegularExpression(
                '/^## \[\d+\.\d+\.\d+\] — \d{4}-\d{2}-\d{2}$/u',
                $heading,
                "Cabeçalho de release mal formado: «{$heading}».",
            );
        }
    }

    #[Test]
    public function changelog_versions_are_listed_newest_first_and_never_repeat(): void
    {
        $versions = $this->changelogVersions();

        $this->assertSame(
            array_values(array_unique($versions)),
            $versions,
            'Há uma versão repetida no CHANGELOG.md.',
        );

        $sorted = $versions;
        usort($sorted, fn (string $a, string $b): int => version_compare($b, $a));

        $this->assertSame($sorted, $versions, 'As entradas do CHANGELOG.md têm de estar da mais recente para a mais antiga.');
    }
}
