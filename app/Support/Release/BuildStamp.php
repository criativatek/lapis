<?php

namespace App\Support\Release;

use JsonException;

/**
 * What is actually running here.
 *
 * The version has never been the hard part — it lives in config/app.php and
 * travels with the code. The commit is: production has no `.git`, so nothing on
 * the server can say which commit produced the files it is serving. Confirming a
 * deploy therefore depended on remembering what was sent, and remembering is
 * exactly what failed with 0.29.1 — a version bumped in git, never deployed, and
 * nothing anywhere to notice the difference.
 *
 * So the commit is recorded at the one moment it is knowable: when the package is
 * built, on the machine that has the repository. The stamp travels inside the
 * package like any other file, and the server reads it back rather than deducing
 * anything.
 *
 * The file is deliberately NOT committed. A stamp in git would describe the
 * commit before itself and be wrong from the moment it was written.
 */
class BuildStamp
{
    /**
     * Kept out of `public/`: the document root is web-served, and a build
     * identifier is not something to publish (§4).
     */
    public const FILENAME = 'build.json';

    public function __construct(
        public readonly string $version,
        public readonly string $commit,
        public readonly string $builtAt,
    ) {}

    public static function path(): string
    {
        return base_path(self::FILENAME);
    }

    /**
     * The stamp this installation is running under, or null when there is none —
     * which is the normal state in development, where the repository itself
     * answers the question.
     */
    public static function read(?string $path = null): ?self
    {
        $path ??= self::path();

        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        foreach (['version', 'commit', 'built_at'] as $key) {
            if (! is_string($decoded[$key] ?? null) || $decoded[$key] === '') {
                return null;
            }
        }

        return new self(
            version: (string) $decoded['version'],
            commit: (string) $decoded['commit'],
            builtAt: (string) $decoded['built_at'],
        );
    }

    public function write(?string $path = null): void
    {
        file_put_contents(
            $path ?? self::path(),
            json_encode([
                'version' => $this->version,
                'commit' => $this->commit,
                'built_at' => $this->builtAt,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL,
        );
    }

    /**
     * The first seven characters, as git and the deploy notes write it.
     */
    public function shortCommit(): string
    {
        return substr($this->commit, 0, 7);
    }
}
